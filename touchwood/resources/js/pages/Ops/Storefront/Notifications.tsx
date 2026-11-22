import { usePage } from '@inertiajs/react';
import { BellPage } from '@/components/ops/BellPage';
import { AccountLayout } from '@/layouts/AccountLayout';
import type { InboxPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The shop's full page behind View All (ops.md §4.15 O2), in the account's frame and listed in its menu.
*/

export default function Notifications({ inbox }: { inbox: InboxPageData }) {
    const { frame, shop, locale } = usePage<SharedProps>().props;
    const words = frame?.ops?.words;

    if (words === undefined || words === null || shop === null || shop === undefined) {
        return null;
    }

    return (
        <AccountLayout title={words.title} page="ops.notifications">
            <BellPage inbox={inbox} base={`/${shop.code}/${locale}/account/notifications`} words={words} />
        </AccountLayout>
    );
}
