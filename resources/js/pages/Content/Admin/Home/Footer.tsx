import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { isShopLink, linkRule, PairFields } from '@/components/content/parts';
import { MoveItems, SortableList } from '@/components/content/sortable';
import { TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { StoreFilter } from '@/components/StoreFilter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { FooterData, HomePageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { HomeTabs } from './tabs';

/*
| W1's Footer tab (content.md §1.5, §4.4), its own page beside the sections' (`?tab=footer`): a line about the store,
| phone, email, an address line, the copyright line, and up to 3 columns of up to 8 links, each a text per language and a
| link - columns and links dragged into order (`SortableList`), or moved to the top or the bottom from their menu. It is
| shown at once, so every box takes both languages; each is checked as it is typed, and a refusal from the server lands
| under the row it is about. The one tick is asked while Translate's mark stays on any English box (§1.3).
|
| The dragging loads once the page is drawn (components/content/sortable.tsx): the first paint and the server's render
| show the lists plain, and the page pays for no @dnd-kit in its 60 KB (JavaScriptBudgetTest).
*/

type Words = { ar: string; en: string };
type Row = { key: string; text: Words; link: string };
type Column = { key: string; title: Words; links: Row[] };
type Lang = 'ar' | 'en';

/** As SaveFooterHandler reads them: 40 characters a column's title and a link's text. */
const TEXT_MAX = 40;
const LINES = ['about', 'address', 'copyright'] as const;

let next = 0;
const key = () => `row-${++next}`;
const wordsOf = (value: unknown): Words => {
    const pair = (value ?? {}) as { ar?: string | null; en?: string | null };

    return { ar: pair.ar ?? '', en: pair.en ?? '' };
};
const empty = (): Words => ({ ar: '', en: '' });
const bad = (words: Words, language: Lang) => words[language].trim() === '' || words[language].length > TEXT_MAX;

/** Moves one item to the top or the bottom of its list. */
function moved<T>(items: T[], at: number, top: boolean): T[] {
    const rest = items.filter((_, i) => i !== at);

    return at >= items.length ? items : top ? [items[at] as T, ...rest] : [...rest, items[at] as T];
}

/** A row's ⋯ menu: Move to Top, Move to Bottom, then its removal. */
function RowMenu({ name, at, count, onMove, onRemove, remove, test }: { name: string; at: number; count: number; onMove: (top: boolean) => void; onRemove: () => void; remove: string; test: string }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton name={name} data-test={`${test}-more`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                <MoveItems first={at === 0} last={at === count - 1} onTop={() => onMove(true)} onBottom={() => onMove(false)} testPrefix={`${test}-move`} />
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={onRemove} data-test={`${test}-remove`}>
                    {remove}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function Footer({ stores, storeCode, sections, footer, translationReady }: HomePageData) {
    const t = useTranslator();
    const store = stores.find((each) => each.code === storeCode);

    return (
        <AdminLayout title={t('content::admin_home.title')} subtitle={t('content::admin_home.subtitle')}>
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} className="max-w-xs" />
                <HomeTabs tab="footer" storeCode={storeCode} sections={sections.length} />
                <FooterForm key={`${storeCode}-${footer.version}`} storeId={store?.id ?? ''} footer={footer} translationReady={translationReady} />
            </div>
        </AdminLayout>
    );
}

function FooterForm({ storeId, footer, translationReady }: { storeId: string; footer: FooterData; translationReady: boolean }) {
    const t = useTranslator();
    const w = (name: string) => t(`content::admin_home.footer.${name}`);
    const { errors } = usePage<SharedProps>().props as unknown as { errors: Record<string, string | undefined> };
    const [boxes, setBoxes] = useState({
        about: { ar: footer.aboutAr ?? '', en: footer.aboutEn ?? '' },
        address: { ar: footer.addressAr ?? '', en: footer.addressEn ?? '' },
        copyright: { ar: footer.copyrightAr ?? '', en: footer.copyrightEn ?? '' },
    });
    const [phone, setPhone] = useState(footer.phone ?? '');
    const [email, setEmail] = useState(footer.email ?? '');
    const [columns, setColumns] = useState<Column[]>(() =>
        (footer.columns as { title?: unknown; links?: { text?: unknown; link?: string | null }[] }[]).map((column) => ({
            key: key(),
            title: wordsOf(column.title),
            links: (column.links ?? []).map((link) => ({ key: key(), text: wordsOf(link.text), link: link.link ?? '' })),
        })),
    );
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const translation = useTranslation('homepage', storeId, translationReady);
    const tick = useReadTick(translation);
    const link = t('content::admin_shared.link');
    const pair = (id: string, label: string, words: Words) =>
        (['ar', 'en'] as const).map((language) => ({ id: `${id}_${language}`, label: w(`${label}_${language}`), value: words[language], rules: { required: true, length: { max: TEXT_MAX } } }));
    const checks = useChecks([
        // Each line both languages or neither; about 200, address 200, copyright 120.
        ...LINES.flatMap((name) =>
            (['ar', 'en'] as const).map((language) => ({
                id: `${name}_${language}`,
                label: w(`${name}_${language}`),
                value: boxes[name][language],
                rules: { required: boxes[name][language === 'ar' ? 'en' : 'ar'].trim() !== '', length: { max: name === 'copyright' ? 120 : 200 } },
            })),
        ),
        { id: 'email', label: w('email'), value: email, rules: { email: true } },
        { id: 'phone', label: w('phone'), value: phone, rules: { phone: true } },
        ...columns.flatMap((column, at) => [
            ...pair(`column${at}`, 'column_title', column.title),
            ...column.links.flatMap((row, li) => [...pair(`column${at}link${li}`, 'link_text', row.text), { id: `column${at}link${li}-address`, label: link, value: row.link, rules: { required: true, own: linkRule } }]),
        ]),
    ]);
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const change = (apply: () => void) => {
        apply();
        setDirty(true);
    };
    const setAll = (edit: (was: Column[]) => Column[]) => change(() => setColumns(edit));
    const setColumn = (at: number, edit: (column: Column) => Column) => setAll((was) => was.map((each, i) => (i === at ? edit(each) : each)));
    const setRow = (at: number, li: number, edit: (row: Row) => Row) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? edit(row) : row)) }));
    const pairs: Pair[] = [
        ...LINES.map((name) => ({ name, ar: boxes[name].ar, en: boxes[name].en, fill: (value: string) => change(() => setBoxes((was) => ({ ...was, [name]: { ...was[name], en: value } }))) })),
        ...columns.flatMap((column, at) => [
            { name: `column${at}`, ar: column.title.ar, en: column.title.en, fill: (value: string) => setColumn(at, (each) => ({ ...each, title: { ...each.title, en: value } })) },
            ...column.links.map((row, li) => ({ name: `column${at}link${li}`, ar: row.text.ar, en: row.text.en, fill: (value: string) => setRow(at, li, (was) => ({ ...was, text: { ...was.text, en: value } })) })),
        ]),
    ];
    // The server names the box, not its row (SaveFooterHandler: `column_title_*`, `link_text_*`, `link`): its refusal goes
    // under the first row that breaks that box's rule, the first row when none does.
    const firstColumn = (language: Lang) => Math.max(0, columns.findIndex((column) => bad(column.title, language)));
    const rows = columns.flatMap((column, at) => column.links.map((row, li) => ({ at, li, a: bad(row.text, 'ar'), e: bad(row.text, 'en'), l: !isShopLink(row.link) })));
    const on = (mark: 'a' | 'e' | 'l', at: number, li: number, message: string | undefined) => {
        const first = rows.find((row) => row[mark]) ?? rows[0];

        return first?.at === at && first.li === li ? message : undefined;
    };
    const label = w('save');

    const send = () =>
        router.post(
            '/admin/content/home/footer',
            {
                store: storeId,
                version: footer.version,
                ...Object.fromEntries(LINES.flatMap((name) => [[`${name}_ar`, boxes[name].ar], [`${name}_en`, boxes[name].en]])),
                phone,
                email,
                columns: columns.map((column) => ({ titleAr: column.title.ar, titleEn: column.title.en, links: column.links.map((row) => ({ textAr: row.text.ar, textEn: row.text.en, link: row.link })) })),
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
            <Note size="small">{w('both')}</Note>
            <TranslateAllButton translation={translation} pairs={pairs} />
            {LINES.map((name) => (
                <PairFields
                    key={name}
                    name={name}
                    rows={name === 'about' ? 3 : undefined}
                    labelAr={w(`${name}_ar`)}
                    labelEn={w(`${name}_en`)}
                    ar={boxes[name].ar}
                    en={boxes[name].en}
                    onChange={(language, value) => change(() => setBoxes((was) => ({ ...was, [name]: { ...was[name], [language]: value } })))}
                    errors={errors}
                    checks={checks}
                    translation={translation}
                />
            ))}
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField id="phone" dir="ltr" label={w('phone')} check={checks.box('phone', errors.phone)} value={phone} onChange={(event) => change(() => setPhone(toLatinDigits(event.target.value)))} data-test="phone" />
                <TextField id="email" dir="ltr" type="email" label={w('email')} check={checks.box('email', errors.email)} value={email} onChange={(event) => change(() => setEmail(event.target.value))} data-test="email" />
            </div>

            <FieldSet className="gap-4" data-test="columns">
                <FieldLegend variant="label" className="text-label-14 text-ink">
                    {w('columns')}
                </FieldLegend>
                <FieldDescription>{w('columns_helper')}</FieldDescription>
                {errors.columns || errors.column_links ? (
                    <p role="alert" className="text-copy-14 text-bad">
                        {errors.columns ?? errors.column_links}
                    </p>
                ) : null}
                <SortableList
                    testPrefix="column"
                    items={columns.map((column, at) => {
                        const name = column.title.ar || t('content::admin_home.footer.column', { number: at + 1 });

                        return {
                            id: column.key,
                            label: name,
                            content: (
                                <div className="grid min-w-0 flex-1 gap-4 py-2" data-test={`column-${at}`}>
                                    <h3 className="text-label-14 text-ink">{t('content::admin_home.footer.column', { number: at + 1 })}</h3>
                                    <PairFields
                                        name={`column${at}`}
                                        labelAr={w('column_title_ar')}
                                        labelEn={w('column_title_en')}
                                        ar={column.title.ar}
                                        en={column.title.en}
                                        onChange={(language, value) => setColumn(at, (each) => ({ ...each, title: { ...each.title, [language]: value } }))}
                                        errors={{ [`column${at}_ar`]: firstColumn('ar') === at ? errors.column_title_ar : undefined, [`column${at}_en`]: firstColumn('en') === at ? errors.column_title_en : undefined }}
                                        checks={checks}
                                        translation={translation}
                                    />
                                    <SortableList
                                        testPrefix={`column-${at}-link`}
                                        items={column.links.map((row, li) => ({
                                            id: row.key,
                                            label: row.text.ar || row.link || w('add_link'),
                                            content: (
                                                <div className="grid min-w-0 flex-1 gap-3 py-2" data-test={`column-${at}-link-${li}`}>
                                                    <PairFields
                                                        name={`column${at}link${li}`}
                                                        labelAr={w('link_text_ar')}
                                                        labelEn={w('link_text_en')}
                                                        ar={row.text.ar}
                                                        en={row.text.en}
                                                        onChange={(language, value) => setRow(at, li, (was) => ({ ...was, text: { ...was.text, [language]: value } }))}
                                                        errors={{ [`column${at}link${li}_ar`]: on('a', at, li, errors.link_text_ar), [`column${at}link${li}_en`]: on('e', at, li, errors.link_text_en) }}
                                                        checks={checks}
                                                        translation={translation}
                                                    />
                                                    <TextField
                                                        id={`column${at}link${li}-address`}
                                                        dir="ltr"
                                                        label={link}
                                                        helper={t('content::admin_shared.link_helper')}
                                                        check={checks.box(`column${at}link${li}-address`, on('l', at, li, errors.link))}
                                                        value={row.link}
                                                        onChange={(event) => setRow(at, li, (was) => ({ ...was, link: toLatinDigits(event.target.value) }))}
                                                        data-test={`column-${at}-link-${li}-address`}
                                                    />
                                                </div>
                                            ),
                                            extra: (
                                                <RowMenu
                                                    name={row.text.ar || row.link}
                                                    at={li}
                                                    count={column.links.length}
                                                    onMove={(top) => setColumn(at, (each) => ({ ...each, links: moved(each.links, li, top) }))}
                                                    onRemove={() => setColumn(at, (each) => ({ ...each, links: each.links.filter((_, j) => j !== li) }))}
                                                    remove={w('remove_link')}
                                                    test={`column-${at}-link-${li}`}
                                                />
                                            ),
                                        }))}
                                        onChange={(ids) => setColumn(at, (each) => ({ ...each, links: ids.flatMap((id) => each.links.filter((row) => row.key === id)) }))}
                                    />
                                    {column.links.length < 8 ? (
                                        <div>
                                            <Button type="button" variant="outline" size="sm" onClick={() => setColumn(at, (each) => ({ ...each, links: [...each.links, { key: key(), text: empty(), link: '' }] }))} data-test={`add-link-${at}`}>
                                                <Plus aria-hidden />
                                                {w('add_link')}
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            ),
                            extra: (
                                <RowMenu
                                    name={name}
                                    at={at}
                                    count={columns.length}
                                    onMove={(top) => setAll((was) => moved(was, at, top))}
                                    onRemove={() => setAll((was) => was.filter((_, i) => i !== at))}
                                    remove={w('remove_column')}
                                    test={`column-${at}`}
                                />
                            ),
                        };
                    })}
                    onChange={(ids) => setAll((was) => ids.flatMap((id) => was.filter((column) => column.key === id)))}
                />
                {columns.length < 3 ? (
                    <div>
                        <Button type="button" variant="outline" onClick={() => setAll((was) => [...was, { key: key(), title: empty(), links: [] }])} data-test="add-column">
                            <Plus aria-hidden />
                            {w('add_column')}
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
