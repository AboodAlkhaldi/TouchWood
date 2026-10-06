import {
    BadgePercent,
    Boxes,
    Building2,
    CircleDot,
    Contact,
    FileText,
    Image,
    UserRound,
    LayoutDashboard,
    Receipt,
    ScrollText,
    Server,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Store,
    Tags,
    TriangleAlert,
    Users,
} from 'lucide-react';

/*
| The icon beside a menu entry, and beside a business area (frontend.md §2.2).
|
| The sidebar collapses to a rail, and on that rail an area's icon is all there is left of it; open,
| the area's row carries it too, its screens indented underneath (§1.11, sidebar-07). An entry keeps
| its own icon for the rail's menu of an area's screens. A menu entry is a Platform contract that
| any module registers into, and PHP has no business naming a React component, so it names one of
| these instead; an area is named by its key.
|
| The list is short and ours on purpose. A module that ships later either finds its name here or
| adds it in one line; a name nobody knows draws the fallback rather than an empty square, because
| a menu that loses an entry is worse than one with a plain mark in it.
*/

const ICONS = {
    // The business areas, by their keys (Platform's menu registry orders them).
    pricing: BadgePercent,
    staff_and_permissions: ShieldCheck,
    store_settings: SlidersHorizontal,
    system: Server,
    // The entries; catalog, orders, companies, customers, media and audit name an area too.
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
    failed_jobs: TriangleAlert,
    companies: Building2,
    company_types: Tags,
    document_types: FileText,
} as const;

type Props = {
    name: string | null;
    className?: string;
};

export function MenuIcon({ name, className }: Props) {
    const Icon = name !== null && name in ICONS ? ICONS[name as keyof typeof ICONS] : CircleDot;

    return <Icon className={className} aria-hidden />;
}
