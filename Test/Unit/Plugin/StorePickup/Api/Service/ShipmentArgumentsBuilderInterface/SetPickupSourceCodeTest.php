<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Test\Unit\Plugin\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;
use PixelPerfect\StorePickupInventory\Plugin\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface\SetPickupSourceCode;

class SetPickupSourceCodeTest extends TestCase
{
    /** @var ShipmentCreationArgumentsExtensionInterfaceFactory&MockObject */
    private $extensionFactory;

    private SetPickupSourceCode $plugin;

    protected function setUp(): void
    {
        $this->extensionFactory = $this->createMock(ShipmentCreationArgumentsExtensionInterfaceFactory::class);
        $this->plugin = new SetPickupSourceCode($this->extensionFactory);
    }

    private function location(?string $binding): PickupLocationInterface
    {
        $location = $this->createMock(PickupLocationInterface::class);
        $location->method('getStockBinding')->willReturn($binding);

        return $location;
    }

    private function build(ShipmentCreationArgumentsInterface $arguments, ?string $binding): ShipmentCreationArgumentsInterface
    {
        return $this->plugin->afterBuild(
            $this->createMock(ShipmentArgumentsBuilderInterface::class),
            $arguments,
            $this->createMock(OrderInterface::class),
            $this->location($binding)
        );
    }

    public function testBoundLocationSetsTheSourceCodeOnNewExtensionAttributes(): void
    {
        $extension = $this->createMock(ShipmentCreationArgumentsExtensionInterface::class);
        $extension->expects(self::once())->method('setSourceCode')->with('vienna_1')->willReturnSelf();
        $this->extensionFactory->expects(self::once())->method('create')->willReturn($extension);
        $arguments = $this->createMock(ShipmentCreationArgumentsInterface::class);
        $arguments->method('getExtensionAttributes')->willReturn(null);
        $arguments->expects(self::once())->method('setExtensionAttributes')->with($extension);

        self::assertSame($arguments, $this->build($arguments, 'vienna_1'));
    }

    public function testExistingExtensionAttributesAreKeptAndExtended(): void
    {
        $extension = $this->createMock(ShipmentCreationArgumentsExtensionInterface::class);
        $extension->expects(self::once())->method('setSourceCode')->with('vienna_1')->willReturnSelf();
        $this->extensionFactory->expects(self::never())->method('create');
        $arguments = $this->createMock(ShipmentCreationArgumentsInterface::class);
        $arguments->method('getExtensionAttributes')->willReturn($extension);
        $arguments->expects(self::once())->method('setExtensionAttributes')->with($extension);

        self::assertSame($arguments, $this->build($arguments, 'vienna_1'));
    }

    public function testUnboundLocationLeavesTheArgumentsUntouched(): void
    {
        $this->extensionFactory->expects(self::never())->method('create');
        $arguments = $this->createMock(ShipmentCreationArgumentsInterface::class);
        $arguments->expects(self::never())->method('getExtensionAttributes');
        $arguments->expects(self::never())->method('setExtensionAttributes');

        self::assertSame($arguments, $this->build($arguments, null));
    }
}
