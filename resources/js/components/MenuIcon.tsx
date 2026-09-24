import {
    Boxes,
    CircleDot,
    Contact,
    Image,
    UserRound,
    LayoutDashboard,
    Receipt,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Store,
    Users,
} from 'lucide-react';

/*
| The icon beside a menu entry (frontend.md §2.2).
|
| The sidebar collapses to a rail, and on that rail the icon is all there is left of an entry - so
| every entry needs one. A menu entry is a Platform contract that any module registers into, and
| PHP has no business naming a React component, so it names one of these instead.
|
| The list is short and ours on purpose. A module that ships later either finds its name here or
| adds it in one line; a name nobody knows draws the fallback rather than an empty square, because
| a menu that loses an entry is worse than one with a plain mark in it.
*/

const ICONS = {
    dashboard: LayoutDashboard,
    staff: Users,
    roles: ShieldCheck,
    customers: UserRound,
    address: Contact,
    stores: Store,
    catalog: Boxes,
    orders: ShoppingCart,
    media: Image,
    audit: ScrollText,
    billing: Receipt,
} as const;

type Props = {
    name: string | null;
    className?: string;
};

export function MenuIcon({ name, className }: Props) {
    const Icon = name !== null && name in ICONS ? ICONS[name as keyof typeof ICONS] : CircleDot;

    return <Icon className={className} aria-hidden />;
}
