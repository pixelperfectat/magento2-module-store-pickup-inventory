<?php declare(strict_types=1);

namespace Magento\Sales\Api\Data;

if (!interface_exists(ShipmentCreationArgumentsExtensionInterface::class)) {
    /**
     * Test stand-in for the generated extension interface, as declared by Magento_InventoryShipping
     * (etc/extension_attributes.xml).
     */
    interface ShipmentCreationArgumentsExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
    {
        /**
         * @return string|null
         */
        public function getSourceCode();

        /**
         * @param string $sourceCode
         * @return $this
         */
        public function setSourceCode($sourceCode);
    }
}

if (!class_exists(ShipmentCreationArgumentsExtensionInterfaceFactory::class)) {
    /**
     * Test stand-in for the auto-generated factory.
     */
    class ShipmentCreationArgumentsExtensionInterfaceFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return ShipmentCreationArgumentsExtensionInterface
         */
        public function create(array $data = []): ShipmentCreationArgumentsExtensionInterface
        {
            throw new \LogicException('Test stand-in: mock it.');
        }
    }
}
