/*
| What every page receives, whoever asked for it (frontend.md §1.6, §2.1).
|
| The page's own data is generated from its PHP class into types/generated/ and never written by
| hand. This file is the envelope around it: who is acting, in what language, in which store, and
| what the last request wants to say to them.
*/

import type { ShopperLineTone } from '@/types/generated/Modules/Access/Public/Enums';

export type Locale = 'ar' | 'en';
export type Direction = 'rtl' | 'ltr';
/** Light or dark. A campaign has one of each. */
export type Mode = 'light' | 'dark';
/** What the person chose: a mode, or System, which follows the device (frontend.md §1.11). */
export type ThemeChoice = Mode | 'system';

/**
 * What the system is wearing: which campaign, and whether the lights are on.
 *
 * `campaign` is a name, not a list of two - the base one today, and whatever an admin makes later.
 * Nothing in a screen may assume there are only two looks (owner, 2026-09-22).
 */
export type Theme = {
    campaign: string;
    /** The mode the server rendered: light for System, which the browser may turn dark. */
    mode: Mode;
    /** Light, dark, or System - what nobody choosing means (owner, 2026-10-02). */
    choice: ThemeChoice;
    /** Custom properties for a campaign an admin made; absent for the one that ships with us. */
    style?: string;
};

/** A menu entry the person may use, as Platform's registry answered for them. */
export type MenuEntry = {
    module: string;
    key: string;
    label: string;
    href: string;
    /** No permission means the module is not built yet: it opens the "coming soon" page. */
    comingSoon: boolean;
    /** Named from the panel's own short list; see MenuIcon. Null draws the fallback. */
    icon: string | null;
    /** How many wait behind it — failed jobs, say (frontend.md E7); null when it counts nothing. */
    count: number | null;
};

export type MenuGroup = {
    key: string;
    label: string;
    entries: MenuEntry[];
};

/** The shop a page belongs to, and the ones somebody may switch to (frontend.md 2.3). */
export type Shop = {
    code: string;
    name: string;
    currency: string;
    symbol: string;
    /** On stores; an off one only during a staff view that covers it, marked by isActive false. */
    available: { code: string; name: string; current: boolean; isActive: boolean }[];
    languages: string[];
    /** The store's own IANA zone: every moment on a shop page is written in it (owner, 2026-10-02). */
    timezone: string;
};

/** Whoever is signed in to the shop, as its header names them. */
export type Shopper = {
    id: string;
    name: string;
    emailVerified: boolean;
};

/** A staff member looking at the shop from the panel (access.md §1.11); nobody is a shopper meanwhile. */
export type StaffView = {
    name: string;
};

/** The account's side list: its own tabs, and the pages other modules add (access.md amendment 50). */
export type AccountMenu = {
    tabs: { key: string; label: string }[];
    pages: { key: string; label: string; routeName: string }[];
};

/** A line under the shop's header, from another module (access.md amendment 50). */
export type ShopperLine = {
    text: string;
    routeName: string;
    tone: ShopperLineTone;
};

/**
 * One of the person's stores, as View Store's menu lists them (access.md amendment 64): a Super
 * Admin's every store, an off one marked; anyone else's that are on.
 */
export type ViewStore = {
    code: string;
    name: string;
    isActive: boolean;
};

/** Who is looking at the page. Null when nobody is signed in. */
export type Viewer = {
    id: string;
    name: string;
    roleLabel: string | null;
    avatarUrl: string | null;
    isSuperAdmin: boolean;
};

export type Flash = {
    /** A success message, shown as a toast. */
    status: string | null;
};

export type PageErrors = Record<string, string>;

export type SharedProps = {
    /** Sent back as X-CSRF-TOKEN so the two same-named cookies are never consulted (§1.4). */
    csrfToken: string;
    locale: Locale;
    direction: Direction;
    theme: Theme;
    /** Only the translation files the page asked for, already in the page's language. */
    translations: Record<string, string>;
    /** Named routes, split by area: an admin page never carries the storefront's, or the reverse. */
    routes: {
        url: string;
        port: number | null;
        defaults: Record<string, string | number>;
        routes: Record<string, unknown>;
    };
    viewer: Viewer | null;
    menu: MenuGroup[];
    /** Shop pages only. */
    shop?: Shop | null;
    shopper?: Shopper | null;
    /** Shop pages only, during a staff view. */
    staffView?: StaffView | null;
    /** Shop pages only, and only for somebody signed in. */
    accountMenu?: AccountMenu | null;
    shopperLines?: ShopperLine[];
    /** Whether the sidebar starts open or shut down to its rail; this browser's own choice. */
    sidebarOpen: boolean;
    /** The sidebar's business areas this browser left open (group keys); several may be. */
    sidebarSections: string[];
    /** Panel pages: the stores View Store may open (access.md amendment 64). */
    viewStores?: ViewStore[];
    /** Panel pages: the base store's zone, for a screen that shows no one store (frontend.md §1.10). */
    panelTimezone?: string;
    /**
     * A panel screen's own value, not a shared one: the zone of the one store it shows (Home, a store
     * screen filtered to a store). Read by Time before panelTimezone.
     */
    storeTimezone?: string | null;
    flash: Flash;
    errors: PageErrors;
};
