<?php

declare(strict_types=1);

namespace Modules\Platform\Public;

/**
 * How the media library keeps a file's name (platform.md §1.4; the modules' requests, B2B step 6):
 * the name alone — no folders, no control characters, no invisible formatting characters such as a
 * right-to-left mark that disguises an extension — trimmed of spaces. The media model keeps names by
 * this function, and a module compares a name it is given with the names of files it holds by it.
 *
 * It only cleans: whether the name may be kept — real UTF-8, 1 to 255 characters — is the media
 * model's to say, when the file is uploaded.
 */
final class MediaFilename
{
    public static function kept(string $filename): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', basename(str_replace('\\', '/', $filename))));
    }
}
