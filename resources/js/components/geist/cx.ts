/*
| Joins class names, skipping the empty ones. Deliberately no merging: a Geist component owns its
| look, and a caller's className adds layout (margins, width), never a second colour or size.
*/
export function cx(...parts: Array<string | false | null | undefined>): string {
    return parts.filter(Boolean).join(' ');
}
