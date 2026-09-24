/*
| What every page receives, whoever asked for it (frontend.md §1.6, §2.1).
|
| The page's own data is generated from its PHP class into types/generated/ and never written by
| hand. This file is the envelope around it: who is acting, in what language, in which store, and
| what the last request wants to say to them.
*/

export type Locale = 'ar' | 'en';
export type Direction = 'rtl' | 'ltr';
/** Light or dark. A campaign has one of each. */
export type Mode = 'light' | 'dark';

/**
 * What the system is wearing: which campaign, and whether the lights are on.
 *
 * `campaign` is a name, not a list of two - the base one today, and whatever an admin makes later.
 * Nothing in a screen may assume there are only two looks (owner, 2026-09-22).
 */
export type Theme = {
    campaign: string;
    mode: Mode;
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
};

/** Whoever is signed in to the shop, as its header names them. */
export type Shopper = {
    id: string;
    name: string;
    emailVerified: boolean;
};

export type Store = {
    id: string;
    name: string;
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
    /** Only the stores that are theirs; one store means the header shows a name, not a picker. */
    available: Store[];
    /**
     * True when the store they had chosen is no longer theirs and the panel opened somewhere else.
     * The layout says so once, as a toast (frontend.md §2.2).
     */
    fellBack: boolean;
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
    /** Whether the sidebar starts open or shut down to its rail; this browser's own choice. */
    sidebarOpen: boolean;
    store: CurrentStore | null;
    flash: Flash;
    errors: PageErrors;
};
