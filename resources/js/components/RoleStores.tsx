import { Note } from '@/components/Note';
import type { PickerGroup, PickerPermission } from '@/components/PermissionPicker';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useList } from '@/lib/list';
import { useTranslator } from '@/lib/t';

/*
| Where a role reaches (frontend.md §3.3, C3 and C6; access.md §1.5, amendment 59).
|
| A role says what somebody may do; this says where. The same pieces answer it for a new staff
| member and for an existing one, so they live here rather than twice.
|
| **Where It Reaches bounds every action** (the owner's rule, 2026-10-04). With two or more stores,
| or every store, each per-store action is "All Selected Stores" or "Custom", ticked among the
| reach's stores only; with one store there is nothing to choose, so the second section is not
| shown. A smaller reach cuts every custom choice to it and says so on the action, before saving; a
| custom choice left with nothing stops the save, naming the action (D9). The server refuses
| anything outside the reach on its own (ActionStoresBeyondReach).
|
| Only the stores the admin manages themselves are ever passed in: nobody hands out reach they do
| not have. An action that covers every store by its nature takes no stores (amendment 4).
|
| shadcn's parts (frontend.md §1.11), boxed as the Role section above them is: a FieldSet named by
| its legend, its hint as the description. "Every store or the ones ticked" and "All Selected
| Stores or Custom" are each a RadioGroup - one choice of two -, and stores are a `field-checkbox`
| group, the whole label the target. An action's stores wrap in a row rather than in fixed columns,
| so a long name never runs over its box. Boxes and radios have the ink-subtle edge, 3.12:1 on a
| card, where shadcn's input line is 1.59:1.
*/

export const ALL_STORES = 'ALL_STORES';
export const SELECTED_STORES = 'SELECTED_STORES';

const ALL = 'all';
const CUSTOM = 'custom';

/** `isActive` false: an off store, offered marked Off - kept, taken away or given while it is off (amendment 58(b)). */
export type StoreRow = { id: string; name: string; isActive?: boolean };
/** One action with Custom stores: always some of the reach's stores (amendment 59). */
export type ExceptionRow = { permission: string; access_level: string; store_ids: string[] };

/** The reach's stores, in the stores' own order: every store the author may give, or the ones ticked. */
export function reachStores(stores: StoreRow[], level: string, chosen: string[]): StoreRow[] {
    return level === ALL_STORES ? stores : stores.filter((store) => chosen.includes(store.id));
}

/** Whether an action may be given Custom stores: a reach of two or more stores, or every store. */
export function reachAllowsCustom(level: string, chosen: string[]): boolean {
    return level === ALL_STORES || chosen.length >= 2;
}

/**
 * Every custom choice as the reach now allows it, and what each has lost to it (D9: shown on the
 * action before saving, never widened behind anyone's back).
 *
 * Worked out afresh from the choices as made, never written over them: a store ticked back into
 * the reach comes back to the actions that had it, and a store passing through on the way to
 * another reach (every store, then none ticked yet, then three) costs nothing (the review of P4).
 */
export function withinReach(rows: ExceptionRow[], level: string, chosen: string[]): { rows: ExceptionRow[]; lost: Record<string, string[]> } {
    if (level === ALL_STORES) {
        return { rows, lost: {} };
    }

    const lost: Record<string, string[]> = {};

    return {
        rows: rows.map((row) => {
            const outside = row.store_ids.filter((id) => !chosen.includes(id));

            if (outside.length === 0) {
                return row;
            }

            lost[row.permission] = outside;

            return { ...row, store_ids: row.store_ids.filter((id) => chosen.includes(id)) };
        }),
        lost,
    };
}

/** One action set to All Selected Stores (null) or to Custom stores, the rest left as they were made. */
export function setActionStores(rows: ExceptionRow[], permission: string, storeIds: string[] | null): ExceptionRow[] {
    const others = rows.filter((row) => row.permission !== permission);

    return storeIds === null ? others : [...others, { permission, access_level: SELECTED_STORES, store_ids: storeIds }];
}

/** The reach as it is sent: "every store" takes no list, so the ticks kept for going back stay here. */
export function reachToSend(level: string, chosen: string[]): string[] {
    return level === ALL_STORES ? [] : chosen;
}

/** The actions with Custom stores and not one store ticked: the save stops, naming them. */
export function emptyCustom(rows: ExceptionRow[], chosenActions: string[]): string[] {
    return rows.filter((row) => chosenActions.includes(row.permission) && row.store_ids.length === 0).map((row) => row.permission);
}

type ChoiceProps = {
    stores: StoreRow[];
    level: string;
    chosen: string[];
    onLevel: (level: string) => void;
    onChosen: (ids: string[]) => void;
};

/** Where It Reaches: every store, or the ones ticked - one box, as the Role section is. */
export function WhereItReaches({ stores, level, chosen, onLevel, onChosen }: ChoiceProps) {
    const t = useTranslator();

    return (
        <FieldSet className="material-base gap-3 p-5" aria-describedby="where-hint" data-test="where">
            <FieldLegend id="where" className="mb-0 text-heading-16 text-ink">
                {t('access::staff.where')}
            </FieldLegend>
            <FieldDescription id="where-hint" className="text-copy-13 text-ink-muted">
                {t('access::staff.where_hint')}
            </FieldDescription>

            {stores.length === 0 ? (
                // Geist's Note, for one gated part of a page that is otherwise usable.
                <Note variant="secondary">{t('access::staff.no_stores_to_give')}</Note>
            ) : (
                <div className="grid gap-4">
                    <RadioGroup name="access_level" value={level} onValueChange={onLevel} aria-labelledby="where" className="gap-2">
                        {[
                            { value: ALL_STORES, label: t('access::staff.stores_all') },
                            { value: SELECTED_STORES, label: t('access::staff.stores_selected') },
                        ].map((option) => (
                            <Field key={option.value} orientation="horizontal">
                                <RadioGroupItem value={option.value} id={`access_level-${option.value}`} className="border-ink-subtle" />
                                <FieldLabel htmlFor={`access_level-${option.value}`} className="text-label-14 font-normal text-ink">
                                    {option.label}
                                </FieldLabel>
                            </Field>
                        ))}
                    </RadioGroup>

                    {level === SELECTED_STORES ? (
                        <StoreBoxes name="access_level" legend={t('access::staff.stores_selected')} stores={stores} chosen={chosen} onChosen={onChosen} />
                    ) : null}
                </div>
            )}
        </FieldSet>
    );
}

/** Stores to tick, wrapping in a row; the legend is for a screen reader, the choice above says it. */
function StoreBoxes({ name, legend, stores, chosen, onChosen }: { name: string; legend: string; stores: StoreRow[]; chosen: string[]; onChosen: (ids: string[]) => void }) {
    return (
        <FieldSet className="gap-0">
            <FieldLegend className="sr-only">{legend}</FieldLegend>
            <FieldGroup data-slot="checkbox-group" className="flex flex-row flex-wrap gap-x-6 gap-y-2">
                {stores.map((store) => (
                    <Field key={store.id} orientation="horizontal" className="w-auto">
                        <Checkbox
                            id={`${name}-store-${store.id}`}
                            checked={chosen.includes(store.id)}
                            onCheckedChange={(on) => onChosen(on === true ? [...chosen, store.id] : chosen.filter((id) => id !== store.id))}
                            className="border-ink-subtle"
                        />
                        <FieldLabel htmlFor={`${name}-store-${store.id}`} className="text-label-14 font-normal whitespace-nowrap text-ink">
                            {store.name}
                            {store.isActive === false ? <StoreOffBadge /> : null}
                        </FieldLabel>
                    </Field>
                ))}
            </FieldGroup>
        </FieldSet>
    );
}

type ActionStoresProps = {
    permissions: PickerPermission[];
    groups: PickerGroup[];
    /** The actions ticked in the role. */
    chosen: string[];
    /** The reach's stores: what Custom may tick. */
    stores: StoreRow[];
    /** Every store offered on the page, to name a store the reach has since lost. */
    offered: StoreRow[];
    /** The custom choices as the reach allows them now (withinReach). */
    rows: ExceptionRow[];
    /** One action set: to All Selected Stores (null), or to these Custom stores. */
    onSet: (permission: string, storeIds: string[] | null) => void;
    /** What a smaller reach took out of each action's custom stores, by store id. */
    lost: Record<string, string[]>;
    /** The actions the last Save stopped on: Custom with nothing ticked. */
    empty: string[];
    /**
     * Only these actions: with one store in the reach there is nothing to choose, and the list is
     * shown only for the actions a smaller reach left with nothing, to be put right.
     */
    only?: string[];
};

/** Each per-store action's stores: All Selected Stores, or Custom among the reach's (amendment 59). */
export function ActionStores({ permissions, groups, chosen, stores, offered, rows, onSet, lost, empty, only }: ActionStoresProps) {
    const t = useTranslator();
    const list = useList();
    const byName = new Map(permissions.map((permission) => [permission.name, permission]));
    const eligible = chosen
        .filter((name) => only === undefined || only.includes(name))
        .map((name) => byName.get(name))
        .filter((permission): permission is PickerPermission => permission !== undefined && !permission.storeFree);
    // A store taken out is no longer among the reach's, so it is named from every store offered.
    const nameOf = (id: string): string => offered.find((store) => store.id === id)?.name ?? '';

    return (
        <FieldSet className="material-base gap-3 p-5" aria-describedby="action-stores-hint" data-test="action-stores">
            <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.exceptions_title')}</FieldLegend>
            <FieldDescription id="action-stores-hint" className="text-copy-13 text-ink-muted">
                {t('access::staff.exceptions_hint')}
            </FieldDescription>

            {eligible.length === 0 ? (
                // Geist's Empty State in place of an empty list: nothing chosen works store by store.
                <Empty className="border border-line p-6" data-test="exceptions-empty">
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-14">{t('access::staff.exceptions_none_title')}</EmptyTitle>
                        <EmptyDescription>{t('access::staff.exceptions_none')}</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <div className="grid gap-4">
                    {groups.map((group) => {
                        const inGroup = eligible.filter((permission) => permission.group === group.key);

                        if (inGroup.length === 0) {
                            return null;
                        }

                        return (
                            <div key={group.key} className="grid gap-1.5">
                                <p className="text-label-13 text-ink-muted">{group.label}</p>
                                <div role="list" className="divide-y divide-line rounded-md border border-line">
                                    {inGroup.map((permission) => {
                                        const row = rows.find((each) => each.permission === permission.name);
                                        const id = `exception-${permission.name}`;
                                        const taken = (lost[permission.name] ?? []).map(nameOf).filter((name) => name !== '');
                                        const stopped = empty.includes(permission.name) && row !== undefined && row.store_ids.length === 0;

                                        return (
                                            <div key={permission.name} role="listitem" className="grid gap-3 px-4 py-3" data-test={id}>
                                                <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                                                    <span id={`${id}-title`} className="text-label-14 text-ink">
                                                        {permission.label}
                                                    </span>
                                                    <RadioGroup
                                                        name={id}
                                                        value={row === undefined ? ALL : CUSTOM}
                                                        onValueChange={(value) => onSet(permission.name, value === CUSTOM ? [] : null)}
                                                        aria-labelledby={`${id}-title`}
                                                        // Said once, at the top of the form; here it is tied to the action it is about.
                                                        aria-describedby={stopped ? `${id}-empty` : undefined}
                                                        aria-invalid={stopped || undefined}
                                                        className="flex flex-wrap gap-x-5 gap-y-2"
                                                    >
                                                        {[
                                                            { value: ALL, label: t('access::staff.exceptions_all') },
                                                            { value: CUSTOM, label: t('access::staff.exceptions_custom') },
                                                        ].map((option) => (
                                                            <Field key={option.value} orientation="horizontal" className="w-auto">
                                                                <RadioGroupItem value={option.value} id={`${id}-${option.value}`} className="border-ink-subtle" data-test={`${id}-${option.value}`} />
                                                                <FieldLabel htmlFor={`${id}-${option.value}`} className="text-label-14 font-normal whitespace-nowrap text-ink">
                                                                    {option.label}
                                                                </FieldLabel>
                                                            </Field>
                                                        ))}
                                                    </RadioGroup>
                                                </div>

                                                {row === undefined ? null : (
                                                    <StoreBoxes
                                                        name={id}
                                                        legend={t('access::staff.exceptions_custom_stores', { action: permission.label })}
                                                        stores={stores}
                                                        chosen={row.store_ids}
                                                        onChosen={(ids) => onSet(permission.name, ids)}
                                                    />
                                                )}

                                                {row !== undefined && taken.length > 0 ? (
                                                    <p className="text-copy-13 text-ink-muted" data-test={`${id}-cut`}>
                                                        {t('access::staff.exceptions_cut', { stores: list(taken) })}
                                                    </p>
                                                ) : null}

                                                {/* Not an alert of its own: the Note at the top of the form says it once (the review of P4). */}
                                                {stopped ? (
                                                    <p id={`${id}-empty`} className="text-copy-13 text-bad" data-test={`${id}-empty`}>
                                                        {t('access::staff.exceptions_emptied')}
                                                    </p>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </FieldSet>
    );
}
