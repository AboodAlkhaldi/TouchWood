import { useState } from 'react';
import { defaultFilter } from 'cmdk';
import { Check, ChevronsUpDown } from 'lucide-react';
import { cn } from 'cn';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldLegend, FieldSet, FieldTitle } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';

/*
| Which role somebody gets (frontend.md §3.3, C3 step 2 and C6): a saved role, taken as it is, or
| a role of their own. One component for the invitation and for one person's role, so the two never
| drift apart.
|
| Up to six saved roles, shadcn's `field-choice-card` (Geist's Choicebox: richer options than a
| plain radio): the group is a FieldSet whose legend is "Role" and whose description is the hint;
| each choice is a tile that is wholly its own target, with a Title Case title - the role's name,
| and "Edited" once its actions are changed here - and one line under it, which is the choice's
| description rather than part of its name (aria-describedby).
|
| Past six, Geist's Choicebox gives way to a picker one can search (owner, 2026-10-03): shadcn's
| `combobox-demo`, each saved role with its count, and "A Role of Their Own" still a card under it,
| since it is a different kind of choice rather than one more name in the list.
*/

export const OWN_ROLE = 'own';

/** Geist's Choicebox: at most four to six tiles, then a Select or a Combobox. */
const MOST_CARDS = 6;

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

    return (
        <FieldSet className="material-base gap-3 p-5" aria-describedby="role-hint" data-test="role-choice">
            <FieldLegend className="mb-0 text-heading-16 text-ink">{t('access::staff.pick_role')}</FieldLegend>
            <FieldDescription id="role-hint" className="text-copy-13 text-ink-muted">
                {t('access::staff.pick_role_hint')}
            </FieldDescription>

            {roles.length <= MOST_CARDS ? (
                <RadioGroup name="role" value={value ?? OWN_ROLE} onValueChange={(next) => onPick(next === OWN_ROLE ? null : next)} className="gap-2">
                    {roles.map((role) => (
                        <Card
                            key={role.id}
                            value={role.id}
                            title={role.name}
                            line={t('access::staff.actions_count', { count: role.permissionCount })}
                            figure
                            badge={value === role.id && edited ? t('access::staff.edited') : null}
                        />
                    ))}
                    <Card value={OWN_ROLE} title={t('access::staff.own_role')} line={t('access::staff.own_role_hint')} />
                </RadioGroup>
            ) : (
                <>
                    <SavedRolePicker roles={roles} value={value} edited={edited} onPick={onPick} />
                    {/* One card: choosing it clears the saved role above, and choosing a saved role
                        clears it. */}
                    <RadioGroup name="role" value={value === null ? OWN_ROLE : ''} onValueChange={() => onPick(null)} className="gap-2">
                        <Card value={OWN_ROLE} title={t('access::staff.own_role')} line={t('access::staff.own_role_hint')} />
                    </RadioGroup>
                </>
            )}
        </FieldSet>
    );
}

/** One choice tile (shadcn's field-choice-card). */
function Card({ value, title, line, figure = false, badge = null }: { value: string; title: string; line: string; figure?: boolean; badge?: string | null }) {
    const id = `role-${value}`;

    return (
        // The whole tile shows the keyboard's place, not only its dot (Geist's Choicebox).
        <FieldLabel
            htmlFor={id}
            className="has-[[data-state=checked]]:border-brand has-[[data-state=checked]]:bg-brand-soft/40 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
        >
            <Field orientation="horizontal">
                <FieldContent>
                    <FieldTitle id={`${id}-title`} className="text-label-14 text-ink">
                        {title}
                        {badge === null ? null : <Badge className={tone('amber-subtle')}>{badge}</Badge>}
                    </FieldTitle>
                    {/* A count is a figure (frontend.md §1.8); the own role's line is a sentence. */}
                    <FieldDescription id={`${id}-line`} className={figure ? 'tw-figure text-copy-13 text-ink-muted' : 'text-copy-13 text-ink-muted'}>
                        {line}
                    </FieldDescription>
                </FieldContent>
                <RadioGroupItem value={value} id={id} aria-labelledby={`${id}-title`} aria-describedby={`${id}-line`} className="border-ink-subtle" data-test={id} />
            </Field>
        </FieldLabel>
    );
}

/** The saved roles, past six of them: shadcn's combobox-demo, scored on the role's name only. */
function SavedRolePicker({ roles, value, edited, onPick }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const chosen = roles.find((role) => role.id === value) ?? null;

    return (
        <Field>
            <FieldLabel id="saved-role-label" htmlFor="saved-role">
                {t('access::staff.saved_role')}
            </FieldLabel>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id="saved-role"
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        // The field's name and then the role chosen: the label alone would hide it.
                        aria-labelledby="saved-role-label saved-role-value"
                        className="w-full justify-between font-normal"
                        data-test="saved-role"
                    >
                        {chosen === null ? (
                            <span id="saved-role-value" className="text-ink-muted">
                                {t('access::staff.choose_saved_role')}
                            </span>
                        ) : (
                            <span id="saved-role-value" className="flex min-w-0 items-center gap-2">
                                <span className="truncate">{chosen.name}</span>
                                <span className="tw-figure text-copy-13 text-ink-muted">{t('access::staff.actions_count', { count: chosen.permissionCount })}</span>
                                {edited ? <Badge className={tone('amber-subtle')}>{t('access::staff.edited')}</Badge> : null}
                            </span>
                        )}
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
                    <Command filter={(_value, search, keywords) => defaultFilter((keywords ?? []).join(' '), search)}>
                        <CommandInput placeholder={t('access::staff.role_search')} value={query} onValueChange={setQuery} className="h-9" />
                        <CommandList>
                            <CommandEmpty>{t('access::staff.role_none', { query })}</CommandEmpty>
                            <CommandGroup>
                                {roles.map((role) => (
                                    <CommandItem
                                        key={role.id}
                                        value={role.id}
                                        keywords={[role.name]}
                                        onSelect={() => {
                                            onPick(role.id);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                        data-test={`role-option-${role.id}`}
                                    >
                                        <span className="truncate">{role.name}</span>
                                        <span className="tw-figure ms-auto text-copy-13 text-ink-muted">{t('access::staff.actions_count', { count: role.permissionCount })}</span>
                                        <Check aria-hidden="true" className={cn(value === role.id ? 'opacity-100' : 'opacity-0')} />
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </Field>
    );
}
