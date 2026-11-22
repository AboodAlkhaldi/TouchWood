/*
| A batch refusal's rows (pricing.md §4.3.1, Q2): a command that saves many sizes at once refuses them
| all, and names the sizes it is about. `App\Http\FormErrors` passes each to the page as
| `errors["rows.{size id}"]`, beside `errors.form` - flat keys, as Inertia hands a page one message a
| key. This reads them back as size id => message, so each marked row says its message under its box
| and the focus can go to the first.
*/

const PREFIX = 'rows.';

export function rowErrors(errors: Partial<Record<string, string>>): Record<string, string> {
    const rows: Record<string, string> = {};

    for (const [key, message] of Object.entries(errors)) {
        if (key.startsWith(PREFIX) && key.length > PREFIX.length && typeof message === 'string' && message !== '') {
            rows[key.slice(PREFIX.length)] = message;
        }
    }

    return rows;
}

/** The first of these rows, in the page's order, that the refusal marks - where the focus goes. */
export function firstMarked<T>(rows: T[], id: (row: T) => string, marked: Record<string, string>): T | undefined {
    return rows.find((row) => marked[id(row)] !== undefined);
}
