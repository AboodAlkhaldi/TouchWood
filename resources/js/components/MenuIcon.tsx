import {
    BadgePercent,
    Boxes,
    Building2,
    CircleDot,
    Coins,
    Contact,
    FileText,
    Gift,
    Heart,
    Image,
    UserRound,
    LayoutDashboard,
    MapPin,
    ListChecks,
    MessageCircleQuestion,
    Megaphone,
    Receipt,
    RefreshCw,
    ScrollText,
    Server,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Star,
    Store,
    Tags,
    TicketPercent,
    TriangleAlert,
    Truck,
    Users,
    CreditCard,
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
    points: Coins,
    marketing: Megaphone,
    // Sync's area and its screens (sync.md §4.1.2, platform.md §9.4).
    sync: RefreshCw,
    // Shipping's area and its Carriers (shipping.md §4.1 part 1): one new icon, the one a carrier is.
    shipping: Truck,
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
    // The address countries and their places (platform.md §4.3).
    places: MapPin,
    failed_jobs: TriangleAlert,
    companies: Building2,
    company_types: Tags,
    document_types: FileText,
    redemptions: TicketPercent,
    // Marketing's screens (promotions.md §4.1). Two icons the panel already draws, as every new icon
    // weighs on every admin page (frontend.md §5).
    coupons: TicketPercent,
    segments: Users,
    gifts: Gift,
    // Feedback's screens (feedback.md §4.1).
    reviews: Star,
    questions: MessageCircleQuestion,
    favourites: Heart,
    reasons: ListChecks,
    // Sales's Returns (sales.md §4.1): an icon the panel already draws.
    returns: RefreshCw,
    carriers: Truck,
    // Shipping's Boxes (shipping.md §4.1 part 3): an icon the panel already draws.
    boxes: Boxes,
    // Payments' Gateway (payments.md §4.2).
    gateway: CreditCard,
    payments: CreditCard,
} as const;

type Props = {
    name: string | null;
    className?: string;
};

export function MenuIcon({ name, className }: Props) {
    const Icon = name !== null && name in ICONS ? ICONS[name as keyof typeof ICONS] : CircleDot;

    return <Icon className={className} aria-hidden />;
}
