<?php

namespace Modules\Platform\Application\Settings;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;

/**
 * Every declared setting, collected from the modules' service providers at boot
 * (Platform spec §1.3). A key is declared by exactly one module and starts with its name.
 */
final class InMemorySettingsRegistry implements SettingsRegistry
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/';

    private const int MAX_KEY_LENGTH = 150;

    /** @var array<string, SettingDefinitionDto> */
    private array $definitions = [];

    public function __construct(
        private readonly ValidatorFactory $validator,
    ) {}

    public function define(string $module, SettingDefinitionDto ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $key = $definition->key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > self::MAX_KEY_LENGTH) {
                throw new InvalidSettingDefinition("The setting key \"{$key}\" must look like \"{module}.{area}.{name}\" in lowercase, at most 150 characters.");
            }

            if (! str_starts_with($key, $module.'.')) {
                throw new InvalidSettingDefinition("The {$module} module cannot declare \"{$key}\": its keys must start with \"{$module}.\".");
            }

            if (isset($this->definitions[$key])) {
                throw new InvalidSettingDefinition("The setting \"{$key}\" is already declared.");
            }

            $problem = $this->validationProblem($definition, $definition->default);

            if ($problem !== null) {
                throw new InvalidSettingDefinition("The default of \"{$key}\" fails its own rules: {$problem}");
            }

            $this->definitions[$key] = $definition;
        }
    }

    public function definition(string $key): ?SettingDefinitionDto
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * The first reason the value breaks the definition's rules, or null when it is valid.
     */
    public function validationProblem(SettingDefinitionDto $definition, mixed $value): ?string
    {
        $validation = $this->validator->make(['value' => $value], ['value' => $definition->rules]);

        return $validation->fails() ? (string) $validation->errors()->first('value') : null;
    }
}
