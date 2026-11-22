<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

use Modules\Content\Domain\Exception\RedirectInvalid;
use Modules\Content\Domain\Repository\Redirects;

/**
 * What a sheet's lines would do to the redirects as they are now (content.md §1.15, W11b): **New** (no line has its
 * old address), **Same** (one has it, going to the same page), **Change** (one has it, going elsewhere), or
 * **Refused** with the rule (`RedirectInvalid`'s, or `twice` for an old address the sheet wrote above already).
 * Read when the sheet is uploaded and again, from what was typed, when it is applied, so Apply writes what holds at
 * that moment.
 */
final readonly class RedirectSheet
{
    public const string NEW = 'NEW';

    public const string SAME = 'SAME';

    public const string CHANGE = 'CHANGE';

    public const string REFUSED = 'REFUSED';

    /** What a line keeps of an address as typed: an address kept is 2,000 characters at most, and a host may lead it. */
    private const int TYPED = 2100;

    public function __construct(
        private RedirectRules $rules,
        private Redirects $redirects,
    ) {}

    /**
     * The addresses come back as kept, or as typed when refused.
     *
     * @param  list<array{line: int, old: string, new: string}>  $lines  as typed
     * @param  bool  $lock  whether the lines found are locked (Apply)
     * @return list<array{line: int, old: string, new: string, result: string, reason: string|null, typed_old: string, typed_new: string}>
     */
    public function check(array $lines, bool $lock = false): array
    {
        $read = [];
        $seen = [];

        foreach ($lines as $line) {
            $typedOld = mb_substr($line['old'], 0, self::TYPED);
            $typedNew = mb_substr($line['new'], 0, self::TYPED);

            try {
                [$old, $new] = $this->rules->line($typedOld, $typedNew);
                $reason = isset($seen[$old]) ? RedirectInvalid::TWICE : null;
                $seen[$old] = true;
            } catch (RedirectInvalid $refused) {
                [$old, $new, $reason] = [$typedOld, $typedNew, $refused->rule];
            }

            $read[] = ['line' => $line['line'], 'old' => $old, 'new' => $new, 'reason' => $reason, 'typed_old' => $typedOld, 'typed_new' => $typedNew];
        }

        $valid = array_values(array_map(static fn (array $line): string => $line['old'], array_filter($read, static fn (array $line): bool => $line['reason'] === null)));
        $now = $valid === [] ? [] : $this->redirects->findMany(array_values(array_unique($valid)), $lock);

        return array_map(static function (array $line) use ($now): array {
            $kept = $line['reason'] === null ? ($now[$line['old']] ?? null) : null;

            return [
                ...$line,
                'result' => match (true) {
                    $line['reason'] !== null => self::REFUSED,
                    $kept === null => self::NEW,
                    $kept->newPath === $line['new'] => self::SAME,
                    default => self::CHANGE,
                },
            ];
        }, $read);
    }
}
