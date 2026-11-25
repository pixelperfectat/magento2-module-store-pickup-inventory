<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Api\Service;

/**
 * Maps an inventory source code to a pickup location code.
 *
 * @api
 */
interface SourceCodeMapperInterface
{
    /**
     * Pickup location code of a source code.
     *
     * A source code that is already a valid location code is used as is, unless it has the shape of a hash-based
     * code. Any other source code becomes a stable hash-based code, so the result always passes the core location
     * code check and two different source codes do not share a location code (up to a hash collision).
     *
     * @param string $sourceCode
     * @return string
     */
    public function toLocationCode(string $sourceCode): string;
}
