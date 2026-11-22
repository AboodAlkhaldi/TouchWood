/*
| Geist's Switch on shadcn's ToggleGroup, unchanged: a sunken track with the chosen view raised on
| it - the panel's one look for a choice between two views (the media library's table or grid, the
| home's scope).
*/

export const SWITCH_TRACK = 'gap-0.5 rounded-[var(--tw-radius)] bg-surface-sunken p-0.5 shadow-[inset_0_0_0_1px_var(--tw-line)]';

export const SWITCH_ITEM =
    'h-8 rounded-[calc(var(--tw-radius)-2px)] px-3 text-label-13 text-ink-muted hover:bg-transparent hover:text-ink data-[state=on]:bg-surface data-[state=on]:text-ink data-[state=on]:shadow-[var(--tw-shadow-small)]';
