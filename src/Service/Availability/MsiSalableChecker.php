<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Service\Availability;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Store\Model\StoreManagerInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\AvailabilityCheckerInterface;

/**
 * Offers a location bound to an inventory source only when every shippable cart item is in stock there.
 *
 * Locations without a stock binding (the config locations) are always offered.
 *
 * The check compares the raw source quantity and status only. Reservations, min_qty, backorders and the
 * stock-level salable flag are not considered (plan and proposal M6); the checkout's own stock validation still
 * guards order placement.
 */
class MsiSalableChecker implements AvailabilityCheckerInterface
{
    /**
     * Constructor.
     *
     * @param StoreManagerInterface $storeManager
     * @param StockResolverInterface $stockResolver
     * @param GetStockItemConfigurationInterface $stockConfiguration
     * @param SourceItemRepositoryInterface $sourceItemRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly GetStockItemConfigurationInterface $stockConfiguration,
        private readonly SourceItemRepositoryInterface $sourceItemRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(PickupLocationInterface $location, RateRequest $request): bool
    {
        $sourceCode = $location->getStockBinding();
        if ($sourceCode === null) {
            return true;
        }

        $wanted = $this->managedQuantities($this->quantities($request), (int) $request->getStoreId());
        if ($wanted === []) {
            return true;
        }

        $held = $this->heldAtSource($sourceCode, array_keys($wanted));
        foreach ($wanted as $sku => $qty) {
            if (!isset($held[$sku]) || $held[$sku] < $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wanted quantity per SKU for the shippable simple items, SKUs lower-cased (SKU matching is case-insensitive).
     *
     * @param RateRequest $request
     * @return array<string, float>
     */
    private function quantities(RateRequest $request): array
    {
        $quantities = [];
        /** @var AbstractItem $item */
        foreach ($request->getAllItems() as $item) {
            if ($item->getProduct()->isVirtual() || $item->getChildren()) {
                continue;
            }
            $sku = strtolower((string) $item->getSku());
            if ($sku === '') {
                continue;
            }
            /** @var AbstractItem|null $parent the PHPDoc of getParentItem() leaves out the null case */
            $parent = $item->getParentItem();
            $qty = (float) $item->getQty() * ($parent === null ? 1.0 : (float) $parent->getQty());
            $quantities[$sku] = ($quantities[$sku] ?? 0.0) + $qty;
        }

        return $quantities;
    }

    /**
     * Drop the SKUs whose stock is not managed; they are available everywhere.
     *
     * @param array<string, float> $quantities
     * @param int $storeId
     * @return array<string, float>
     */
    private function managedQuantities(array $quantities, int $storeId): array
    {
        if ($quantities === []) {
            return [];
        }

        $stockId = $this->stockId($storeId);
        foreach (array_keys($quantities) as $sku) {
            try {
                $managed = $this->stockConfiguration->execute((string) $sku, $stockId)->isManageStock();
            } catch (LocalizedException) {
                // a SKU unknown to the stock has no configuration to relax the rule
                $managed = true;
            }
            if (!$managed) {
                unset($quantities[$sku]);
            }
        }

        return $quantities;
    }

    /**
     * Stock serving the store's website.
     *
     * @param int $storeId
     * @return int
     */
    private function stockId(int $storeId): int
    {
        $store = $this->storeManager->getStore($storeId);
        $websiteCode = $this->storeManager->getWebsite($store->getWebsiteId())->getCode();

        return (int) $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
    }

    /**
     * Quantity per lower-cased SKU of the in-stock source items at the source, loaded in one call.
     *
     * @param string $sourceCode
     * @param list<string> $skus
     * @return array<string, float>
     */
    private function heldAtSource(string $sourceCode, array $skus): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SKU, $skus, 'in')
            ->addFilter(SourceItemInterface::SOURCE_CODE, $sourceCode)
            ->create();

        $held = [];
        foreach ($this->sourceItemRepository->getList($criteria)->getItems() as $sourceItem) {
            if ((int) $sourceItem->getStatus() === SourceItemInterface::STATUS_IN_STOCK) {
                $held[strtolower((string) $sourceItem->getSku())] = (float) $sourceItem->getQuantity();
            }
        }

        return $held;
    }
}
