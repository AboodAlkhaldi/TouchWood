import { useEffect, useState } from 'react';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useTranslator } from '@/lib/t';
import { PhoneTab } from '@/pages/Access/Storefront/Account/PhoneTab';
import { ProfileTab } from '@/pages/Access/Storefront/Account/ProfileTab';
import { SecurityTab } from '@/pages/Access/Storefront/Account/SecurityTab';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F7 and F8 - a customer's own account (frontend.md §3.6).
|
| One screen with tabs, as the panel's is, because it is one person's account: Details, Password,
| Phone. It only ever shows the person looking at it - there is no id in any route behind it.
|
| Addresses and closing the account are tabs of this same screen and arrive with F9 and F10. They
| are not listed here yet, because a heading that opens an empty panel is worse than one that is
| not there.
|
| Which tab is open is this browser's business while the person is here, and the server's when they
| arrive: a form that saved comes back to `?tab=phone` rather than dropping them at the top of the
| first tab, which reads as the page having forgotten what they were doing.
*/

type Props = CustomerAccountPage;

const TABS = ['profile', 'security', 'phone'] as const;

type Tab = (typeof TABS)[number];

function asTab(value: string): Tab {
    return TABS.find((tab) => tab === value) ?? 'profile';
}

export default function Account(account: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState<Tab>(() => asTab(account.tab));

    // The server's answer wins whenever it changes - which is exactly when a form has saved and
    // sent the person back to the tab they were on. Keyed on the string, not on the props object,
    // so an unrelated re-render does not drag them back to a tab they have since left.
    useEffect(() => setOpen(asTab(account.tab)), [account.tab]);

    return (
        <AccountLayout
            title={t('access::account.shop_title')}
            subtitle={t('access::account.shop_subtitle')}
            sections={TABS.map((tab) => ({ key: tab, label: t(`access::account.shop_tab.${tab}`) }))}
            open={open}
            onOpen={setOpen}
        >
            {open === 'profile' ? <ProfileTab account={account} /> : null}
            {open === 'security' ? <SecurityTab account={account} /> : null}
            {open === 'phone' ? <PhoneTab account={account} /> : null}
        </AccountLayout>
    );
}
