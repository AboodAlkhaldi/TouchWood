import { Badge } from '@/components/ui/badge';
import { FieldContent, FieldDescription, FieldLabel, FieldLegend, FieldSet, FieldTitle, Field } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';

/*
| Which role somebody gets (frontend.md §3.3, C3 step 2 and C6): a saved role, taken as it is, or
| a role of their own. One component for the invitation and for one person's role, so the two never
| drift apart.
|
| shadcn's `field-choice-card` (Geist's Choicebox: richer options than a plain radio): the group
| is a FieldSet whose legend is "Role" and whose description is the hint; each choice is a tile
| that is wholly its own target, with a Title Case title - the role's name, and "Edited" once its
| actions are changed here - and one line under it. The line is the choice's description, not part
| of its name (aria-describedby), so a screen reader hears "Store Keeper, radio, 12 actions".
*/

export const OWN_ROLE = 'own';

export type RoleOption = { id: string; name: string; permissionCount: number };

type Props = {
    roles: RoleOption[];
    /** The saved role picked, or null for a role of their own. */
    value: string | null;
    /** True once the picked role's actions differ from the saved ones. */
    edited: boolean;
    onPick: (id: string | null) => void;
};

export function RoleChoice({ roles, value, edited, onPick }: Props) {
    const t = useTranslator();
    const options = [
        ...roles.map((role) => ({
            value: role.id,
            title: role.name,
            line: t('access::staff.actions_count', { count: role.permissionCount }),
            badge: value === role.id && edited,
            // A count is a figure (frontend.md §1.8); the own role's line is a sentence.
            figure: true,
        })),
        { value: OWN_ROLE, title: t('access::staff.own_role'), line: t('access::staff.own_role_hint'), badge: false, figure: false },
    ];

    return (
        <FieldSet className="material-base gap-3 p-5" data-test="role-choice">
            <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.pick_role')}</FieldLegend>
            <FieldDescription className="text-copy-13 text-ink-muted">{t('access::staff.pick_role_hint')}</FieldDescription>

            <RadioGroup name="role" value={value ?? OWN_ROLE} onValueChange={(next) => onPick(next === OWN_ROLE ? null : next)} className="gap-2">
                {options.map((option) => {
                    const id = `role-${option.value}`;

                    return (
                        <FieldLabel key={option.value} htmlFor={id} className="has-[[data-state=checked]]:border-brand has-[[data-state=checked]]:bg-brand-soft/40">
                            <Field orientation="horizontal">
                                <FieldContent>
                                    <FieldTitle id={`${id}-title`} className="text-label-14 text-ink">
                                        {option.title}
                                        {option.badge ? <Badge className={tone('amber-subtle')}>{t('access::staff.edited')}</Badge> : null}
                                    </FieldTitle>
                                    <FieldDescription id={`${id}-line`} className={option.figure ? 'tw-figure text-copy-13 text-ink-muted' : 'text-copy-13 text-ink-muted'}>
                                        {option.line}
                                    </FieldDescription>
                                </FieldContent>
                                <RadioGroupItem
                                    value={option.value}
                                    id={id}
                                    aria-labelledby={`${id}-title`}
                                    aria-describedby={`${id}-line`}
                                    className="border-ink-subtle"
                                    data-test={id}
                                />
                            </Field>
                        </FieldLabel>
                    );
                })}
            </RadioGroup>
        </FieldSet>
    );
}
