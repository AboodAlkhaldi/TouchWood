<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Settings;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Every declared setting, collected from the modules' service providers at boot
 * (Platform spec §1.3). A key is declared by exactly one module and starts with its name.
 */
final class InMemorySettingsRegistry implements SettingsRegistry
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}\z/';

    private const int MAX_KEY_LENGTH = 150;

    /**
     * A key segment that names a secret: those belong in server environment variables. More words
     * may follow (secret_key_live, api_key_test, password_hash).
     */
    private const string SECRET_NAME = '/(^|_)(password|passwd|passphrase|secret|token|api_?key|private_?key|access_?key|secret_?key|signing_?key|encryption_?key|hmac_?key|merchant_?key|license_?key|credentials?)(_|\z)|(^|_)pass\z/';

    /**
     * A key whose last segment ends like this is a policy about a secret, not a secret: its length,
     * lifetime or number of attempts (password_min_length, token.lifetime_minutes).
     */
    private const string POLICY_NAME = '/(^|_)(length|ttl|lifetime|seconds|minutes|hours|days|attempts|count|limit|enabled|required)\z/';

    /** @var array<string, SettingDefinitionDto> */
    private array $definitions = [];

    public function __construct(
        private readonly ValidatorFactory $validator,
    ) {}

    /**
     * Any segment counts: "payments.api_key.live" names a secret as much as "payments.live.api_key".
     */
    private function looksLikeSecret(string $key): bool
    {
        $segments = explode('.', $key);

        if (preg_match(self::POLICY_NAME, $segments[array_key_last($segments)]) === 1) {
            return false;
        }

        return array_filter($segments, fn (string $segment): bool => preg_match(self::SECRET_NAME, $segment) === 1) !== [];
    }

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

            if ($this->looksLikeSecret($key)) {
                throw new InvalidSettingDefinition("\"{$key}\" looks like a secret. Secrets live in server environment variables, never in settings.");
            }

            if (isset($this->definitions[$key])) {
                throw new InvalidSettingDefinition("The setting \"{$key}\" is already declared.");
            }

            $problem = $this->validationProblem($definition, $definition->default);

            if ($problem !== null) {
                throw new InvalidSettingDefinition("The default of \"{$key}\" fails its own type or rules: {$problem}");
            }

            $this->definitions[$key] = $definition;
        }
    }

    public function definition(string $key): ?SettingDefinitionDto
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * The first reason the value is not valid for the definition, or null when it is.
     *
     * The type is checked strictly first: Laravel's rules skip empty strings and accept "5" as an
     * integer, which would let a value through that then breaks every typed read.
     */
    public function validationProblem(SettingDefinitionDto $definition, mixed $value): ?string
    {
        $typeProblem = match ($definition->type) {
            SettingType::Integer => is_int($value) ? null : 'must be a whole number',
            SettingType::Boolean => is_bool($value) ? null : 'must be true or false',
            SettingType::Text => is_string($value) && trim($value) !== '' ? null : 'must be text that is not empty',
            SettingType::List => self::isListOfScalars($value) ? null : 'must be a list of numbers, text or true/false values',
        };

        if ($typeProblem !== null) {
            return $typeProblem;
        }

        // The matching type rule goes first so size rules compare the right thing: without it,
        // "min:1|max:10" measures the length of 11 (two characters) and accepts it.
        $typeRule = match ($definition->type) {
            SettingType::Integer => 'integer',
            SettingType::Boolean => 'boolean',
            SettingType::Text => 'string',
            SettingType::List => 'array',
        };

        $validation = $this->validator->make(['value' => $value], ['value' => [$typeRule, ...$definition->rules]]);

        return $validation->fails() ? (string) $validation->errors()->first('value') : null;
    }

    private static function isListOfScalars(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return false;
            }
        }

        return true;
    }
}
