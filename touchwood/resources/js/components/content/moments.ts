import type { MomentInput } from '@/lib/moments';

/*
| A moment as Content's handlers take it (content.md §1.1 rule 6): "YYYY-MM-DD HH:MM" on the store's own clock, or empty
| for Now and No End. The server reads it in the store's zone; nothing here converts it.
*/
export function sentMoment(input: MomentInput): string {
    return input.none || input.date === '' ? '' : `${input.date} ${input.time.trim()}`;
}
