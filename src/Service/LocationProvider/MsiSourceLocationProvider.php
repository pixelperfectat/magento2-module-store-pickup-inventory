<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Service\LocationProvider;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\LocationProviderInterface;
use PixelPerfect\StorePickup\Model\Data\PickupLocationFactory;
use PixelPerfect\StorePickupInventory\Api\Service\SourceCodeMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Inventory sources of the store's website stock as pickup locations.
 *
 * Store-view texts are not read here; they stay a feature of the config provider.
 */
class MsiSourceLocationProvider implements LocationProviderInterface
{
    /** Distance between two stock priority positions, leaving room for locations of other providers. */
    private const SORT_ORDER_STEP = 10;

    /** @var array<int, array<string, PickupLocationInterface>> */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param StoreManagerInterface $storeManager
     * @param StockResolverInterface $stockResolver
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $getSources
     * @param SourceCodeMapperInterface $codeMapper
     * @param PickupLocationFactory $locationFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly GetSourcesAssignedToStockOrderedByPriorityInterface $getSources,
        private readonly SourceCodeMapperInterface $codeMapper,
        private readonly PickupLocationFactory $locationFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLocations(int $storeId): array
    {
        return array_filter(
            $this->load($storeId),
            static fn (PickupLocationInterface $location): bool => $location->isActive()
        );
    }

    /**
     * @inheritDoc
     */
    public function getLocation(string $code, int $storeId): ?PickupLocationInterface
    {
        foreach ($this->load($storeId) as $location) {
            if ($location->getCode() === $code) {
                return $location;
            }
        }

        return null;
    }

    /**
     * All assigned sources as locations (active and inactive), keyed by code, in stock priority order.
     *
     * @param int $storeId
     * @return array<string, PickupLocationInterface>
     */
    private function load(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }

        $locations = [];
        foreach ($this->loadSources($storeId) as $position => $source) {
            $location = $this->createLocation($source, $position * self::SORT_ORDER_STEP);
            // The key is only a convenience; PHP turns a numeric code into an int key.
            $locations[$location->getCode()] = $location;
        }

        return $this->cache[$storeId] = $locations;
    }

    /**
     * Sources assigned to the stock of the store's website, ordered by priority; none when no stock is linked.
     *
     * @param int $storeId
     * @return list<SourceInterface>
     */
    private function loadSources(int $storeId): array
    {
        $websiteCode = '';
        try {
            $store = $this->storeManager->getStore($storeId);
            $websiteCode = $this->storeManager->getWebsite($store->getWebsiteId())->getCode();
            $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf(
                'No inventory stock is linked to website "%s"; no pickup locations from inventory sources.',
                $websiteCode
            ));

            return [];
        }

        return array_values($this->getSources->execute((int) $stock->getStockId()));
    }

    /**
     * Build a location from a source.
     *
     * @param SourceInterface $source
     * @param int $sortOrder
     * @return PickupLocationInterface
     */
    private function createLocation(SourceInterface $source, int $sortOrder): PickupLocationInterface
    {
        $sourceCode = (string) $source->getSourceCode();
        $extension = $source->getExtensionAttributes();
        $regionId = (int) $source->getRegionId();

        return $this->locationFactory->create([
            'code' => $this->codeMapper->toLocationCode($sourceCode),
            'name' => $this->nullIfEmpty($extension?->getFrontendName()) ?? (string) $source->getName(),
            'street' => $this->splitLines((string) $source->getStreet()),
            'postcode' => (string) $source->getPostcode(),
            'city' => (string) $source->getCity(),
            'countryId' => (string) $source->getCountryId(),
            'regionId' => $regionId > 0 ? $regionId : null,
            'region' => $this->nullIfEmpty($source->getRegion()),
            'telephone' => $this->nullIfEmpty($source->getPhone()),
            'openingHours' => null,
            'note' => $this->nullIfEmpty($extension?->getFrontendDescription()),
            'price' => null,
            'sortOrder' => $sortOrder,
            'active' => (bool) $source->isEnabled() && (bool) $extension?->getIsPickupLocationActive(),
            'stockBinding' => $sourceCode,
        ]);
    }

    /**
     * Trimmed value, or null when it is null or empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullIfEmpty(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * Split a multi-line value into trimmed, non-empty lines.
     *
     * @param string $value
     * @return list<string>
     */
    private function splitLines(string $value): array
    {
        $lines = array_map('trim', preg_split('/\R/', $value) ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
