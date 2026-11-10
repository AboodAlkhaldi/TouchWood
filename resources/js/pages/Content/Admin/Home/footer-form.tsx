import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { PairFields } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { FooterData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| W1's Footer tab (content.md §1.5): a line about the store, phone, email, an address line, the copyright line, and up
| to 3 columns of up to 8 links, each a text per language and a link. It is shown at once, so it is refused without
| both languages; the one tick is asked while Translate's mark stays on any English box (§1.3).
*/

type Words = { ar: string; en: string };
type LinkRow = { text: Words; link: string };
type Column = { title: Words; links: LinkRow[] };

function wordsOf(value: unknown): Words {
    const pair = (value ?? {}) as { ar?: string | null; en?: string | null };

    return { ar: pair.ar ?? '', en: pair.en ?? '' };
}

function columnsOf(columns: FooterData['columns']): Column[] {
    return columns.map((column) => {
        const raw = column as { title?: unknown; links?: { text?: unknown; link?: string | null }[] };

        return { title: wordsOf(raw.title), links: (raw.links ?? []).map((link) => ({ text: wordsOf(link.text), link: link.link ?? '' })) };
    });
}

export function FooterForm({ storeId, footer, translationReady }: { storeId: string; footer: FooterData; translationReady: boolean }) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props as unknown as { errors: Record<string, string | undefined> };
    const [boxes, setBoxes] = useState({
        about: { ar: footer.aboutAr ?? '', en: footer.aboutEn ?? '' },
        address: { ar: footer.addressAr ?? '', en: footer.addressEn ?? '' },
        copyright: { ar: footer.copyrightAr ?? '', en: footer.copyrightEn ?? '' },
    });
    const [phone, setPhone] = useState(footer.phone ?? '');
    const [email, setEmail] = useState(footer.email ?? '');
    const [columns, setColumns] = useState<Column[]>(() => columnsOf(footer.columns));
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const translation = useTranslation('homepage', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'about_ar', label: t('content::admin_home.footer.about_ar'), value: boxes.about.ar, rules: { length: { max: 300 } } },
        { id: 'about_en', label: t('content::admin_home.footer.about_en'), value: boxes.about.en, rules: { required: boxes.about.ar !== '', length: { max: 300 } } },
        { id: 'email', label: t('content::admin_home.footer.email'), value: email, rules: { email: true } },
        { id: 'phone', label: t('content::admin_home.footer.phone'), value: phone, rules: { phone: true } },
    ]);
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const change = (apply: () => void) => {
        apply();
        setDirty(true);
    };
    const boxPair = (name: 'about' | 'address' | 'copyright'): Pair => ({ name, ar: boxes[name].ar, en: boxes[name].en, fill: (value) => change(() => setBoxes((was) => ({ ...was, [name]: { ...was[name], en: value } }))) });
    const setColumn = (at: number, next: (column: Column) => Column) => change(() => setColumns((was) => was.map((each, i) => (i === at ? next(each) : each))));
    const pairs: Pair[] = [
        boxPair('about'),
        boxPair('address'),
        boxPair('copyright'),
        ...columns.flatMap((column, at) => [
            { name: `column${at}`, ar: column.title.ar, en: column.title.en, fill: (value: string) => setColumn(at, (each) => ({ ...each, title: { ...each.title, en: value } })) },
            ...column.links.map((link, li) => ({
                name: `column${at}link${li}`,
                ar: link.text.ar,
                en: link.text.en,
                fill: (value: string) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? { ...row, text: { ...row.text, en: value } } : row)) })),
            })),
        ]),
    ];
    const label = t('content::admin_home.footer.save');
    const labels = {
        about: { ar: t('content::admin_home.footer.about_ar'), en: t('content::admin_home.footer.about_en') },
        address: { ar: t('content::admin_home.footer.address_ar'), en: t('content::admin_home.footer.address_en') },
        copyright: { ar: t('content::admin_home.footer.copyright_ar'), en: t('content::admin_home.footer.copyright_en') },
    };

    const send = () =>
        router.post(
            '/admin/content/home/footer',
            {
                store: storeId,
                version: footer.version,
                about_ar: boxes.about.ar,
                about_en: boxes.about.en,
                phone,
                email,
                address_ar: boxes.address.ar,
                address_en: boxes.address.en,
                copyright_ar: boxes.copyright.ar,
                copyright_en: boxes.copyright.en,
                columns: columns.map((column) => ({ titleAr: column.title.ar, titleEn: column.title.en, links: column.links.map((link) => ({ textAr: link.text.ar, textEn: link.text.en, link: link.link })) })),
            },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => setDirty(false) },
        );

    return (
        <form
            className="grid max-w-4xl min-w-0 gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                checks.submit(() => tick.ask(send, label));
            }}
            data-test="footer-form"
        >
            <Note size="small">{t('content::admin_home.footer.both')}</Note>
            <TranslateAllButton translation={translation} pairs={pairs} />
            {(['about', 'address', 'copyright'] as const).map((name) => (
                <PairFields
                    key={name}
                    name={name}
                    rows={name === 'about' ? 3 : undefined}
                    labelAr={labels[name].ar}
                    labelEn={labels[name].en}
                    ar={boxes[name].ar}
                    en={boxes[name].en}
                    onChange={(language, value) => change(() => setBoxes((was) => ({ ...was, [name]: { ...was[name], [language]: value } })))}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
            ))}
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField id="phone" dir="ltr" label={t('content::admin_home.footer.phone')} check={checks.box('phone', errors.phone)} value={phone} onChange={(event) => change(() => setPhone(toLatinDigits(event.target.value)))} data-test="phone" />
                <TextField id="email" dir="ltr" type="email" label={t('content::admin_home.footer.email')} check={checks.box('email', errors.email)} value={email} onChange={(event) => change(() => setEmail(event.target.value))} data-test="email" />
            </div>

            <FieldSet className="gap-4" data-test="columns">
                <FieldLegend variant="label" className="text-label-14 text-ink">
                    {t('content::admin_home.footer.columns')}
                </FieldLegend>
                <FieldDescription>{t('content::admin_home.footer.columns_helper')}</FieldDescription>
                {errors.columns || errors.column_links ? (
                    <p role="alert" className="text-copy-14 text-bad">
                        {errors.columns ?? errors.column_links}
                    </p>
                ) : null}
                {columns.map((column, at) => (
                    <div key={at} className="material-base grid gap-4 p-4" data-test={`column-${at}`}>
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-label-14 text-ink">{t('content::admin_home.footer.column', { number: at + 1 })}</h3>
                            <Button type="button" variant="ghost" size="sm" onClick={() => change(() => setColumns((was) => was.filter((_, i) => i !== at)))} data-test={`remove-column-${at}`}>
                                <Trash2 aria-hidden />
                                {t('content::admin_home.footer.remove_column')}
                            </Button>
                        </div>
                        <PairFields
                            name={`column${at}`}
                            labelAr={t('content::admin_home.footer.column_title_ar')}
                            labelEn={t('content::admin_home.footer.column_title_en')}
                            ar={column.title.ar}
                            en={column.title.en}
                            onChange={(language, value) => setColumn(at, (each) => ({ ...each, title: { ...each.title, [language]: value } }))}
                            errors={{}}
                            checks={checks}
                            translation={translation}
                        />
                        {column.links.map((link, li) => (
                            <div key={li} className="grid gap-3 border-t border-line pt-3">
                                <PairFields
                                    name={`column${at}link${li}`}
                                    labelAr={t('content::admin_home.footer.link_text_ar')}
                                    labelEn={t('content::admin_home.footer.link_text_en')}
                                    ar={link.text.ar}
                                    en={link.text.en}
                                    onChange={(language, value) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? { ...row, text: { ...row.text, [language]: value } } : row)) }))}
                                    errors={{}}
                                    checks={checks}
                                    translation={translation}
                                />
                                <div className="flex flex-wrap items-end gap-2">
                                    <TextField
                                        id={`column${at}link${li}-address`}
                                        dir="ltr"
                                        className="min-w-0 flex-1"
                                        label={t('content::admin_shared.link')}
                                        value={link.link}
                                        onChange={(event) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? { ...row, link: toLatinDigits(event.target.value) } : row)) }))}
                                    />
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setColumn(at, (each) => ({ ...each, links: each.links.filter((_, j) => j !== li) }))}>
                                        {t('content::admin_home.footer.remove_link')}
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {column.links.length < 8 ? (
                            <div>
                                <Button type="button" variant="outline" size="sm" onClick={() => setColumn(at, (each) => ({ ...each, links: [...each.links, { text: { ar: '', en: '' }, link: '' }] }))} data-test={`add-link-${at}`}>
                                    <Plus aria-hidden />
                                    {t('content::admin_home.footer.add_link')}
                                </Button>
                            </div>
                        ) : null}
                    </div>
                ))}
                {columns.length < 3 ? (
                    <div>
                        <Button type="button" variant="outline" onClick={() => change(() => setColumns((was) => [...was, { title: { ar: '', en: '' }, links: [] }]))} data-test="add-column">
                            <Plus aria-hidden />
                            {t('content::admin_home.footer.add_column')}
                        </Button>
                    </div>
                ) : null}
            </FieldSet>
            <div>
                <ActionButton type="submit" loading={saving} disabledReason={checks.reason} data-test="save-footer">
                    {label}
                </ActionButton>
            </div>
            {translation.dialog}
            {tick.dialog}
            {leave}
        </form>
    );
}
