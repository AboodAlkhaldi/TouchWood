import { useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { AccountHeading, AccountMenu, AccountPageLinks } from '@/layouts/AccountLayout';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { SharedProps } from '@/types/page';

/*
| The account page's own frame (frontend.md §3.6 F8): AccountLayout's list and heading, with the
| account's headings as shadcn's Tabs, upright, in their `line` look. Only this page draws them, so
| only it downloads them (AccountLayout says why the two are apart).
*/

type Props = {
    title: string;
    subtitle?: string;
    /** The tab open, and how to open another. */
    tab: string;
    onTab: (key: string) => void;
    children: ReactNode;
};

export function AccountTabsLayout({ title, subtitle, tab, onTab, children }: Props) {
    const { accountMenu } = usePage<SharedProps>().props;
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <StorefrontLayout title={title}>
            <Tabs
                orientation="vertical"
                value={tab}
                onValueChange={(key) => {
                    // A tab chosen from the folded menu shuts it, so its panel is what the phone shows.
                    setMenuOpen(false);
                    onTab(key);
                }}
                className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]"
            >
                <AccountMenu label={title} open={menuOpen} onOpenChange={setMenuOpen}>
                    <AccountPageLinks />
                    <TabsList variant="line" aria-label={title} className="w-full items-stretch">
                        {(accountMenu?.tabs ?? []).map((each) => (
                            <TabsTrigger key={each.key} value={each.key} data-test={`tab-${each.key}`} className="h-9 flex-none px-2 text-label-14">
                                {each.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </AccountMenu>

                <TabsContent value={tab} className="grid content-start gap-5">
                    <AccountHeading title={title} subtitle={subtitle} />
                    {children}
                </TabsContent>
            </Tabs>
        </StorefrontLayout>
    );
}
