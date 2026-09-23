import { router, usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';

/*
| The two choices a person makes about how the system looks to them (frontend.md §2.1, §2.2): the
| theme, and the language the panel is displayed in.
|
| Both are remembered **per browser**, in a cookie, and both are applied **by the server**: the page
| is asked for again and comes back already in the new theme or language. That is why there is no
| flash, why SSR renders exactly what the browser would, and why the choice survives a reload.
|
| The displayed language is not the person's communication language. Emails and SMS codes keep going
| in the language saved on their account, which only they can change, in their own settings
| (Access amendment 16).
*/

function choose(preference: 'theme' | 'locale', value: string) {
    router.post(
        '/admin/preferences',
        { preference, value },
        { preserveScroll: true, preserveState: false },
    );
}

export function ThemeToggle({ className = '' }: { className?: string }) {
    const { theme } = usePage<SharedProps>().props;
    const t = useTranslator();
    // Only the mode is a person's to choose. Which campaign the system is wearing belongs to the
    // store, and every campaign has both (owner, 2026-09-22).
    const next = theme.mode === 'dark' ? 'light' : 'dark';

    return (
        <button
            type="button"
            onClick={() => choose('theme', next)}
            className={`inline-flex items-center gap-2 rounded-pill border border-line px-3 py-1.5 text-xs text-ink-muted transition-colors hover:border-brand hover:text-brand ${className}`}
            title={t(`admin.theme.switch_to_${next}`)}
        >
            {theme.mode === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
            <span>{t(`admin.theme.${next}`)}</span>
        </button>
    );
}

export function LanguageToggle({ className = '' }: { className?: string }) {
    const { locale } = usePage<SharedProps>().props;
    const next = locale === 'ar' ? 'en' : 'ar';

    return (
        <button
            type="button"
            onClick={() => choose('locale', next)}
            className={`inline-flex items-center rounded-pill border border-line px-3 py-1.5 text-xs font-medium text-ink-muted transition-colors hover:border-brand hover:text-brand ${className}`}
            // The label is the language being offered, written in that language - never translated,
            // because someone who cannot read the current language must still recognise it.
            lang={next}
        >
            {next === 'ar' ? 'العربية' : 'English'}
        </button>
    );
}
