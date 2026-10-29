import { useRef, useState, type ReactNode } from 'react';
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
    const panel = useRef<HTMLDivElement>(null);

    // A tab pressed in the unfolded menu (pointer, Enter or Space - never an arrow key, which only walks
    // the tabs) shuts it, so its panel is what the phone shows, and the focus goes to that panel rather
    // than staying on a tab that is now hidden (the review of the phone audit, 2026-10-29).
    function chosen() {
        if (!menuOpen) {
            return;
        }

        setMenuOpen(false);
        requestAnimationFrame(() => panel.current?.focus());
    }

    return (
        <StorefrontLayout title={title}>
            <Tabs orientation="vertical" value={tab} onValueChange={onTab} className="grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)]">
                <AccountMenu label={title} open={menuOpen} onOpenChange={setMenuOpen}>
                    <AccountPageLinks />
                    <TabsList variant="line" aria-label={title} className="w-full items-stretch">
                        {(accountMenu?.tabs ?? []).map((each) => (
                            <TabsTrigger key={each.key} value={each.key} onClick={chosen} data-test={`tab-${each.key}`} className="h-9 flex-none px-2 text-label-14">
                                {each.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </AccountMenu>

                <TabsContent ref={panel} value={tab} className="grid content-start gap-5">
                    <AccountHeading title={title} subtitle={subtitle} />
                    {children}
                </TabsContent>
            </Tabs>
        </StorefrontLayout>
    );
}
