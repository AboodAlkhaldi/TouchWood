import { TextareaField, TextField } from '@/components/Fields';
import { isShopLink } from '@/components/content/links';
import { TranslateButton, type Translation } from '@/components/content/translate';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { Checks } from '@/lib/use-checks';

/*
| What Content's forms draw the same way (content.md §4.2, §1.3): a pair of Arabic and English boxes side by side,
| stacked on a phone, each in its own direction, Translate under the English. The lists' parts are list.tsx.
*/

type PairProps = {
    /** The pair's name: its boxes post as `{name}_ar` and `{name}_en`. */
    name: string;
    labelAr: string;
    labelEn: string;
    helper?: string;
    ar: string;
    en: string;
    onChange: (language: 'ar' | 'en', value: string) => void;
    errors: Partial<Record<string, string>>;
    checks: Checks;
    translation: Translation;
    rows?: number;
    /** Typed marks (§1.2), sent to Translate block by block. */
    rich?: boolean;
};

export { isShopLink };

/** A link box's own rule, for `use-checks`: its sentence's key when the link is not one the server takes. */
export const linkRule = (text: string): string | null => (isShopLink(text) ? null : 'content::admin_shared.link_rule');

/** Arabic and English side by side, stacked on a phone, each in its own direction, Translate under the English. */
export function PairFields({ name, labelAr, labelEn, helper, ar, en, onChange, errors, checks, translation, rows, rich = false }: PairProps) {
    const t = useTranslator();
    const marked = translation.marked.has(name);
    const note = translation.note(name);
    const Box = rows === undefined ? TextField : TextareaField;
    const english = `${name}_en`;
    const pair = { name, ar, en, rich, fill: (value: string) => onChange('en', value) };

    return (
        <div className="grid gap-4 sm:grid-cols-2" data-test={`pair-${name}`}>
            <Box
                id={`${name}_ar`}
                dir="rtl"
                lang="ar"
                label={labelAr}
                helper={helper}
                rows={rows}
                check={checks.box(`${name}_ar`, errors[`${name}_ar`])}
                value={ar}
                onChange={(event: { target: { value: string } }) => onChange('ar', toLatinDigits(event.target.value))}
                data-test={`${name}_ar`}
            />
            <div className="grid content-start gap-2">
                <Box
                    id={english}
                    dir="ltr"
                    lang="en"
                    label={labelEn}
                    helper={marked ? t('content::admin_shared.translate.marked') : helper}
                    rows={rows}
                    check={checks.box(english, errors[english])}
                    value={en}
                    onChange={(event: { target: { value: string } }) => {
                        translation.typed(name);
                        onChange('en', toLatinDigits(event.target.value));
                    }}
                    data-test={english}
                />
                {note === undefined ? null : (
                    <p className="text-copy-13 text-ink-muted" role="status" data-test={`translate-note-${name}`}>
                        {note}
                    </p>
                )}
                <div>
                    <TranslateButton translation={translation} pair={pair} />
                </div>
            </div>
        </div>
    );
}
