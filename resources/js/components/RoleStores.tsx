import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { PickerPermission } from '@/components/PermissionPicker';
import { useTranslator } from '@/lib/t';

/*
| Where a role reaches (frontend.md §3.3, C3 and C6).
|
| A role says what somebody may do; this says where. The same two pieces answer it for a new staff
| member and for an existing one, so they live here rather than twice.
|
| Only the stores the admin manages themselves are ever passed in: nobody hands out reach they do
| not have. An action that covers every store by its nature cannot be given stores of its own
| (amendment 4), so it is left out of the exceptions list.
*/

export const ALL_STORES = 'ALL_STORES';
export const SELECTED_STORES = 'SELECTED_STORES';

export type StoreRow = { id: string; name: string };
export type ExceptionRow = { permission: string; access_level: string; store_ids: string[] };

type ChoiceProps = {
    stores: StoreRow[];
    level: string;
    chosen: string[];
    onLevel: (level: string) => void;
    onChosen: (ids: string[]) => void;
};

/** Every store, or the ones ticked — asked of the role, and of any action with stores of its own. */
export function StoreChoice({ stores, level, chosen, onLevel, onChosen }: ChoiceProps) {
    const t = useTranslator();

    if (stores.length === 0) {
        return <p className="text-xs text-ink-muted">{t('access::staff.no_stores_to_give')}</p>;
    }

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-4 text-sm">
                {[ALL_STORES, SELECTED_STORES].map((each) => (
                    <label key={each} className="flex cursor-pointer items-center gap-2 text-ink">
                        <input
                            type="radio"
                            className="accent-brand"
                            checked={level === each}
                            onChange={() => onLevel(each)}
                        />
                        {t(each === ALL_STORES ? 'access::staff.stores_all' : 'access::staff.stores_selected')}
                    </label>
                ))}
            </div>

            {level === SELECTED_STORES ? (
                <ul className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                    {stores.map((store) => (
                        <li key={store.id}>
                            <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-ink hover:bg-surface-sunken">
                                <Checkbox
                                    checked={chosen.includes(store.id)}
                                    onCheckedChange={(on) =>
                                        onChosen(
                                            on === true ? [...chosen, store.id] : chosen.filter((id) => id !== store.id),
                                        )
                                    }
                                />
                                {store.name}
                            </label>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

type ExceptionsProps = {
    permissions: PickerPermission[];
    chosen: string[];
    stores: StoreRow[];
    rows: ExceptionRow[];
    onChange: (rows: ExceptionRow[]) => void;
};

/** The actions given stores of their own, apart from the rest of the role (access.md §1.5). */
export function ExceptionList({ permissions, chosen, stores, rows, onChange }: ExceptionsProps) {
    const t = useTranslator();
    const byName = new Map(permissions.map((permission) => [permission.name, permission]));

    return (
        <ul className="grid gap-2">
            {chosen.map((name) => {
                const permission = byName.get(name);

                if (permission === undefined || permission.storeFree) {
                    return null;
                }

                const row = rows.find((each) => each.permission === name);

                return (
                    <li key={name} className="grid gap-3 rounded-lg border border-line bg-surface px-4 py-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-sm text-ink">{permission.label}</span>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    onChange(
                                        row === undefined
                                            ? [...rows, { permission: name, access_level: SELECTED_STORES, store_ids: [] }]
                                            : rows.filter((each) => each.permission !== name),
                                    )
                                }
                            >
                                {t(row === undefined ? 'access::staff.give_own_stores' : 'access::staff.follow_the_role')}
                            </Button>
                        </div>

                        {row === undefined ? null : (
                            <StoreChoice
                                stores={stores}
                                level={row.access_level}
                                chosen={row.store_ids}
                                onLevel={(level) =>
                                    onChange(
                                        rows.map((each) =>
                                            each.permission === name ? { ...each, access_level: level } : each,
                                        ),
                                    )
                                }
                                onChosen={(ids) =>
                                    onChange(
                                        rows.map((each) =>
                                            each.permission === name ? { ...each, store_ids: ids } : each,
                                        ),
                                    )
                                }
                            />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
