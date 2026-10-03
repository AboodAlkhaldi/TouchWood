import { Note } from '@/components/Note';
import type { PickerPermission } from '@/components/PermissionPicker';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Item, ItemActions, ItemContent, ItemFooter, ItemTitle } from '@/components/ui/item';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
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
|
| shadcn's parts (frontend.md §1.11): "every store or the ones ticked" is a RadioGroup - one choice
| of two - and the stores are its `field-checkbox` group, several from a list, where the whole label
| is the target. The group's name is the page's own section legend ("Where It Reaches"), passed in
| as `labelledBy`, so one group never carries two headings. Each choice is named apart (`name`),
| because the exceptions list puts one of these per action on the same page and two radio groups
| sharing a name would be one group to the browser.
|
| An action given stores of its own is an on/off choice, so it is a Switch named "Own Stores"
| (Geist's Toggle), and the stores it then reaches open under it. Boxes and switches have the
| ink-subtle edge, 3.12:1 on a card, where shadcn's input line is 1.59:1.
*/

export const ALL_STORES = 'ALL_STORES';
export const SELECTED_STORES = 'SELECTED_STORES';

/** `isActive` false: an off store, offered marked Off - kept, taken away or given while it is off (amendment 58(b)). */
export type StoreRow = { id: string; name: string; isActive?: boolean };
export type ExceptionRow = { permission: string; access_level: string; store_ids: string[] };

type ChoiceProps = {
    stores: StoreRow[];
    level: string;
    chosen: string[];
    onLevel: (level: string) => void;
    onChosen: (ids: string[]) => void;
    /** The id of what names this choice: the page's section legend, or an action's own name. */
    labelledBy: string;
    /** Unique on the page: it names the radio group and prefixes every control's id. */
    name?: string;
};

/** Every store, or the ones ticked — asked of the role, and of any action with stores of its own. */
export function StoreChoice({ stores, level, chosen, onLevel, onChosen, labelledBy, name = 'access_level' }: ChoiceProps) {
    const t = useTranslator();

    if (stores.length === 0) {
        // Geist's Note, for one gated part of a page that is otherwise usable.
        return <Note variant="secondary">{t('access::staff.no_stores_to_give')}</Note>;
    }

    return (
        <div className="grid gap-4">
            <RadioGroup name={name} value={level} onValueChange={onLevel} aria-labelledby={labelledBy} className="gap-2">
                {[
                    { value: ALL_STORES, label: t('access::staff.stores_all') },
                    { value: SELECTED_STORES, label: t('access::staff.stores_selected') },
                ].map((option) => (
                    <Field key={option.value} orientation="horizontal">
                        <RadioGroupItem value={option.value} id={`${name}-${option.value}`} className="border-ink-subtle" />
                        <FieldLabel htmlFor={`${name}-${option.value}`} className="text-label-14 font-normal text-ink">
                            {option.label}
                        </FieldLabel>
                    </Field>
                ))}
            </RadioGroup>

            {level === SELECTED_STORES ? (
                <FieldSet className="gap-2">
                    <FieldLegend variant="label" className="text-label-13 text-ink-muted">
                        {t('access::staff.stores_selected')}
                    </FieldLegend>
                    <FieldGroup data-slot="checkbox-group" className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                        {stores.map((store) => (
                            <Field key={store.id} orientation="horizontal" className="rounded-sm px-2 py-1.5 has-[label:hover]:bg-surface-sunken">
                                <Checkbox
                                    id={`${name}-store-${store.id}`}
                                    checked={chosen.includes(store.id)}
                                    onCheckedChange={(on) => onChosen(on === true ? [...chosen, store.id] : chosen.filter((id) => id !== store.id))}
                                    className="border-ink-subtle"
                                />
                                <FieldLabel htmlFor={`${name}-store-${store.id}`} className="text-label-14 font-normal text-ink">
                                    {store.name}
                                    {store.isActive === false ? <StoreOffBadge /> : null}
                                </FieldLabel>
                            </Field>
                        ))}
                    </FieldGroup>
                </FieldSet>
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
    const eligible = chosen
        .map((name) => byName.get(name))
        .filter((permission): permission is PickerPermission => permission !== undefined && !permission.storeFree);

    // Geist's Empty State in place of an empty list: nothing chosen works store by store.
    if (eligible.length === 0) {
        return (
            <Empty className="material-base p-6" data-test="exceptions-empty">
                <EmptyHeader>
                    <EmptyTitle className="text-heading-14">{t('access::staff.exceptions_none_title')}</EmptyTitle>
                    <EmptyDescription>{t('access::staff.exceptions_none')}</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <div role="list" className="grid gap-2">
            {eligible.map((permission) => {
                const name = permission.name;
                const row = rows.find((each) => each.permission === name);
                const id = `exception-${name}`;

                return (
                    <div key={name} role="listitem">
                        <Collapsible open={row !== undefined}>
                            <Item variant="outline" className="material-base border-0 px-4 py-3" data-test={id}>
                                <ItemContent>
                                    <ItemTitle id={`${id}-title`} className="text-label-14 text-ink">
                                        {permission.label}
                                    </ItemTitle>
                                </ItemContent>

                                <ItemActions>
                                    <Field orientation="horizontal" className="w-auto gap-2">
                                        <Switch
                                            id={`${id}-own`}
                                            checked={row !== undefined}
                                            aria-controls={`${id}-stores`}
                                            onCheckedChange={(on) =>
                                                onChange(
                                                    on
                                                        ? [...rows, { permission: name, access_level: SELECTED_STORES, store_ids: [] }]
                                                        : rows.filter((each) => each.permission !== name),
                                                )
                                            }
                                            className="data-[state=unchecked]:bg-ink-subtle"
                                        />
                                        <FieldLabel htmlFor={`${id}-own`} className="text-label-14 font-normal text-ink">
                                            {t('access::staff.exception')}
                                        </FieldLabel>
                                    </Field>
                                </ItemActions>

                                <CollapsibleContent asChild id={`${id}-stores`}>
                                    <ItemFooter className="pt-3">
                                        {row === undefined ? null : (
                                            <StoreChoice
                                                name={id}
                                                labelledBy={`${id}-title`}
                                                stores={stores}
                                                level={row.access_level}
                                                chosen={row.store_ids}
                                                onLevel={(level) => onChange(rows.map((each) => (each.permission === name ? { ...each, access_level: level } : each)))}
                                                onChosen={(ids) => onChange(rows.map((each) => (each.permission === name ? { ...each, store_ids: ids } : each)))}
                                            />
                                        )}
                                    </ItemFooter>
                                </CollapsibleContent>
                            </Item>
                        </Collapsible>
                    </div>
                );
            })}
        </div>
    );
}
