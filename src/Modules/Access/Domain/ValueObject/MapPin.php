<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * Where an address is on the map (spec §1.9): both coordinates or neither, each within its range.
 * Six decimals — about ten centimetres — is what the column keeps.
 */
final readonly class MapPin
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * @throws InvalidAccessAttribute
     */
    public static function of(float $latitude, float $longitude): self
    {
        // Every comparison with NAN is false, so it would pass a range check unasked (review of
        // step 5); the column keeps it, and the CHECK then refuses the row.
        if (! is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidAccessAttribute('latitude', 'between -90 and 90');
        }

        if (! is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidAccessAttribute('longitude', 'between -180 and 180');
        }

        return new self(round($latitude, 6), round($longitude, 6));
    }

    /**
     * Both or neither: half a pin points nowhere.
     *
     * @throws InvalidAccessAttribute
     */
    public static function optional(?float $latitude, ?float $longitude): ?self
    {
        if ($latitude === null && $longitude === null) {
            return null;
        }

        if ($latitude === null || $longitude === null) {
            throw new InvalidAccessAttribute('map_pin', 'both coordinates or neither');
        }

        return self::of($latitude, $longitude);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->latitude === $this->latitude && $other->longitude === $this->longitude;
    }
}
