/*
| The admin home's chart colours (dashboard.md §4): our tokens - brand navy, copper, then the information blue and
| the greys - read from the CSS variables, so light, dark and a campaign theme repaint them. Apart from the charts
| themselves, so the page's legends name the same colours before the charts' own code arrives.
*/

/** The parts' colours, in order: the largest part first takes the brand. */
export const PALETTE = ['var(--tw-brand)', 'var(--tw-accent)', 'var(--tw-info)', 'var(--tw-ink-muted)', 'var(--tw-sand-ink)', 'var(--tw-line-strong)'];

/** The n-th part's colour, round again past the last. */
export function colour(index: number): string {
    return PALETTE[index % PALETTE.length] ?? 'var(--tw-brand)';
}
