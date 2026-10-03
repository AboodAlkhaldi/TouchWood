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
    available: { code: string; name: string; current: boolean }[];
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

export type Store = {
    id: string;
    name: string;
    /** Its IANA zone: the panel writes every moment in the zone of the store it is working in. */
    timezone: string;
    /** Switched on. An off store is shown, marked Off, only to the staff who cover it. */
    isActive: boolean;
    /**
     * Whether this person may work in it: every on store of theirs, and an off one only for a
     * Super Admin preparing it (access.md amendment 58(a)). The others are shown disabled.
     */
    choosable: boolean;
};

/** Who is looking at the page. Null when nobody is signed in. */
export type Viewer = {
    id: string;
    name: string;
    roleLabel: string | null;
    avatarUrl: string | null;
    isSuperAdmin: boolean;
};

export type CurrentStore = {
    /** Null when the person has no stores at all. */
    current: Store | null;
    /** Only the stores that are theirs, on or off; one store means the header shows a name, not a picker. */
    available: Store[];
    /**
     * True when the store they had chosen is no longer theirs and the panel opened somewhere else.
     * The layout says so once, as a toast (frontend.md §2.2).
     */
    fellBack: boolean;
    /** The store they had chosen was switched off, rather than taken away: the toast says that. */
    fellBackFromOff: boolean;
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
    /** Shop pages only; the panel shares its own "store", which is a different thing. */
    shop?: Shop | null;
    shopper?: Shopper | null;
    /** Shop pages only, and only for somebody signed in. */
    accountMenu?: AccountMenu | null;
    shopperLines?: ShopperLine[];
    /** Whether the sidebar starts open or shut down to its rail; this browser's own choice. */
    sidebarOpen: boolean;
    store: CurrentStore | null;
    flash: Flash;
    errors: PageErrors;
};
