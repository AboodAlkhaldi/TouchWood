<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Illuminate\Contracts\Validation\UncompromisedVerifier;

/**
 * Stands in for the online breach list, so tests never call it: only LEAKED counts as found.
 */
final class FakeBreachList implements UncompromisedVerifier
{
    public const string LEAKED = 'password123456789';

    public static function install(): void
    {
        app()->instance(UncompromisedVerifier::class, new self);
    }

    /**
     * @param  array{value: string, threshold: int}  $data
     */
    public function verify($data): bool
    {
        return $data['value'] !== self::LEAKED;
    }
}
