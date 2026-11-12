<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Integration\Availability;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
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
use PixelPerfect\StorePickupInventory\Test\Integration\Fixture\SandboxStateTrait;

require_once __DIR__ . '/../Fixture/SandboxStateTrait.php';

/**
 * A pickup source is offered only when the whole cart is salable there.
 *
 * Two pickup-active sources feed a custom stock that serves the main website; the product is in stock at Vienna only.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class MsiSalableCheckerTest extends TestCase
{
    use SandboxStateTrait;

    /**
     * Runs before the method-level fixtures are applied, so it sees the untouched stock-to-website links.
     */
    public static function setUpBeforeClass(): void
    {
        self::rememberSandboxState();
    }

    /**
     * Runs after the fixtures are rolled back.
     */
    public static function tearDownAfterClass(): void
    {
        self::restoreSandboxState();
    }

    protected function setUp(): void
    {
        $this->resetMsiLocationCaches();
    }

    protected function tearDown(): void
    {
        $this->allowFixtureDeletion();
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
