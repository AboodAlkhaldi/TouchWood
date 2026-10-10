<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AdminShell;

/**
 * Who the admin panel is being shown to (frontend.md §2.2, stage 2b step 1).
 *
 * The person block at the bottom of the sidebar, and the facts the shell needs about them. Not
 * their permissions: what they may use is asked of the authorizer, entry by entry, by the menu.
 *
 * Both of the role's names travel together, so one read answers both questions the shell asks -
 * which language the panel is in, and what the person block says in it (amendment 65).
 */
final readonly class AdminShellDto
{
    /**
     * @param  string  $name  as it is shown, already joined
     * @param  string|null  $roleNameAr  the role they hold, in Arabic; null for a Super Admin, who
     *                                   holds none - the panel says "Super Admin" in its own words
     * @param  string|null  $roleNameEn  the same, in English
     * @param  string|null  $avatarUrl  null when they have not set a picture
     * @param  string  $locale  their **communication** language (amendment 16), which is the
     *                          panel's language only until this browser chooses otherwise
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $roleNameAr,
        public ?string $roleNameEn,
        public ?string $avatarUrl,
        public bool $isSuperAdmin,
        public string $locale,
    ) {}

    /**
     * The role's own name in the language the panel is being read in.
     */
    public function roleLabel(string $locale): ?string
    {
        return $locale === 'en' ? $this->roleNameEn : $this->roleNameAr;
    }
}
