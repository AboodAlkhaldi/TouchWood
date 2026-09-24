<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Modules\Access\Public\Enums\StaffNotificationTopic;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * One topic's two switches on the notifications tab (frontend.md §3.2, B4).
 *
 * The topic travels as its own enum value rather than as a label, so the screen posts back
 * something Access recognises and the words stay in the translation files where every other word
 * of the screen is.
 */
#[TypeScript]
final class NotificationSetting extends Data
{
    public function __construct(
        /**
         * Typed as the enum here and written out as a plain string there, on purpose.
         *
         * Referring to the enum across namespaces makes the generator write
         * `import { StaffNotificationTopic } from '...'` into the generated file — a **value**
         * import of a type, which `verbatimModuleSyntax` refuses, so `npm run types` fails on a
         * file nobody is allowed to edit by hand (the generated types are compared against the
         * PHP by a test). PHP keeps the enum, which is where the safety is worth having: this
         * cannot be constructed with a topic that does not exist.
         */
        #[TypeScriptType('string')]
        public StaffNotificationTopic $topic,
        public bool $email,
        public bool $panel,
    ) {}
}
