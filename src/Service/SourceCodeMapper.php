<?php declare(strict_types=1);

namespace PixelPerfect\StorePickupInventory\Service;

use PixelPerfect\StorePickup\Api\Service\PickupMethodResolverInterface;
use PixelPerfect\StorePickupInventory\Api\Service\SourceCodeMapperInterface;

/**
 * Keeps valid source codes and replaces all others by a prefix plus the start of their hash.
 */
class SourceCodeMapper implements SourceCodeMapperInterface
{
    /**
     * Constructor.
     *
     * The algorithm, length and prefix are part of every stored location code: changing them changes the codes of
     * all sources with an invalid code.
     *
     * @param PickupMethodResolverInterface $resolver
     * @param string $algorithm hash algorithm
     * @param int $hashLength number of hex characters kept from the hash
     * @param string $prefix marks a hashed code
     */
    public function __construct(
        private readonly PickupMethodResolverInterface $resolver,
        private readonly string $algorithm = 'sha1',
        private readonly int $hashLength = 16,
        private readonly string $prefix = 's'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toLocationCode(string $sourceCode): string
    {
        if ($this->resolver->isValidLocationCode($sourceCode)) {
            return $sourceCode;
        }

        return $this->prefix . substr(hash($this->algorithm, $sourceCode), 0, $this->hashLength);
    }
}
