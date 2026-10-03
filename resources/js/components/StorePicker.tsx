import { router, usePage } from '@inertiajs/react';
import { Select } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| Which store the panel is working in (frontend.md §2.2), as Geist's Select (1.10).
|
| It never shows a store outside this person's stores - the list came from the server, which asked
| the authorizer, not from anything the browser knows. One store means a name, with no menu to open:
| there is nothing to choose. The choice is remembered on the account, so the next sign-in, on any
| device, opens where they left off.
|
| When the store they had chosen is no longer theirs, the server already opened them somewhere else.
| Saying so is the toast's job, not this one's - it is a message about the last request, and it is
| rendered on the server with the rest of the page.
*/

export function StorePicker() {
    const { store } = usePage<SharedProps>().props;
    const t = useTranslator();

    if (store === null || store.current === null) {
        return null;
    }

    if (store.available.length < 2) {
        return <span className="text-label-14 font-medium text-ink">{store.current.name}</span>;
    }

    return (
        <Select
            id="current-store"
            size="small"
            aria-label={t('admin.store.label')}
            value={store.current.id}
            onChange={(event) => router.post('/admin/current-store', { store: event.target.value }, { preserveScroll: true })}
            className="w-44"
        >
            {store.available.map((one) => (
                <option key={one.id} value={one.id}>
                    {one.name}
                </option>
            ))}
        </Select>
    );
}
