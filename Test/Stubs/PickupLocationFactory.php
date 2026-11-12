<?php declare(strict_types=1);

namespace PixelPerfect\StorePickup\Model\Data;

if (!class_exists(PickupLocationFactory::class)) {
    /**
     * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
     */
    class PickupLocationFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return PickupLocation
         */
        public function create(array $data = []): PickupLocation
        {
            return new PickupLocation(...$data);
        }
    }
}
