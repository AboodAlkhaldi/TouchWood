<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AdminShell;

/**
 * Who the admin panel is being shown to (frontend.md §2.2, stage 2b step 1).
 *
 * The person block at the bottom of the sidebar, and the facts the shell needs about them. Not
 * their permissions: what they may use is asked of the authorizer, entry by entry, by the menu.
 */
final readonly class AdminShellDto
{
    /**
     * @param  string  $name  as it is shown, already joined
     * @param  string|null  $roleLabel  the role they hold, or null for a Super Admin, who holds
     *                                  none - the panel says "Super Admin" in its own words
     * @param  string|null  $avatarUrl  null when they have not set a picture
     * @param  string  $locale  their **communication** language (amendment 16), which is the
     *                          panel's language only until this browser chooses otherwise
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $roleLabel,
        public ?string $avatarUrl,
        public bool $isSuperAdmin,
        public string $locale,
    ) {}
}
