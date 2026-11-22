<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Request;

/**
 * A staff member's own profile, as the account form sends it (frontend.md §3.2, B1).
 *
 * Shape and type only (handoff §5.3): what a name may be, what a date of birth may be and which
 * countries exist are the domain's, and StaffProfile refuses them with its own translated error.
 * The picture is a file here and a media id by the time it reaches the handler — the controller
 * hands it to Platform in between, which is the only thing allowed to turn one into the other.
 */
final class OwnProfileRequest extends AccessFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'job_title' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'string', 'max:10'],
            'country' => ['required', 'string', 'max:2'],
            'address' => ['nullable', 'string', 'max:500'],
            'locale' => ['required', 'string', 'max:5'],
            // Whether it is an image Platform will accept is Platform's to say, against the
            // settings an admin set — not a number written into a form request here.
            //
            // `nullable`, not `sometimes`: a form with no picture chosen sends the field anyway,
            // as null over JSON and as an empty string as multipart, and `sometimes` only skips a
            // field that is absent altogether. It would refuse every save that left the picture
            // alone.
            'avatar' => ['nullable', 'file'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
