import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useTranslator } from '@/lib/t';
import { AddressesTab } from '@/pages/Access/Storefront/Account/AddressesTab';
import { CloseTab } from '@/pages/Access/Storefront/Account/CloseTab';
import { PhoneTab } from '@/pages/Access/Storefront/Account/PhoneTab';
import { ProfileTab } from '@/pages/Access/Storefront/Account/ProfileTab';
import { SecurityTab } from '@/pages/Access/Storefront/Account/SecurityTab';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F7 and F8 - a customer's own account (frontend.md §3.6).
|
| One screen with tabs, as the panel's is, because it is one person's account: Details, Password,
| Phone, Addresses, and closing it. It only ever shows the person looking at it - there is no id in
| any route behind it. The server keeps the same list (CustomerAccountTabs) and sends it with every
| shop page, so another module's account page can link back to these tabs.
|
| The open tab lives in the address (Geist's Tabs: reflect the active tab in the URL; frontend.md
| §1.11 "Applied in the rebuild"), so a refresh or a shared link opens the same tab, and the
| server's answer wins when it changes - which is exactly when a form has saved and sent the person
| back to the tab they were on (`?tab=phone`).
*/

type Props = CustomerAccountPage;

const TABS = ['profile', 'security', 'phone', 'addresses', 'close'] as const;

type Tab = (typeof TABS)[number];

function asTab(value: string): Tab {
    return TABS.find((tab) => tab === value) ?? 'profile';
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

        // Into the address without a visit, through Inertia, so its own record of the page - the
        // one Back and Forward bring back - says the same tab as the address (the batch B review).
        // Any other part of the address stays.
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        router.replace({ url: `${url.pathname}${url.search}${url.hash}`, props: (props) => ({ ...props, tab }), preserveState: true, preserveScroll: true });
    }

    return (
        <AccountLayout
            title={t('access::account.shop_title')}
            subtitle={t('access::account.shop_subtitle')}
            // The headings themselves arrive with every shop page (amendment 50), beside the pages
            // other modules add; this page only says which is open.
            tab={open}
            onTab={choose}
        >
            {open === 'profile' ? <ProfileTab account={account} /> : null}
            {open === 'security' ? <SecurityTab account={account} /> : null}
            {open === 'phone' ? <PhoneTab account={account} /> : null}
            {open === 'addresses' ? <AddressesTab account={account} /> : null}
            {open === 'close' ? <CloseTab account={account} /> : null}
        </AccountLayout>
    );
}
