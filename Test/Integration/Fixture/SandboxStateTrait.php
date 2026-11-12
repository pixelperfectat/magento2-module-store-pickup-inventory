<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickupInventory\Service\LocationProvider\MsiSourceLocationProvider;

/**
 * Keeps the shared integration sandbox as the tests found it.
 *
 * The stock fixtures re-link the main website to a custom stock and their rollback does not restore the
 * previous link; the product fixtures need the secure-area flag to be deleted in the frontend area.
 * Call rememberSandboxState() from setUpBeforeClass() (before the method-level fixtures run) and
 * restoreSandboxState() from tearDownAfterClass(); call allowFixtureDeletion() from tearDown().
 */
trait SandboxStateTrait
{
    /** @var list<array<string, mixed>>|null stock-to-sales-channel rows before the test's fixtures ran */
    private static ?array $channelRows = null;

    /** @var bool|null the isSecureArea value before the test, when it was registered */
    private static ?bool $secureArea = null;

    private static bool $secureAreaWasSet = false;

    /**
     * Records the stock-to-sales-channel rows and the secure-area flag.
     */
    private static function rememberSandboxState(): void
    {
        self::$channelRows = self::channelRows();
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        self::$secureAreaWasSet = $registry->registry('isSecureArea') !== null;
        self::$secureArea = self::$secureAreaWasSet ? (bool) $registry->registry('isSecureArea') : null;
    }

    /**
     * Puts the stock-to-website links back if the fixtures' rollback lost them, and the secure-area flag as it was.
     */
    private static function restoreSandboxState(): void
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

    /**
     * The fixtures roll back after tearDown and delete products, which the frontend area forbids without this.
     */
    private function allowFixtureDeletion(): void
    {
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
    }

    /**
     * The providers cache per store for the length of a request; every test is a request of its own.
     */
    private function resetMsiLocationCaches(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        foreach ([CompositeLocationProvider::class, MsiSourceLocationProvider::class] as $class) {
            $provider = $objectManager->get($class);
            (function (): void {
                $this->cache = [];
            })->call($provider);
        }
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
}
