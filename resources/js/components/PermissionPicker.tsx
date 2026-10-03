import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';

/*
| Choosing what a role allows (frontend.md §3.4 D3, and §3.3 C6 for one person).
|
| Actions are shown grouped by **business area** - "Staff and permissions", "Store settings and
| tax" - because that is how the people using this think about them, not in the order the modules
| happened to declare them. The area comes from the permission catalog itself (stage 2b, P2), so a
| module that ships later appears under its own heading without this component changing.
|
| An action the author does not hold is shown but cannot be ticked: nobody hands out what they do
| not have. It is shown rather than hidden so that an admin can see the shape of the whole system
| and understand why something is not theirs to give (access.md §1.5) - and, as Geist asks of every
| disabled checkbox, the reason is its tooltip rather than a greyed box that reads as a bug: the box
| stays reachable (aria-disabled), and ticking it does nothing.
|
| shadcn's parts, as its `field-checkbox` example puts them together: each area a Card holding a
| FieldSet whose legend is the area's name, so a screen reader hears the area before each action;
| the count beside it says "3 of 5 chosen" (Geist's Checkbox group); each action a horizontal Field
| - the box, its label, and "Every store, by its nature" as the label's description, not part of
| its name. The box's edge is ink-subtle, 3.12:1 on a card, where shadcn's input line is 1.59:1.
|
| Stores are not here at all. What a role allows and where a person may do it are two different
| questions, and the second is answered per staff member (§3.3 C6).
*/

export type PickerPermission = {
    name: string;
    label: string;
    group: string;
    storeFree: boolean;
    grantable: boolean;
};

export type PickerGroup = {
    key: string;
    label: string;
};

type Props = {
    permissions: PickerPermission[];
    groups: PickerGroup[];
    chosen: string[];
    onChange: (chosen: string[]) => void;
    /** Read-only: an admin looking at a role they may not change. */
    disabled?: boolean;
};

export function PermissionPicker({ permissions, groups, chosen, onChange, disabled = false }: Props) {
    const t = useTranslator();
    const held = new Set(chosen);

    function toggle(name: string, on: boolean) {
        onChange(on ? [...chosen, name] : chosen.filter((each) => each !== name));
    }

    function lockedBecause(permission: PickerPermission): string | undefined {
        if (disabled) {
            return t('access::roles.not_editable');
        }

        return permission.grantable ? undefined : t('access::roles.not_yours');
    }

    return (
        <div className="grid gap-6">
            {groups.map((group) => {
                const inGroup = permissions.filter((permission) => permission.group === group.key);

                // A business area no module has declared anything for yet simply is not shown,
                // rather than appearing as an empty heading.
                if (inGroup.length === 0) {
                    return null;
                }

                const chosenHere = inGroup.filter((permission) => held.has(permission.name)).length;

                return (
                    <Card key={group.key} className="material-base gap-0 border-0 py-0" data-test={`area-${group.key}`}>
                            <CardContent className="p-0">
                                {/* Named by its legend through aria-labelledby: the legend sits beside the count, inside
                                a row, and a fieldset takes its name only from a legend that is its own first child
                                (the review of batch A). */}
                                <FieldSet className="gap-0" aria-labelledby={`area-${group.key}-legend`}>
                                    <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                                        <FieldLegend id={`area-${group.key}-legend`} className="mb-0 text-heading-14 text-ink">
                                            {group.label}
                                        </FieldLegend>
                                        {chosenHere > 0 ? (
                                            <Badge className={tone('blue-subtle')}>
                                                <span className="tw-figure">{t('access::roles.chosen_count', { count: chosenHere, total: inGroup.length })}</span>
                                            </Badge>
                                        ) : null}
                                    </div>

                                    <FieldGroup className="gap-0.5 p-2">
                                        {inGroup.map((permission) => {
                                            const locked = lockedBecause(permission);
                                            const id = `permission-${permission.name}`;
                                            const box = (
                                                <Checkbox
                                                    id={id}
                                                    checked={held.has(permission.name)}
                                                    aria-disabled={locked === undefined ? undefined : true}
                                                    aria-describedby={permission.storeFree ? `${id}-description` : undefined}
                                                    onCheckedChange={(next) => (locked === undefined ? toggle(permission.name, next === true) : undefined)}
                                                    className="border-ink-subtle aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                                    data-test={id}
                                                />
                                            );

                                            return (
                                                <Field
                                                    key={permission.name}
                                                    orientation="horizontal"
                                                    // No hover paint on the row: it would promise a target bigger than the box and its label
                                                    // (the review of batch A).
                                                    className="px-2 py-2"
                                                >
                                                    {locked === undefined ? (
                                                        box
                                                    ) : (
                                                        // On a span, never on the box: both are Radix parts writing
                                                        // data-state, and the tooltip's would hide whether the box is
                                                        // ticked (lesson 133).
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <span className="inline-flex">{box}</span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{locked}</TooltipContent>
                                                        </Tooltip>
                                                    )}
                                                    <FieldContent className="gap-0.5">
                                                        <FieldLabel htmlFor={id} className={locked === undefined ? 'text-label-14 text-ink' : 'text-label-14 text-ink-subtle'}>
                                                            {permission.label}
                                                        </FieldLabel>
                                                        {permission.storeFree ? (
                                                            <FieldDescription id={`${id}-description`} className="text-copy-13 text-ink-muted">
                                                                {t('access::roles.store_free')}
                                                            </FieldDescription>
                                                        ) : null}
                                                    </FieldContent>
                                                </Field>
                                            );
                                        })}
                                    </FieldGroup>
                                </FieldSet>
                            </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
