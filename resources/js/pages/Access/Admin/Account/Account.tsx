import { useEffect, useState } from 'react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { NotificationsTab } from '@/pages/Access/Admin/Account/NotificationsTab';
import { ProfileTab } from '@/pages/Access/Admin/Account/ProfileTab';
import { SecurityTab } from '@/pages/Access/Admin/Account/SecurityTab';
import { SessionsTab } from '@/pages/Access/Admin/Account/SessionsTab';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1-B4 - "Account & settings" (frontend.md §3.2).
|
| One screen with four tabs, because it is one person's account: Account, Security, Sessions,
| Notifications. Every staff member has it, and it only ever shows the person looking at it - there
| is no id in any route behind it.
|
| shadcn's Tabs, its `line` look (frontend.md §1.11; Geist's Tabs: a Title Case noun each, the open
| one underlined in ink): Radix gives the arrow keys, the roving focus and the roles a screen reader
| needs, which the hand-made buttons before did not. The account arrives in one payload, so pressing
| a tab costs no round trip.
|
| The open tab lives in the address (Geist's Tabs: reflect the active tab in the URL; §1.11 "Applied
| in the rebuild"), so a refresh or a shared link opens the same tab, and the server's answer wins
| when it changes - which is exactly when a form has saved and sent the person back to the tab they
| were on (`?tab=security`).
*/

type Props = AccountPage;

const TABS = ['account', 'security', 'sessions', 'notifications'] as const;

type Tab = (typeof TABS)[number];

function asTab(value: string): Tab {
    return TABS.find((tab) => tab === value) ?? 'account';
}

export default function Account(account: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState<Tab>(() => asTab(account.tab));

    // Keyed on the string, not on the props object, so an unrelated re-render does not drag the
    // person back to a tab they have since left.
    useEffect(() => setOpen(asTab(account.tab)), [account.tab]);

    function choose(value: string) {
        const tab = asTab(value);
        setOpen(tab);

        // Into the address without a visit: Inertia's own record of the page is kept as it is.
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState(window.history.state, '', url.toString());
    }

    return (
        <AdminLayout title={t('access::account.title')} subtitle={t('access::account.subtitle')}>
            <Tabs value={open} onValueChange={choose} className="gap-6">
                <TabsList variant="line" className="h-10 w-full justify-start overflow-x-auto border-b border-line p-0" aria-label={t('access::account.title')}>
                    {TABS.map((tab) => (
                        <TabsTrigger key={tab} value={tab} data-test={`tab-${tab}`} className="flex-none px-3 text-label-14">
                            {t(`access::account.tab.${tab}`)}
                        </TabsTrigger>
                    ))}
                </TabsList>

                <TabsContent value="account">
                    <ProfileTab account={account} />
                </TabsContent>
                <TabsContent value="security">
                    <SecurityTab account={account} />
                </TabsContent>
                <TabsContent value="sessions">
                    <SessionsTab account={account} />
                </TabsContent>
                <TabsContent value="notifications">
                    <NotificationsTab account={account} />
                </TabsContent>
            </Tabs>
        </AdminLayout>
    );
}
