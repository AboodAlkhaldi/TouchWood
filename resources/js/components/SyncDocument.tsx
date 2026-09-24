import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/*
| Keeps <html> saying what the page actually is (frontend.md §1.3, §2.1).
|
| The language, the direction and the theme are decided on the server and written into the shell by
| Blade, so the very first paint is already right. But Blade runs **only on a full page load**, and
| every move after that is an Inertia visit that replaces the body and leaves <html> exactly as it
| was. So switching to Arabic changed every word on the screen while the page stayed left to right,
| and the dark theme changed nothing at all until something forced a reload (found by running it,
| 2026-09-22).
|
| This puts the two back in step: the server still decides, and the document follows what it
| decided, on the first paint and on every visit after it.
|
| It renders nothing. It is in every layout rather than in one place high up, because a page is
| reached through its layout and there is no component above them all inside the Inertia tree.
*/

export function SyncDocument() {
    const { locale, direction, theme } = usePage<SharedProps>().props;

    useEffect(() => {
        const html = document.documentElement;

        if (html.lang !== locale) {
            html.lang = locale;
        }

        if (html.dir !== direction) {
            html.dir = direction;
        }

        if (html.dataset.campaign !== theme.campaign) {
            html.dataset.campaign = theme.campaign;
        }

        if (html.dataset.mode !== theme.mode) {
            html.dataset.mode = theme.mode;
        }
    }, [locale, direction, theme.campaign, theme.mode]);

    return null;
}
