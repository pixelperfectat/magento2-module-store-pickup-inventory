<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Unit\Service\LocationProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\InventoryApi\Api\Data\SourceExtensionInterface;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Model\Data\PickupLocationFactory;
use PixelPerfect\StorePickup\Service\LocationProvider\CompositeLocationProvider;
use PixelPerfect\StorePickup\Service\LocationProvider\ConfigLocationProvider;
use PixelPerfect\StorePickup\Service\PickupMethodResolver;
use PixelPerfect\StorePickupInventory\Service\LocationProvider\MsiSourceLocationProvider;
use PixelPerfect\StorePickupInventory\Service\SourceCodeMapper;
use Psr\Log\LoggerInterface;

class MsiSourceLocationProviderTest extends TestCase
{
    private const STORE_ID = 3;

    /** @var StockResolverInterface&MockObject */
    private $stockResolver;

    /** @var GetSourcesAssignedToStockOrderedByPriorityInterface&MockObject */
    private $sources;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    protected function setUp(): void
    {
        $this->stockResolver = $this->createMock(StockResolverInterface::class);
        $this->sources = $this->createMock(GetSourcesAssignedToStockOrderedByPriorityInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->with(self::STORE_ID)->willReturn($store);
        $this->storeManager->method('getWebsite')->with(1)->willReturn($website);
    }

    private function provider(): MsiSourceLocationProvider
    {
        return new MsiSourceLocationProvider(
            $this->storeManager,
            $this->stockResolver,
            $this->sources,
            new SourceCodeMapper(new PickupMethodResolver()),
            new PickupLocationFactory(),
            $this->logger
        );
    }

    private function stock(int $id = 7): void
    {
        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn($id);
        $this->stockResolver->method('execute')->with('website', 'base')->willReturn($stock);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return SourceInterface&MockObject
     */
    private function source(string $code, array $overrides = []): SourceInterface
    {
        $values = $overrides + [
            'name' => 'Source ' . $code,
            'enabled' => true,
            'pickup' => true,
            'frontend_name' => null,
            'frontend_description' => null,
            'street' => '1 Example Street',
            'postcode' => '1010',
            'city' => 'Vienna',
            'country' => 'AT',
            'region_id' => null,
            'region' => null,
            'phone' => null,
            'extension' => true,
        ];
        $extension = $this->createMock(SourceExtensionInterface::class);
        $extension->method('getIsPickupLocationActive')->willReturn($values['pickup']);
        $extension->method('getFrontendName')->willReturn($values['frontend_name']);
        $extension->method('getFrontendDescription')->willReturn($values['frontend_description']);

        $source = $this->createMock(SourceInterface::class);
        $source->method('getSourceCode')->willReturn($code);
        $source->method('getName')->willReturn($values['name']);
        $source->method('isEnabled')->willReturn($values['enabled']);
        $source->method('getStreet')->willReturn($values['street']);
        $source->method('getPostcode')->willReturn($values['postcode']);
        $source->method('getCity')->willReturn($values['city']);
        $source->method('getCountryId')->willReturn($values['country']);
        $source->method('getRegionId')->willReturn($values['region_id']);
        $source->method('getRegion')->willReturn($values['region']);
        $source->method('getPhone')->willReturn($values['phone']);
        $source->method('getExtensionAttributes')->willReturn($values['extension'] ? $extension : null);

        return $source;
    }

    public function testOnlyPickupActiveSourceBecomesALocationWithMappedFields(): void
    {
        $this->stock(7);
        $this->sources->method('execute')->with(7)->willReturn([
            $this->source('Warehouse-2', ['pickup' => false]),
            $this->source('Vienna-1', [
                'name' => 'Vienna warehouse',
                'frontend_name' => 'Pick up in Vienna',
                'frontend_description' => 'Ring the bell.',
                'street' => 'Example Street 1',
                'postcode' => '1010',
                'city' => 'Vienna',
                'country' => 'AT',
                'region_id' => 94,
                'region' => 'Wien',
                'phone' => '+43 1 000000',
            ]),
        ]);

        $locations = $this->provider()->getLocations(self::STORE_ID);

        self::assertCount(1, $locations);
        $location = reset($locations);
        $code = 's' . substr(sha1('Vienna-1'), 0, 16);
        self::assertSame([$code], array_keys($locations));
        self::assertSame($code, $location->getCode());
        self::assertSame('Pick up in Vienna', $location->getName());
        self::assertSame(['Example Street 1'], $location->getStreet());
        self::assertSame('1010', $location->getPostcode());
        self::assertSame('Vienna', $location->getCity());
        self::assertSame('AT', $location->getCountryId());
        self::assertSame(94, $location->getRegionId());
        self::assertSame('Wien', $location->getRegion());
        self::assertSame('+43 1 000000', $location->getTelephone());
        self::assertNull($location->getOpeningHours());
        self::assertSame('Ring the bell.', $location->getNote());
        self::assertNull($location->getPrice());
        self::assertSame(10, $location->getSortOrder());
        self::assertTrue($location->isActive());
        self::assertSame('Vienna-1', $location->getStockBinding());
    }

    public function testValidSourceCodeIsUsedAsLocationCode(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([$this->source('vienna_1')]);

        self::assertSame(['vienna_1'], array_keys($this->provider()->getLocations(self::STORE_ID)));
    }

    public function testSortOrderFollowsStockPriorityPositionTimesTen(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('first', ['pickup' => false]),
            $this->source('second'),
            $this->source('third'),
        ]);

        $locations = $this->provider()->getLocations(self::STORE_ID);

        self::assertSame(10, $locations['second']->getSortOrder());
        self::assertSame(20, $locations['third']->getSortOrder());
    }

    public function testDisabledSourceIsNotListed(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([$this->source('closed', ['enabled' => false])]);

        self::assertSame([], $this->provider()->getLocations(self::STORE_ID));
    }

    public function testEmptyFrontendNameFallsBackToSourceName(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('one', ['frontend_name' => '', 'name' => 'Warehouse One']),
            $this->source('two', ['frontend_name' => '  ', 'name' => 'Warehouse Two']),
        ]);

        $locations = $this->provider()->getLocations(self::STORE_ID);

        self::assertSame('Warehouse One', $locations['one']->getName());
        self::assertSame('Warehouse Two', $locations['two']->getName());
    }

    public function testEmptyOptionalFieldsBecomeNull(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('one', ['frontend_description' => '', 'region' => '', 'phone' => '', 'region_id' => 0]),
        ]);

        $location = $this->provider()->getLocations(self::STORE_ID)['one'];

        self::assertNull($location->getNote());
        self::assertNull($location->getRegion());
        self::assertNull($location->getTelephone());
        self::assertNull($location->getRegionId());
    }

    public function testUnknownWebsiteStockYieldsNoLocationsAndIsLogged(): void
    {
        $this->stockResolver->method('execute')->willThrowException(new NoSuchEntityException(__('No stock.')));
        $this->sources->expects(self::never())->method('execute');
        $this->logger->expects(self::once())->method('warning')->with(
            'No inventory stock is linked to website "base"; no pickup locations from inventory sources.'
        );

        $provider = $this->provider();

        self::assertSame([], $provider->getLocations(self::STORE_ID));
        self::assertNull($provider->getLocation('anything', self::STORE_ID));
    }

    public function testGetLocationAlsoReturnsInactiveAssignedSources(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('Not-Pickup', ['pickup' => false]),
            $this->source('closed', ['enabled' => false]),
            $this->source('open'),
        ]);
        $provider = $this->provider();

        $inactive = $provider->getLocation('s' . substr(sha1('Not-Pickup'), 0, 16), self::STORE_ID);
        self::assertNotNull($inactive);
        self::assertFalse($inactive->isActive());
        self::assertSame('Not-Pickup', $inactive->getStockBinding());
        self::assertFalse($provider->getLocation('closed', self::STORE_ID)?->isActive());
        self::assertTrue($provider->getLocation('open', self::STORE_ID)?->isActive());
        self::assertNull($provider->getLocation('unknown', self::STORE_ID));
    }

    public function testSourcesAreLoadedOncePerStore(): void
    {
        $this->stock();
        $this->sources->expects(self::once())->method('execute')->willReturn([$this->source('open')]);
        $provider = $this->provider();

        $provider->getLocations(self::STORE_ID);
        $provider->getLocation('open', self::STORE_ID);
        $provider->getLocations(self::STORE_ID);
    }

    public function testConfigRowWinsOverSourceWithTheSameCodeAndYieldsOneLocation(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([$this->source('vienna_1', ['frontend_name' => 'From MSI'])]);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === ConfigLocationProvider::XML_PATH_LOCATIONS
                ? (new Json())->serialize([[
                    'code' => 'vienna_1',
                    'name' => 'From config',
                    'street' => '2 Example Street',
                    'postcode' => '1010',
                    'city' => 'Vienna',
                    'country_id' => 'AT',
                    'active' => '1',
                    'sort_order' => '0',
                ]])
                : null
        );
        $configProvider = new ConfigLocationProvider(
            $scopeConfig,
            new Json(),
            new PickupMethodResolver(),
            new PickupLocationFactory(),
            $this->createMock(LoggerInterface::class)
        );
        $compositeLogger = $this->createMock(LoggerInterface::class);
        $compositeLogger->expects(self::once())->method('warning')->with(
            'Pickup location code "vienna_1" from provider "msi_sources" is already taken; ignored.'
        );
        $compositeLogger->expects(self::never())->method('error');
        $composite = new CompositeLocationProvider(
            $compositeLogger,
            ['config' => $configProvider, 'msi_sources' => $this->provider()]
        );

        $locations = $composite->getLocations(self::STORE_ID);

        // The carrier creates one rate per listed location, so one location is one rate.
        self::assertCount(1, $locations);
        self::assertSame('From config', $locations['vienna_1']->getName());
        self::assertNull($locations['vienna_1']->getStockBinding());
        self::assertSame('From config', $composite->getLocation('vienna_1', self::STORE_ID)?->getName());
    }

    public function testSourceWithoutExtensionAttributesIsKnownButNotActive(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([$this->source('plain', ['extension' => false])]);
        $provider = $this->provider();

        self::assertSame([], $provider->getLocations(self::STORE_ID));
        $location = $provider->getLocation('plain', self::STORE_ID);
        self::assertSame('Source plain', $location?->getName());
        self::assertNull($location->getNote());
        self::assertFalse($location->isActive());
    }

    public function testUnsetEnabledOrPickupFlagMeansNotActive(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('no_enabled', ['enabled' => null]),
            $this->source('no_pickup', ['pickup' => null]),
        ]);

        self::assertSame([], $this->provider()->getLocations(self::STORE_ID));
        self::assertNotNull($this->provider()->getLocation('no_pickup', self::STORE_ID));
    }

    public function testMissingAddressPartsBecomeEmptyStrings(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('bare', ['name' => null, 'street' => null, 'postcode' => null, 'city' => null, 'country' => null]),
        ]);

        $location = $this->provider()->getLocations(self::STORE_ID)['bare'];

        self::assertSame('', $location->getName());
        self::assertSame([], $location->getStreet());
        self::assertSame('', $location->getPostcode());
        self::assertSame('', $location->getCity());
        self::assertSame('', $location->getCountryId());
    }

    public function testStreetIsSplitIntoTrimmedNonEmptyLines(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('multi', ['street' => " Example Street 1 \r\n\n  Building B  \n   "]),
        ]);

        self::assertSame(
            ['Example Street 1', 'Building B'],
            $this->provider()->getLocations(self::STORE_ID)['multi']->getStreet()
        );
    }

    public function testTextsAreTrimmed(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([
            $this->source('trim', [
                'frontend_name' => '  Shown name ',
                'frontend_description' => ' Note ',
                'region' => ' Wien ',
                'phone' => ' 123 ',
            ]),
        ]);

        $location = $this->provider()->getLocations(self::STORE_ID)['trim'];

        self::assertSame('Shown name', $location->getName());
        self::assertSame('Note', $location->getNote());
        self::assertSame('Wien', $location->getRegion());
        self::assertSame('123', $location->getTelephone());
    }

    public function testSortOrderFollowsPositionEvenWhenTheSourceListHasGapsInItsKeys(): void
    {
        $this->stock();
        $this->sources->method('execute')->willReturn([5 => $this->source('one'), 9 => $this->source('two')]);

        $locations = $this->provider()->getLocations(self::STORE_ID);

        self::assertSame(0, $locations['one']->getSortOrder());
        self::assertSame(10, $locations['two']->getSortOrder());
    }

    public function testStockIdIsPassedToTheSourceLookup(): void
    {
        $this->stock(42);
        $this->sources->expects(self::once())->method('execute')->with(42)->willReturn([]);

        self::assertSame([], $this->provider()->getLocations(self::STORE_ID));
    }
}
