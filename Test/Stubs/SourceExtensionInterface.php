<?php declare(strict_types=1);

namespace Magento\InventoryApi\Api\Data;

/**
 * Test stand-in for the generated extension interface of an inventory source, as declared by
 * Magento_InventoryInStorePickupApi (etc/extension_attributes.xml).
 */
interface SourceExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
{
    /**
     * @return bool|null
     */
    public function getIsPickupLocationActive();

    /**
     * @return string|null
     */
    public function getFrontendName();

    /**
     * @return string|null
     */
    public function getFrontendDescription();
}
