import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Toggle } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type {
    AccountPage,
    NotificationSetting,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B4 - the notifications tab (frontend.md §3.2), in Geist (1.10).
|
| Two Geist Toggles per topic, each saving the moment it is flipped, with a small "Saved." toast
| (decided 2026-09-19; Geist's own rule for a Toggle). There is no Save button, so there is nothing
| to forget to press.
|
| The toggles show the server's answer, never a guess. A toggle that moved on the click and then
| had to move back because the save failed is worse than one that waits: the person walks away
| believing a setting that was never stored. Both toggles of the topic being saved are out of reach
| while the request is in flight - and say so, as Geist asks of anything disabled - so a second
| click cannot race the first.
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

        router.post(
            '/admin/account/notifications',
            { topic: setting.topic, email, panel },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(null),
            },
        );
    }

    return (
        <section className="material-base grid gap-4 p-5 sm:p-6">
            <p className="text-copy-14 text-ink-muted">{t('access::account.notifications_hint')}</p>

            <ul className="grid divide-y divide-line">
                {account.notifications.map((setting) => {
                    const topic = t(`access::account.topic.${setting.topic}`);
                    const busy = saving === setting.topic ? t('access::account.saving_reason') : undefined;

                    return (
                        <li
                            key={setting.topic}
                            className="flex flex-wrap items-center justify-between gap-4 py-4"
                        >
                            <span className="text-label-14 text-ink">{topic}</span>

                            <div className="flex items-center gap-6">
                                {/* The visible word is short ("Email"); the name a screen reader
                                    hears carries the topic, because "Email" alone, out of the row,
                                    says nothing about what it switches. */}
                                <Toggle
                                    id={`email-${setting.topic}`}
                                    checked={setting.email}
                                    disabledReason={busy}
                                    data-test={`switch-email-${setting.topic}`}
                                    aria-label={t('access::account.switch_email_for', { topic })}
                                    onChange={(next) => save(setting, next, setting.panel)}
                                >
                                    {t('access::account.by_email')}
                                </Toggle>

                                <Toggle
                                    id={`panel-${setting.topic}`}
                                    checked={setting.panel}
                                    disabledReason={busy}
                                    data-test={`switch-panel-${setting.topic}`}
                                    aria-label={t('access::account.switch_panel_for', { topic })}
                                    onChange={(next) => save(setting, setting.email, next)}
                                >
                                    {t('access::account.in_panel')}
                                </Toggle>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
