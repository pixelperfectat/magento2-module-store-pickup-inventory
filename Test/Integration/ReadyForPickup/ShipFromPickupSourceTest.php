<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Integration\ReadyForPickup;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Test\Fixture\SourceItems as SourceItemsFixture;
use Magento\InventoryApi\Test\Fixture\Stock as StockFixture;
use Magento\InventoryApi\Test\Fixture\StockSourceLinks as StockSourceLinksFixture;
use Magento\InventoryInStorePickupApi\Test\Fixture\Source as PickupSourceFixture;
use Magento\InventorySalesApi\Test\Fixture\StockSalesChannels as StockSalesChannelsFixture;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Service\ReadyForPickupServiceInterface;
use PixelPerfect\StorePickup\Test\Integration\Fixture\PickupOrderTrait;
use PixelPerfect\StorePickupInventory\Test\Integration\Fixture\SandboxStateTrait;

// the lab's symlinked package has no dev autoload
require_once __DIR__ . '/../Fixture/SandboxStateTrait.php';
(static function (): void {
    $relative = '/Test/Integration/Fixture/PickupOrderTrait.php';
    $tried = [];
    if (class_exists(\Composer\InstalledVersions::class)
        && \Composer\InstalledVersions::isInstalled('pixelperfectat/magento2-module-store-pickup')
    ) {
        $installPath = \Composer\InstalledVersions::getInstallPath('pixelperfectat/magento2-module-store-pickup');
        $tried[] = (string) $installPath . $relative;
    }
    $tried[] = __DIR__ . '/../../../../magento2-module-store-pickup' . $relative;
    foreach ($tried as $path) {
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }

    throw new \RuntimeException(
        'The core package test trait PickupOrderTrait was not found; tried: ' . implode(', ', $tried)
    );
})();

/**
 * A ready-for-pickup order ships from the pickup source it was placed for, and that source is deducted.
 *
 * Both pickup sources and the default source hold the product, so a shipment that fell back to another source
 * would deduct the wrong one.
 */
#[DbIsolation(false)]
#[AppArea('frontend')]
class ShipFromPickupSourceTest extends TestCase
{
    use PickupOrderTrait;
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
        $this->resetLocationCaches();
        $this->resetMsiLocationCaches();
    }

    protected function tearDown(): void
    {
        $this->cleanUpOrders();
        $this->resetLocationCaches();
        $this->resetMsiLocationCaches();
        $this->allowFixtureDeletion();
    }

    /**
     * @return array<string, float> source code => quantity of the product there
     */
    private function quantities(string $sku): array
    {
        $quantities = [];
        foreach (Bootstrap::getObjectManager()->get(GetSourceItemsBySkuInterface::class)->execute($sku) as $item) {
            $quantities[$item->getSourceCode()] = (float) $item->getQuantity();
        }
        ksort($quantities);

        return $quantities;
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
        [['stock_id' => '$stock.stock_id$', 'source_code' => '$graz.source_code$'],
            ['stock_id' => '$stock.stock_id$', 'source_code' => '$vienna.source_code$']]
    )]
    #[DataFixture(
        StockSalesChannelsFixture::class,
        ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['base']]
    )]
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(
        SourceItemsFixture::class,
        [['sku' => '$product.sku$', 'source_code' => '$graz.source_code$', 'quantity' => 8,
            'status' => SourceItemInterface::STATUS_IN_STOCK],
            ['sku' => '$product.sku$', 'source_code' => '$vienna.source_code$', 'quantity' => 5,
                'status' => SourceItemInterface::STATUS_IN_STOCK]]
    )]
    #[DataFixture(GuestCart::class, as: 'cart')]
    #[DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2])]
    #[DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'jane.doe@example.com'])]
    #[DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$', 'address' => self::ADDRESS])]
    #[DataFixture(
        SetDeliveryMethod::class,
        ['cart_id' => '$cart.id$', 'carrier_code' => 'pppickup', 'method_code' => 'vienna_1']
    )]
    #[DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo'])]
    public function testShipmentIsDeductedFromThePickupSource(): void
    {
        $sku = (string) $this->fixture('product')->getSku();
        $orderId = $this->placeOrder();
        self::assertSame('pppickup_vienna_1', $this->freshOrder($orderId)->getShippingMethod());
        $before = $this->quantities($sku);
        self::assertSame(8.0, $before['graz_1']);
        self::assertSame(5.0, $before['vienna_1']);

        Bootstrap::getObjectManager()->get(ReadyForPickupServiceInterface::class)->execute($orderId);

        $shipments = Bootstrap::getObjectManager()->get(ShipmentCollectionFactory::class)->create()
            ->setOrderFilter($orderId);
        self::assertSame(1, $shipments->getSize());
        $shipment = Bootstrap::getObjectManager()->get(ShipmentRepositoryInterface::class)
            ->get((int) $shipments->getFirstItem()->getId());
        self::assertSame('vienna_1', $shipment->getExtensionAttributes()->getSourceCode());
        // the other sources, the default source included, are untouched
        $expected = $before;
        $expected['vienna_1'] = 3.0;
        self::assertSame($expected, $this->quantities($sku));
    }
}
