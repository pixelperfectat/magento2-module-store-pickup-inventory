<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Generated classes only exist in a generated Magento installation: use stand-ins.
if (!interface_exists(\Magento\InventoryApi\Api\Data\SourceExtensionInterface::class)) {
    require __DIR__ . '/Stubs/SourceExtensionInterface.php';
}

if (!class_exists(\PixelPerfect\StorePickup\Model\Data\PickupLocationFactory::class)) {
    require __DIR__ . '/Stubs/PickupLocationFactory.php';
}

if (!interface_exists(\Magento\Sales\Api\Data\ShipmentCreationArgumentsExtensionInterface::class)) {
    require __DIR__ . '/Stubs/ShipmentCreationArgumentsExtension.php';
}
