import { useEffect, useState } from 'react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import { NotificationsTab } from '@/pages/Access/Admin/Account/NotificationsTab';
import { ProfileTab } from '@/pages/Access/Admin/Account/ProfileTab';
import { SecurityTab } from '@/pages/Access/Admin/Account/SecurityTab';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1-B4 - "Account & settings" (frontend.md §3.2).
|
| One screen with three tabs, because it is one person's account: Account, Security, Notifications.
| Every staff member has it, and it only ever shows the person looking at it - there is no id in any
| route behind it.
|
| Which tab is open is this browser's business while the person is here, and the server's when they
| arrive: a form that saved comes back to `?tab=security` rather than dropping them at the top of the
| first tab, which reads as the page having forgotten what they were doing.
|
| The tabs are three buttons and a panel rather than a component with a state machine. They carry
| the roles a screen reader needs, arrow keys are not intercepted, and there is nothing to render
| differently on the server than in the browser.
*/

type Props = AccountPage;

const TABS = ['account', 'security', 'notifications'] as const;

type Tab = (typeof TABS)[number];

function asTab(value: string): Tab {
    return TABS.find((tab) => tab === value) ?? 'account';
}

export default function Account(account: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState<Tab>(() => asTab(account.tab));

    // The server's answer wins whenever it changes - which is exactly when a form has saved and
    // sent the person back to the tab they were on. Keyed on the string, not on the props object,
    // so an unrelated re-render does not drag them back to a tab they have since left.
    useEffect(() => setOpen(asTab(account.tab)), [account.tab]);

    return (
        <AdminLayout title={t('access::account.title')} subtitle={t('access::account.subtitle')}>
            <div className="grid gap-6">
                <div role="tablist" className="flex flex-wrap gap-1 border-b border-line">
                    {TABS.map((tab) => (
                        <button
                            key={tab}
                            type="button"
                            role="tab"
                            id={`tab-${tab}`}
                            aria-selected={open === tab}
                            aria-controls={`panel-${tab}`}
                            data-test={`tab-${tab}`}
                            onClick={() => setOpen(tab)}
                            className={[
                                '-mb-px border-b-2 px-4 py-2 text-sm transition-colors',
                                open === tab
                                    ? 'border-brand text-brand'
                                    : 'border-transparent text-ink-muted hover:text-ink',
                            ].join(' ')}
                        >
                            {t(`access::account.tab.${tab}`)}
                        </button>
                    ))}
                </div>

                <div role="tabpanel" id={`panel-${open}`} aria-labelledby={`tab-${open}`}>
                    {open === 'account' ? <ProfileTab account={account} /> : null}
                    {open === 'security' ? <SecurityTab account={account} /> : null}
                    {open === 'notifications' ? <NotificationsTab account={account} /> : null}
                </div>
            </div>
        </AdminLayout>
    );
}
