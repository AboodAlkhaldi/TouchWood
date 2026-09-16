<?php

namespace Modules\Platform\Application\Settings;

use LogicException;

/**
 * A module declared a setting wrongly. A programming error, raised at boot — never shown to a
 * customer and never a DomainError.
 */
final class InvalidSettingDefinition extends LogicException {}
