<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Unit\Service\Availability;

use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemSearchResultsInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickupInventory\Service\Availability\MsiSalableChecker;

class MsiSalableCheckerTest extends TestCase
{
    private const STORE_ID = 3;
    private const STOCK_ID = 7;
    private const SOURCE = 'vienna_1';

    /** @var SourceItemRepositoryInterface&MockObject */
    private $repository;

    /** @var GetStockItemConfigurationInterface&MockObject */
    private $stockConfiguration;

    /** @var list<array{string, mixed, string}> */
    private array $filters = [];

    /** @var array<string, bool> sku => manage stock */
    private array $manageStock = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SourceItemRepositoryInterface::class);
        $this->stockConfiguration = $this->createMock(GetStockItemConfigurationInterface::class);
        $this->stockConfiguration->method('execute')->willReturnCallback(
            function (string $sku, int $stockId): StockItemConfigurationInterface {
                self::assertSame(self::STOCK_ID, $stockId);
                $configuration = $this->createMock(StockItemConfigurationInterface::class);
                $configuration->method('isManageStock')->willReturn($this->manageStock[$sku] ?? true);

                return $configuration;
            }
        );
    }

    private function checker(): MsiSalableChecker
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(self::STORE_ID)->willReturn($store);
        $storeManager->method('getWebsite')->with(1)->willReturn($website);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(self::STOCK_ID);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->with('website', 'base')->willReturn($stock);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            function (string $field, $value, string $condition = 'eq') use ($builder): SearchCriteriaBuilder {
                $this->filters[] = [$field, $value, $condition];

                return $builder;
            }
        );
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new MsiSalableChecker($storeManager, $stockResolver, $this->stockConfiguration, $this->repository, $builder);
    }

    private function location(?string $binding): PickupLocationInterface
    {
        $location = $this->createMock(PickupLocationInterface::class);
        $location->method('getStockBinding')->willReturn($binding);

        return $location;
    }

    private function item(string $sku, float $qty, bool $virtual = false, ?Item $parent = null, array $children = []): Item
    {
        $product = $this->createMock(Product::class);
        $product->method('isVirtual')->willReturn($virtual);
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getProduct')->willReturn($product);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getChildren')->willReturn($children);

        return $item;
    }

    private function request(Item ...$items): RateRequest
    {
        $request = $this->getMockBuilder(RateRequest::class)
            ->disableOriginalConstructor()
            ->addMethods(['getAllItems', 'getStoreId'])
            ->getMock();
        $request->method('getAllItems')->willReturn($items);
        $request->method('getStoreId')->willReturn(self::STORE_ID);

        return $request;
    }

    private function sourceItem(string $sku, float $qty, int $status = SourceItemInterface::STATUS_IN_STOCK): SourceItemInterface
    {
        $sourceItem = $this->createMock(SourceItemInterface::class);
        $sourceItem->method('getSku')->willReturn($sku);
        $sourceItem->method('getQuantity')->willReturn($qty);
        $sourceItem->method('getStatus')->willReturn($status);

        return $sourceItem;
    }

    /**
     * @param list<SourceItemInterface> $items
     */
    private function sourceHas(array $items): void
    {
        $result = $this->createMock(SourceItemSearchResultsInterface::class);
        $result->method('getItems')->willReturn($items);
        $this->repository->method('getList')->willReturn($result);
    }

    public function testUnboundLocationIsAlwaysAvailable(): void
    {
        $this->repository->expects(self::never())->method('getList');

        self::assertTrue($this->checker()->isAvailable($this->location(null), $this->request($this->item('a', 5))));
    }

    public function testAvailableWhenEverySkuIsInStockWithEnoughQty(): void
    {
        $this->sourceHas([$this->sourceItem('a', 5), $this->sourceItem('b', 2)]);

        self::assertTrue($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 5), $this->item('b', 1))
        ));
    }

    public function testNotAvailableWhenTheCartWantsMoreThanTheSourceHolds(): void
    {
        $this->sourceHas([$this->sourceItem('a', 1)]);

        self::assertFalse($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($this->item('a', 2))));
    }

    public function testNotAvailableWhenTheSourceItemIsOutOfStock(): void
    {
        $this->sourceHas([$this->sourceItem('a', 9, SourceItemInterface::STATUS_OUT_OF_STOCK)]);

        self::assertFalse($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($this->item('a', 1))));
    }

    public function testNotAvailableWhenTheSourceHasNoItemForTheSku(): void
    {
        $this->sourceHas([$this->sourceItem('a', 5)]);

        self::assertFalse($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 1), $this->item('b', 1))
        ));
    }

    public function testSkuMatchingIgnoresCase(): void
    {
        $this->sourceHas([$this->sourceItem('Sku-A', 5)]);

        self::assertTrue($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($this->item('SKU-a', 1))));
    }

    public function testConfigurableParentIsSkippedAndChildQtyIsMultipliedByParentQty(): void
    {
        $child = null;
        $parent = $this->item('conf', 3, false, null, [&$child]);
        $child = $this->item('conf-red', 1, false, $parent);
        $this->sourceHas([$this->sourceItem('conf-red', 2)]);

        // 1 x 3 = 3 wanted, 2 held; the parent SKU has no source item and must not matter on its own
        self::assertFalse($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($parent, $child)));
        self::assertSame(['conf-red'], $this->filters[0][1]);
    }

    public function testConfigurableChildIsAvailableWithEnoughQty(): void
    {
        $child = null;
        $parent = $this->item('conf', 3, false, null, [&$child]);
        $child = $this->item('conf-red', 1, false, $parent);
        $this->sourceHas([$this->sourceItem('conf-red', 3)]);

        self::assertTrue($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($parent, $child)));
    }

    public function testVirtualItemsAreIgnored(): void
    {
        $this->sourceHas([$this->sourceItem('a', 1)]);

        self::assertTrue($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 1), $this->item('download', 1, true))
        ));
        self::assertSame(['a'], $this->filters[0][1]);
    }

    public function testSkuWithoutStockManagementNeedsNoSourceItem(): void
    {
        $this->manageStock = ['free' => false];
        $this->sourceHas([$this->sourceItem('a', 1)]);

        self::assertTrue($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 1), $this->item('free', 4))
        ));
        self::assertSame(['a'], $this->filters[0][1]);
    }

    public function testOnlyUnmanagedSkusNeedNoLookupAtAll(): void
    {
        $this->manageStock = ['free' => false];
        $this->repository->expects(self::never())->method('getList');

        self::assertTrue($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($this->item('free', 1))));
    }

    public function testSkuNotAssignedToTheStockIsLookedUpAtTheSource(): void
    {
        $this->stockConfiguration = $this->createMock(GetStockItemConfigurationInterface::class);
        $this->stockConfiguration->method('execute')->willThrowException(new LocalizedException(__('not assigned')));
        $this->sourceHas([]);

        self::assertFalse($this->checker()->isAvailable($this->location(self::SOURCE), $this->request($this->item('a', 1))));
    }

    public function testCartWithoutShippableItemsIsAvailable(): void
    {
        $this->repository->expects(self::never())->method('getList');

        self::assertTrue($this->checker()->isAvailable($this->location(self::SOURCE), $this->request()));
    }

    public function testThreeSkusAreLoadedInOneRepositoryCall(): void
    {
        $this->repository->expects(self::once())->method('getList')->willReturn(
            $this->createConfiguredMock(SourceItemSearchResultsInterface::class, [
                'getItems' => [$this->sourceItem('a', 1), $this->sourceItem('b', 1), $this->sourceItem('c', 1)],
            ])
        );

        self::assertTrue($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 1), $this->item('b', 1), $this->item('c', 1))
        ));
        self::assertSame(['sku', ['a', 'b', 'c'], 'in'], $this->filters[0]);
        self::assertSame(['source_code', self::SOURCE, 'eq'], $this->filters[1]);
    }

    public function testSameSkuInTwoLinesIsSummed(): void
    {
        $this->sourceHas([$this->sourceItem('a', 3)]);

        self::assertFalse($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('a', 2), $this->item('a', 2))
        ));
    }

    public function testItemWithoutSkuIsIgnoredAndLaterItemsStillCount(): void
    {
        $this->sourceHas([$this->sourceItem('a', 1)]);

        self::assertFalse($this->checker()->isAvailable(
            $this->location(self::SOURCE),
            $this->request($this->item('', 1), $this->item('a', 2))
        ));
        self::assertSame(['a'], $this->filters[0][1]);
    }
}
