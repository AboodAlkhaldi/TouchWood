/**
 * One or two letters of a name, as Geist's Avatar takes them: the first letter of its first two
 * words ("Sara Ali" → "SA"). The lists show initials, not pictures: resolving a picture per row
 * would be a call to Platform per person (owner, 2026-09-23).
 */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter((word) => word !== '')
        .slice(0, 2)
        .map((word) => word.slice(0, 1).toLocaleUpperCase())
        .join('');
}
