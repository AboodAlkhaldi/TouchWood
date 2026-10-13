import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslator } from '@/lib/t';
import type { CategoryData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';

/*
| Who sees a category in the store shown (catalog.md §1.13, §4.4 S2, amendment 14): one of its two
| switches, Individuals or Companies, **saved the moment it flips** (P9 - Geist's Toggle takes effect
| at once), for the store in the page's own filter. Flipping a parent flips everything under it the
| same way; the page comes back with every row as the server now has it, so a switch always shows
| what is stored, never a guess.
|
| Someone who may not choose there (an admin role's job, 14(c)) sees it in place, out of reach, the
| reason in a tooltip and for a screen reader - as the settings' switches are (lesson 141: the
| tooltip hangs on a span, never on the switch, since both are Radix parts writing data-state).
*/

export function AudienceSwitch({
    category,
    side,
    storeCode,
    storeName,
    reason,
}: {
    category: CategoryData;
    side: 'individuals' | 'companies';
    storeCode: string;
    storeName: string;
    /** Why the reader may not change it here; undefined when they may. */
    reason: string | undefined;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [saving, setSaving] = useState(false);
    const [tip, setTip] = useState(false);
    const on = side === 'individuals' ? category.forIndividuals : category.forCompanies;
    const name = nameIn(locale, category.nameAr, category.nameEn);
    const id = `audience-${side}-${category.id}`;
    const busy = saving ? t('catalog::admin_categories.audience.saving') : reason;
    const label =
        side === 'individuals'
            ? t('catalog::admin_categories.audience.individuals_label', { name, store: storeName })
            : t('catalog::admin_categories.audience.companies_label', { name, store: storeName });

    const flip = (next: boolean) => {
        if (busy !== undefined) {
            return;
        }

        router.post(
            `/admin/categories/${category.id}/audience`,
            { store: storeCode, [side]: next ? '1' : '0' },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <>
            <Tooltip open={busy !== undefined && tip} onOpenChange={setTip}>
                <TooltipTrigger asChild>
                    <span className="inline-flex">
                        <Switch
                            id={id}
                            checked={on}
                            aria-label={label}
                            aria-disabled={busy === undefined ? undefined : true}
                            aria-describedby={busy === undefined ? undefined : `${id}-busy`}
                            onCheckedChange={flip}
                            className="data-[state=unchecked]:bg-ink-subtle aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            data-test={id}
                        />
                    </span>
                </TooltipTrigger>
                {busy === undefined ? null : <TooltipContent>{busy}</TooltipContent>}
            </Tooltip>
            {busy === undefined ? null : (
                <span id={`${id}-busy`} className="sr-only">
                    {busy}
                </span>
            )}
        </>
    );
}
