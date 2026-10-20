<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * Why the customer asks to return pieces (sales.md §1.8): one of the list, or `OTHER` with their words.
 */
enum ReturnReason: string
{
    case Damaged = 'DAMAGED';
    case WrongItem = 'WRONG_ITEM';
    case NotAsDescribed = 'NOT_AS_DESCRIBED';
    case ChangedMind = 'CHANGED_MIND';
    case Other = 'OTHER';
}
