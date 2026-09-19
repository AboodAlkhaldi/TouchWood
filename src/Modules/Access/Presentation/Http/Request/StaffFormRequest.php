<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a sign-in form (handoff §5.3: form requests for shape and type, the domain for the
 * rules). Permissions are the handlers' — never checked here.
 */
abstract class StaffFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function text(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : '';
    }
}
