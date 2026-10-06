import { Monitor, Moon, Sun } from 'lucide-react';
import { DropdownMenuRadioGroup, DropdownMenuRadioItem } from '@/components/ui/dropdown-menu';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from 'cn';
import { useTranslator } from '@/lib/t';
import type { ThemeChoice } from '@/types/page';

/*
| Geist's Theme Switcher (vercel.com/geist/theme-switcher), which shadcn does not have - built from
| Geist's own page (frontend.md §1.11), on shadcn's ToggleGroup for the keyboard and the pressed
| state.
|
| Geist's rules, as its page gives them:
|   - the canonical Light / System / Dark control, in Geist's order: System, Light, Dark;
|   - once per area, in the footer or settings, never duplicated across pages (owner, 2026-10-02:
|     the panel's person menu in the sidebar's footer, and the shop's footer);
|   - `small` for dense chrome - a menu, a footer - and the default size where there is room;
|   - an aria-label per option, composed from the theme's own name, and one for the whole control.
*/

const OPTIONS: { value: ThemeChoice; icon: typeof Sun }[] = [
    { value: 'system', icon: Monitor },
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon },
];

type Props = {
    value: ThemeChoice;
    onChange: (choice: ThemeChoice) => void;
    size?: 'default' | 'small';
    className?: string;
};

export function ThemeSwitcher({ value, onChange, size = 'default', className }: Props) {
    const t = useTranslator();

    return (
        <ToggleGroup
            type="single"
            value={value}
            // A single toggle group lets the pressed item be pressed again to clear it; a theme is
            // always one of the three, so an empty answer changes nothing.
            onValueChange={(next) => {
                if ((next === 'system' || next === 'light' || next === 'dark') && next !== value) {
                    onChange(next);
                }
            }}
            aria-label={t('admin.theme.label')}
            data-test="theme"
            className={cn('rounded-full border border-border p-0.5', className)}
        >
            {OPTIONS.map(({ value: option, icon: Icon }) => (
                // No tooltip around it: a tooltip's trigger writes its own data-state over the
                // toggle's, and the chosen one was never marked (found by a browser test,
                // 2026-10-03). Its name is its label, and the browser's own title.
                <ToggleGroupItem
                    key={option}
                    value={option}
                    aria-label={t(`admin.theme.${option}`)}
                    title={t(`admin.theme.${option}`)}
                    data-test={`theme-${option}`}
                    className={cn(
                        // The chosen one is ringed in the page's ink, well past 3:1 against either
                        // theme; a fill alone was 1.10:1 (the review, 2026-10-03). The keyboard's
                        // place has its own mark, an outline clear of the ring, so the chosen one
                        // still shows it has focus (the re-check of the review).
                        'rounded-full! px-0 text-muted-foreground data-[state=on]:text-foreground data-[state=on]:ring-1 data-[state=on]:ring-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                        size === 'small' ? 'size-6 min-w-6 [&_svg]:size-3.5' : 'size-8 min-w-8 [&_svg]:size-4',
                    )}
                >
                    <Icon aria-hidden="true" />
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

/**
 * The same switcher inside a dropdown menu - the panel's person menu (owner, 2026-10-02) - where
 * Geist passes `small` for dense chrome. Inside a menu a separate control cannot be reached from
 * the keyboard, so here its three segments are the menu's own radio items, in Geist's pill: the
 * arrow keys reach them like every other item, and each says its name.
 */
export function ThemeMenuSwitcher({ value, onChange }: { value: ThemeChoice; onChange: (choice: ThemeChoice) => void }) {
    const t = useTranslator();

    return (
        <div className="flex items-center justify-between gap-3 px-2 py-1.5 text-sm">
            <span>{t('admin.theme.label')}</span>
            <DropdownMenuRadioGroup
                value={value}
                onValueChange={(next) => {
                    if ((next === 'system' || next === 'light' || next === 'dark') && next !== value) {
                        onChange(next);
                    }
                }}
                aria-label={t('admin.theme.label')}
                data-test="theme"
                className="flex rounded-full border border-border p-0.5"
            >
                {OPTIONS.map(({ value: option, icon: Icon }) => (
                    <DropdownMenuRadioItem
                        key={option}
                        value={option}
                        aria-label={t(`admin.theme.${option}`)}
                        title={t(`admin.theme.${option}`)}
                        // Typing a letter reaches it, as it does every item with words.
                        textValue={t(`admin.theme.${option}`)}
                        data-test={`theme-${option}`}
                        // Not a list row but one segment of the pill: no room for the radio dot. The
                        // chosen one is ringed in the page's ink; the one under the keyboard or the
                        // pointer only tinted, so the two never look alike (the review, 2026-10-03).
                        // Choosing closes the menu, as every menu item does (Geist's Menu).
                        className="size-6 justify-center rounded-full p-0 text-muted-foreground data-[state=checked]:text-foreground data-[state=checked]:ring-1 data-[state=checked]:ring-foreground [&>span:first-child]:hidden [&_svg]:size-3.5"
                    >
                        <Icon aria-hidden="true" />
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
        </div>
    );
}
