import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Switch } from '@/components/Switch';
import { useTranslator } from '@/lib/t';
import type {
    AccountPage,
    NotificationSetting,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B4 - the notifications tab (frontend.md §3.2).
|
| Two switches per topic, each saving the moment it is flipped, with a small "Saved." toast
| (decided 2026-09-19). There is no Save button, so there is nothing to forget to press.
|
| The switches show the server's answer, never a guess. A switch that moved on the click and then
| had to move back because the save failed is worse than one that waits: the person walks away
| believing a setting that was never stored. Both switches of the topic being saved are disabled
| while the request is in flight, so a second click cannot race the first.
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
        <div className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
            <p className="text-sm text-ink-muted">{t('access::account.notifications_hint')}</p>

            <ul className="grid divide-y divide-line">
                {account.notifications.map((setting) => {
                    const topic = t(`access::account.topic.${setting.topic}`);
                    const busy = saving === setting.topic;

                    return (
                        <li
                            key={setting.topic}
                            className="flex flex-wrap items-center justify-between gap-4 py-4"
                        >
                            <span className="text-sm text-ink">{topic}</span>

                            <div className="flex items-center gap-6">
                                <label className="flex items-center gap-2 text-xs text-ink-muted">
                                    <span>{t('access::account.by_email')}</span>
                                    <Switch
                                        checked={setting.email}
                                        disabled={busy}
                                        data-test={`switch-email-${setting.topic}`}
                                        aria-label={t('access::account.switch_email_for', { topic })}
                                        onCheckedChange={(next) =>
                                            save(setting, next === true, setting.panel)
                                        }
                                    />
                                </label>

                                <label className="flex items-center gap-2 text-xs text-ink-muted">
                                    <span>{t('access::account.in_panel')}</span>
                                    <Switch
                                        checked={setting.panel}
                                        disabled={busy}
                                        data-test={`switch-panel-${setting.topic}`}
                                        aria-label={t('access::account.switch_panel_for', { topic })}
                                        onCheckedChange={(next) =>
                                            save(setting, setting.email, next === true)
                                        }
                                    />
                                </label>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
