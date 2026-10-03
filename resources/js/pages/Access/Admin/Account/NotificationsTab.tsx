import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslator } from '@/lib/t';
import type { AccountPage, NotificationSetting } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B4 - the notifications tab (frontend.md §3.2), on shadcn's parts with Geist's rules (§1.11).
|
| Two switches per topic, each saving the moment it is flipped, with a small "Saved." toast
| (decided 2026-09-19; Geist's Toggle). There is no Save button, so there is nothing to forget to
| press.
|
| Each topic is a FieldSet whose legend is the topic, so a screen reader hears the topic with each
| switch, and the visible word is the switch's name. Before, each switch had an aria-label that
| overrode the word on the screen, which Geist's Toggle forbids (the batch B audit).
|
| The switches show the server's answer, never a guess. A switch that moved on the click and then
| had to move back because the save failed is worse than one that waits: the person walks away
| believing a setting that was never stored. Both switches of the topic being saved are out of reach
| while the request is in flight - still focusable, and saying why, as Geist asks of anything
| disabled - so a second click cannot race the first.
|
| The topics come from the server in the order the enum declares them, so the list does not
| rearrange itself according to what somebody has switched on.
*/

type Props = {
    account: AccountPage;
};

export function NotificationsTab({ account }: Props) {
    const t = useTranslator();
    const [saving, setSaving] = useState<string | null>(null);

    function save(setting: NotificationSetting, email: boolean, panel: boolean) {
        setSaving(setting.topic);

        router.post('/admin/account/notifications', { topic: setting.topic, email, panel }, { preserveScroll: true, preserveState: true, onFinish: () => setSaving(null) });
    }

    return (
        <Card className="material-base gap-3 border-0 py-5">
            <CardHeader className="px-6">
                <CardTitle className="text-heading-20 text-ink">
                    <h2>{t('access::account.tab.notifications')}</h2>
                </CardTitle>
                <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.notifications_hint')}</CardDescription>
            </CardHeader>

            <CardContent className="grid divide-y divide-line px-6">
                {account.notifications.map((setting) => {
                    const busy = saving === setting.topic ? t('access::account.saving_reason') : undefined;

                    return (
                        // The topic over its two switches: a fieldset's legend is never a flex
                        // item, so the row cannot put them side by side (the batch B review).
                        <FieldSet key={setting.topic} className="gap-3 py-4">
                            <FieldLegend variant="label" className="mb-0 text-label-14 text-ink">
                                {t(`access::account.topic.${setting.topic}`)}
                            </FieldLegend>

                            <div className="flex items-center gap-6">
                                <TopicSwitch
                                    id={`email-${setting.topic}`}
                                    label={t('access::account.by_email')}
                                    checked={setting.email}
                                    busy={busy}
                                    test={`switch-email-${setting.topic}`}
                                    onChange={(next) => save(setting, next, setting.panel)}
                                />
                                <TopicSwitch
                                    id={`panel-${setting.topic}`}
                                    label={t('access::account.in_panel')}
                                    checked={setting.panel}
                                    busy={busy}
                                    test={`switch-panel-${setting.topic}`}
                                    onChange={(next) => save(setting, setting.email, next)}
                                />
                            </div>
                        </FieldSet>
                    );
                })}
            </CardContent>
        </Card>
    );
}

/**
 * One switch and its word. While its topic saves it stays reachable but does nothing, and its
 * tooltip says why (aria-disabled rather than disabled, as Geist asks of a disabled control). The
 * tooltip hangs on a span around the switch, never on the switch itself: both are Radix parts that
 * write data-state, and the tooltip's would hide whether the switch is on (lesson 133). So the
 * reason is also written for a screen reader beside the switch and tied to it, since the tooltip
 * describes the span, which never takes focus (the batch B review). The tooltip is always
 * controlled, shut while there is nothing to say.
 */
function TopicSwitch({ id, label, checked, busy, test, onChange }: { id: string; label: string; checked: boolean; busy?: string; test: string; onChange: (next: boolean) => void }) {
    const [tip, setTip] = useState(false);

    return (
        <Field orientation="horizontal" className="w-auto gap-2">
            <Tooltip open={busy !== undefined && tip} onOpenChange={setTip}>
                <TooltipTrigger asChild>
                    <span className="inline-flex">
                        <Switch
                            id={id}
                            checked={checked}
                            aria-disabled={busy === undefined ? undefined : true}
                            aria-describedby={busy === undefined ? undefined : `${id}-reason`}
                            onCheckedChange={(next) => (busy === undefined ? onChange(next) : undefined)}
                            className="data-[state=unchecked]:bg-ink-subtle aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            data-test={test}
                        />
                    </span>
                </TooltipTrigger>
                {busy === undefined ? null : <TooltipContent>{busy}</TooltipContent>}
            </Tooltip>
            {busy === undefined ? null : (
                <span id={`${id}-reason`} className="sr-only">
                    {busy}
                </span>
            )}
            <FieldLabel htmlFor={id} className="text-label-14 font-normal text-ink">
                {label}
            </FieldLabel>
        </Field>
    );
}
