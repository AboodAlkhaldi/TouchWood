import { router } from '@inertiajs/react';
import { SelectField } from '@/components/Fields';
import { NativeSelectOption } from '@/components/ui/native-select';
import { useTranslator } from '@/lib/t';

/*
| A screen's own store filter (frontend.md §2.2; the owner, 2026-10-06): the panel has no store
| "worked in" any more, so a screen that shows one store's data says which, in its own address
| (`?store=sa`). The server offers only the stores the person may use there - a Super Admin's off
| stores too, marked Off - and refuses any other; with one store there is nothing to choose and the
| filter is not drawn. The same native select as the Companies filter (SelectField).
*/

export type StoreFilterOption = { code: string; name: string; isActive: boolean };

type Props = {
    stores: StoreFilterOption[];
    /** The store shown; null for All Stores, where offered. */
    value: string | null;
    /** Offer All Stores first (Home's figures). */
    all?: boolean;
    className?: string;
};

export function StoreFilter({ stores, value, all = false, className }: Props) {
    const t = useTranslator();

    if (stores.length + (all ? 1 : 0) < 2) {
        return null;
    }

    const choose = (code: string) => {
        const params = new URLSearchParams(window.location.search);

        if (code === '') {
            params.delete('store');
        } else {
            params.set('store', code);
        }

        // Another store has its own pages: the list starts again at its first.
        params.delete('page');
        const query = params.toString();

        router.get(`${window.location.pathname}${query === '' ? '' : `?${query}`}`, {}, { preserveScroll: true });
    };

    return (
        <SelectField
            id="store-filter"
            label={t('admin.store.label')}
            value={value ?? ''}
            onChange={(event) => choose(event.target.value)}
            className={className}
            data-test="store-filter"
        >
            {all ? <NativeSelectOption value="">{t('admin.store.all')}</NativeSelectOption> : null}
            {stores.map((store) => (
                <NativeSelectOption key={store.code} value={store.code}>
                    {store.isActive ? store.name : t('admin.store.option_off', { store: store.name })}
                </NativeSelectOption>
            ))}
        </SelectField>
    );
}
