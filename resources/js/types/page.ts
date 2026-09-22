/*
| What every page receives, whoever asked for it (frontend.md §1.6, §2.1).
|
| The page's own data is generated from its PHP class into types/generated/ and never written by
| hand. This file is the envelope around it: who is acting, in what language, in which store, and
| what the last request wants to say to them.
*/

export type Locale = 'ar' | 'en';
export type Direction = 'rtl' | 'ltr';
export type Theme = 'light' | 'dark';

/** A menu entry the person may use, as Platform's registry answered for them. */
export type MenuEntry = {
    module: string;
    key: string;
    label: string;
    href: string;
    /** No permission means the module is not built yet: it opens the "coming soon" page. */
    comingSoon: boolean;
};

export type MenuGroup = {
    key: string;
    label: string;
    entries: MenuEntry[];
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
    store: CurrentStore | null;
    flash: Flash;
    errors: PageErrors;
};
