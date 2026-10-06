<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

/**
 * How a label looks: one of the ten variants of the project's Geist Badge (frontend.md §1.8;
 * `resources/js/components/geist/Feedback.tsx`), and so **its colour follows Geist's meanings**
 * (catalog.md amendment 1(e), owner 2026-10-03: "green is always healthy") — gray neutral, blue
 * information, green healthy, amber warning, red error; each strong, or subtle for dense surfaces.
 *
 * Staff choose a meaning and a strength; the screens name each by its meaning, never by a bare
 * colour. The values are the Badge's own variant names, so a label is drawn with the Badge as it is.
 */
enum LabelTone: string
{
    case Neutral = 'gray';
    case Information = 'blue';
    case Healthy = 'green';
    case Warning = 'amber';
    case Error = 'red';
    case NeutralSubtle = 'gray-subtle';
    case InformationSubtle = 'blue-subtle';
    case HealthySubtle = 'green-subtle';
    case WarningSubtle = 'amber-subtle';
    case ErrorSubtle = 'red-subtle';
}
