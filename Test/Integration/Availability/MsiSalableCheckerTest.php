<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Integration\Availability;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Test\Fixture\SourceItems as SourceItemsFixture;
use Magento\InventoryApi\Test\Fixture\Stock as StockFixture;
use Magento\InventoryApi\Test\Fixture\StockSourceLinks as StockSourceLinksFixture;
use Magento\InventoryInStorePickupApi\Test\Fixture\Source as PickupSourceFixture;
use Magento\InventorySalesApi\Test\Fixture\StockSalesChannels as StockSalesChannelsFixture;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShipmentEstimationInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickupInventory\Service\LocationProvider\MsiSourceLocationProvider;

/**
 * A pickup source is offered only when the whole cart is salable there.
 *
 * Two pickup-active sources feed a custom stock that serves the main website; the product is in stock at Vienna only.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class MsiSalableCheckerTest extends TestCase
{
    /** @var list<array<string, mixed>>|null stock-to-sales-channel rows before the test's fixtures ran */
    private static ?array $channelRows = null;

    /** @var bool|null the isSecureArea value before the test, when it was registered */
    private static ?bool $secureArea = null;

    private static bool $secureAreaWasSet = false;

    /**
     * Runs before the method-level fixtures are applied, so it sees the untouched stock-to-website links.
     */
    public static function setUpBeforeClass(): void
    {
        self::$channelRows = self::channelRows();
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        self::$secureAreaWasSet = $registry->registry('isSecureArea') !== null;
        self::$secureArea = self::$secureAreaWasSet ? (bool) $registry->registry('isSecureArea') : null;
    }

    /**
     * Runs after the fixtures are rolled back: put the stock-to-website links back only if they were lost, and
     * return the secure-area flag to what it was.
     */
    public static function tearDownAfterClass(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        if (self::$channelRows !== null && self::channelRows() !== self::$channelRows) {
            $table = $connection->getTableName('inventory_stock_sales_channel');
            $connection->delete($table);
            if (self::$channelRows !== []) {
                $connection->insertMultiple($table, self::$channelRows);
            }
        }
        self::$channelRows = null;

        $registry = $objectManager->get(Registry::class);
        $registry->unregister('isSecureArea');
        if (self::$secureAreaWasSet) {
            $registry->register('isSecureArea', self::$secureArea);
        }
    }

    protected function setUp(): void
    {
        // the providers cache per store for the length of a request; every test is a request of its own
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->removeSharedInstance(CompositeLocationProvider::class);
        $objectManager->removeSharedInstance(MsiSourceLocationProvider::class);
    }

    protected function tearDown(): void
    {
        // the fixtures roll back after tearDown and delete products, which the frontend area forbids without this
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
    }

    /**
     * @return list<array<string, mixed>> all stock-to-sales-channel rows, in a stable order
     */
    private static function channelRows(): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('inventory_stock_sales_channel'))
            ->order(['type', 'code', 'stock_id']);

        return array_values($connection->fetchAll($select));
    }

    /**
     * @return list<string> the method codes of the pickup rates
     */
    private function pickupMethods(): array
    {
        $objectManager = Bootstrap::getObjectManager();
        $cart = $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('cart');
        self::assertInstanceOf(Quote::class, $cart);

        $address = $objectManager->get(AddressInterfaceFactory::class)->create();
        self::assertInstanceOf(AddressInterface::class, $address);
        $address->setCountryId('AT')->setPostcode('1010')->setCity('Vienna')->setStreet(['1 Example Street']);

        $rates = $objectManager->get(ShipmentEstimationInterface::class)
            ->estimateByExtendedAddress((int) $cart->getId(), $address);

        return array_values(array_map(
            static fn (ShippingMethodInterface $rate): string => $rate->getMethodCode(),
            array_filter(
                $rates,
                static fn (ShippingMethodInterface $rate): bool => $rate->getCarrierCode() === 'pppickup'
            )
        ));
    }

    #[Config('carriers/pppickup/active', '1', 'store', 'default')]
    #[DataFixture(
        PickupSourceFixture::class,
        ['source_code' => 'vienna_1', 'name' => 'Vienna', 'contact_name' => 'Jane Doe', 'email' => null,
            'street' => '1 Example Street', 'city' => 'Vienna', 'country_id' => 'AT', 'region_id' => null,
            'region' => null, 'postcode' => '1010'],
        as: 'vienna'
    )]
    #[DataFixture(
        PickupSourceFixture::class,
        ['source_code' => 'graz_1', 'name' => 'Graz', 'contact_name' => 'John Smith', 'email' => null,
            'street' => '2 Example Street', 'city' => 'Graz', 'country_id' => 'AT', 'region_id' => null,
            'region' => null, 'postcode' => '8010'],
        as: 'graz'
    )]
    #[DataFixture(StockFixture::class, as: 'stock')]
    #[DataFixture(
        StockSourceLinksFixture::class,
        [['stock_id' => '$stock.stock_id$', 'source_code' => '$vienna.source_code$'],
            ['stock_id' => '$stock.stock_id$', 'source_code' => '$graz.source_code$']]
    )]
    #[DataFixture(
        StockSalesChannelsFixture::class,
        ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['base']]
    )]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(
        SourceItemsFixture::class,
        [['sku' => '$product.sku$', 'source_code' => '$vienna.source_code$', 'quantity' => 5,
            'status' => SourceItemInterface::STATUS_IN_STOCK]]
    )]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2])]
    public function testOnlyTheSourceHoldingTheCartIsOffered(): void
    {
        self::assertSame(['vienna_1'], $this->pickupMethods());
    }
}
