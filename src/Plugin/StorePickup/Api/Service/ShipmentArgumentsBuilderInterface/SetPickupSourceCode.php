<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Plugin\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterface;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface;
use PixelPerfect\StorePickup\Api\Data\PickupLocationInterface;
use PixelPerfect\StorePickup\Api\Service\ShipmentArgumentsBuilderInterface;

/**
 * Ships the ready-for-pickup shipment from the inventory source the pickup location is bound to.
 *
 * Without this, Magento_InventoryShipping picks the default source for the shipment and deducts the stock there.
 */
class SetPickupSourceCode
{
    /**
     * Constructor.
     *
     * @param ShipmentCreationArgumentsExtensionInterfaceFactory $extensionFactory
     */
    public function __construct(
        private readonly ShipmentCreationArgumentsExtensionInterfaceFactory $extensionFactory
    ) {
    }

    /**
     * Sets the `source_code` extension attribute to the location's stock binding; a location without one is left alone.
     *
     * @param ShipmentArgumentsBuilderInterface $subject
     * @param ShipmentCreationArgumentsInterface $result
     * @param OrderInterface $order
     * @param PickupLocationInterface $location
     * @return ShipmentCreationArgumentsInterface
     */
    public function afterBuild(
        ShipmentArgumentsBuilderInterface $subject,
        ShipmentCreationArgumentsInterface $result,
        OrderInterface $order,
        PickupLocationInterface $location
    ): ShipmentCreationArgumentsInterface {
        $sourceCode = $location->getStockBinding();
        if ($sourceCode === null) {
            return $result;
        }

        $extension = $result->getExtensionAttributes();
        if (!$extension instanceof ShipmentCreationArgumentsExtensionInterface) {
            $extension = $this->extensionFactory->create();
        }
        $extension->setSourceCode($sourceCode);
        $result->setExtensionAttributes($extension);

        return $result;
    }
}
