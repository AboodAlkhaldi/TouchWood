import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { isShopLink, linkRule, PairFields } from '@/components/content/parts';
import { TranslateAllButton, useReadTick, useTranslation, type Pair } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { MoreButton } from '@/components/RowMenu';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { FieldDescription, FieldLegend, FieldSet } from '@/components/ui/field';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks, type Box } from '@/lib/use-checks';
import type { FooterData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { MoveItems, SortableList } from '../../../Catalog/Admin/SortableList';

/*
| W1's Footer tab (content.md §1.5, §4.4): a line about the store, phone, email, an address line, the copyright line, and
| up to 3 columns of up to 8 links, each a text per language and a link - columns and links dragged into order
| (`SortableList`), or moved to the top or the bottom from their menu. It is shown at once, so every box takes both
| languages; each is checked as it is typed, and a refusal from the server lands under the row it is about. The one
| tick is asked while Translate's mark stays on any English box (§1.3).
*/

type Words = { ar: string; en: string };
type LinkRow = { key: string; text: Words; link: string };
type Column = { key: string; title: Words; links: LinkRow[] };

/** As SaveFooterHandler reads them: 40 characters a column's title and a link's text. */
const TEXT_MAX = 40;

let next = 0;
const key = () => `row-${++next}`;

function wordsOf(value: unknown): Words {
    const pair = (value ?? {}) as { ar?: string | null; en?: string | null };

    return { ar: pair.ar ?? '', en: pair.en ?? '' };
}

function columnsOf(columns: FooterData['columns']): Column[] {
    return columns.map((column) => {
        const raw = column as { title?: unknown; links?: { text?: unknown; link?: string | null }[] };

        return { key: key(), title: wordsOf(raw.title), links: (raw.links ?? []).map((link) => ({ key: key(), text: wordsOf(link.text), link: link.link ?? '' })) };
    });
}

/** Moves one item to the top or the bottom of its list. */
function moved<T extends { key: string }>(items: T[], at: number, to: 'top' | 'bottom'): T[] {
    const item = items[at];

    if (item === undefined) {
        return items;
    }

    const rest = items.filter((_, i) => i !== at);

    return to === 'top' ? [item, ...rest] : [...rest, item];
}

function RowMenu({ name, first, last, onTop, onBottom, onRemove, removeLabel, test }: { name: string; first: boolean; last: boolean; onTop: () => void; onBottom: () => void; onRemove: () => void; removeLabel: string; test: string }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton name={name} data-test={`${test}-more`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                <MoveItems first={first} last={last} onTop={onTop} onBottom={onBottom} testPrefix={`${test}-move`} />
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={onRemove} data-test={`${test}-remove`}>
                    {removeLabel}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
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
    const pairBoxes = (id: string, label: { ar: string; en: string }, words: Words, required: boolean): Box[] => [
        { id: `${id}_ar`, label: label.ar, value: words.ar, rules: { required: required || words.en !== '', length: { max: TEXT_MAX } } },
        { id: `${id}_en`, label: label.en, value: words.en, rules: { required: required || words.ar !== '', length: { max: TEXT_MAX } } },
    ];
    const titleLabel = { ar: t('content::admin_home.footer.column_title_ar'), en: t('content::admin_home.footer.column_title_en') };
    const textLabel = { ar: t('content::admin_home.footer.link_text_ar'), en: t('content::admin_home.footer.link_text_en') };
    const checks = useChecks([
        // As SaveFooterHandler reads them: each line both languages or neither; about 200, address 200, copyright 120.
        ...(['about', 'address', 'copyright'] as const).flatMap((name) => [
            { id: `${name}_ar`, label: t(`content::admin_home.footer.${name}_ar`), value: boxes[name].ar, rules: { required: boxes[name].en.trim() !== '', length: { max: name === 'copyright' ? 120 : 200 } } },
            { id: `${name}_en`, label: t(`content::admin_home.footer.${name}_en`), value: boxes[name].en, rules: { required: boxes[name].ar.trim() !== '', length: { max: name === 'copyright' ? 120 : 200 } } },
        ]),
        { id: 'email', label: t('content::admin_home.footer.email'), value: email, rules: { email: true } },
        { id: 'phone', label: t('content::admin_home.footer.phone'), value: phone, rules: { phone: true } },
        ...columns.flatMap((column, at) => [
            ...pairBoxes(`column${at}`, titleLabel, column.title, true),
            ...column.links.flatMap((link, li) => [
                ...pairBoxes(`column${at}link${li}`, textLabel, link.text, true),
                { id: `column${at}link${li}-address`, label: t('content::admin_shared.link'), value: link.link, rules: { required: true, own: linkRule } },
            ]),
        ]),
    ]);
    const leave = useLeaveGuard(dirty, { description: t('content::admin_shared.leave') });
    const change = (apply: () => void) => {
        apply();
        setDirty(true);
    };
    const setColumn = (at: number, edit: (column: Column) => Column) => change(() => setColumns((was) => was.map((each, i) => (i === at ? edit(each) : each))));
    const boxPair = (name: 'about' | 'address' | 'copyright'): Pair => ({ name, ar: boxes[name].ar, en: boxes[name].en, fill: (value) => change(() => setBoxes((was) => ({ ...was, [name]: { ...was[name], en: value } }))) });
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
    const bad = (words: Words, language: 'ar' | 'en') => words[language].trim() === '' || words[language].length > TEXT_MAX;
    // The server names the box, not its row (SaveFooterHandler: `column_title_*`, `link_text_*`, `link`): its refusal goes
    // under the first row that breaks that box's rule, the first row when none does.
    const firstColumn = (language: 'ar' | 'en') => Math.max(0, columns.findIndex((column) => bad(column.title, language)));
    const links = columns.flatMap((column, at) => column.links.map((link, li) => ({ at, li, link })));
    const firstLink = (wrong: (link: LinkRow) => boolean) => links.find((each) => wrong(each.link)) ?? links[0];
    const textAr = firstLink((link) => bad(link.text, 'ar'));
    const textEn = firstLink((link) => bad(link.text, 'en'));
    const address = firstLink((link) => !isShopLink(link.link));
    const on = (row: { at: number; li: number } | undefined, at: number, li: number, message: string | undefined) => (row !== undefined && row.at === at && row.li === li ? message : undefined);
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
    const linkRows = (column: Column, at: number): { id: string; label: string; content: ReactNode; extra: ReactNode }[] =>
        column.links.map((link, li) => ({
            id: link.key,
            label: link.text.ar || link.link || t('content::admin_home.footer.add_link'),
            content: (
                <div className="grid min-w-0 flex-1 gap-3 py-2" data-test={`column-${at}-link-${li}`}>
                    <PairFields
                        name={`column${at}link${li}`}
                        labelAr={textLabel.ar}
                        labelEn={textLabel.en}
                        ar={link.text.ar}
                        en={link.text.en}
                        onChange={(language, value) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? { ...row, text: { ...row.text, [language]: value } } : row)) }))}
                        errors={{ [`column${at}link${li}_ar`]: on(textAr, at, li, errors.link_text_ar), [`column${at}link${li}_en`]: on(textEn, at, li, errors.link_text_en) }}
                        checks={checks}
                        translation={translation}
                    />
                    <TextField
                        id={`column${at}link${li}-address`}
                        dir="ltr"
                        label={t('content::admin_shared.link')}
                        helper={t('content::admin_shared.link_helper')}
                        check={checks.box(`column${at}link${li}-address`, on(address, at, li, errors.link))}
                        value={link.link}
                        onChange={(event) => setColumn(at, (each) => ({ ...each, links: each.links.map((row, j) => (j === li ? { ...row, link: toLatinDigits(event.target.value) } : row)) }))}
                        data-test={`column-${at}-link-${li}-address`}
                    />
                </div>
            ),
            extra: (
                <RowMenu
                    name={link.text.ar || link.link}
                    first={li === 0}
                    last={li === column.links.length - 1}
                    onTop={() => setColumn(at, (each) => ({ ...each, links: moved(each.links, li, 'top') }))}
                    onBottom={() => setColumn(at, (each) => ({ ...each, links: moved(each.links, li, 'bottom') }))}
                    onRemove={() => setColumn(at, (each) => ({ ...each, links: each.links.filter((_, j) => j !== li) }))}
                    removeLabel={t('content::admin_home.footer.remove_link')}
                    test={`column-${at}-link-${li}`}
                />
            ),
        }));

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
                <SortableList
                    testPrefix="column"
                    items={columns.map((column, at) => ({
                        id: column.key,
                        label: column.title.ar || t('content::admin_home.footer.column', { number: at + 1 }),
                        content: (
                            <div className="grid min-w-0 flex-1 gap-4 py-2" data-test={`column-${at}`}>
                                <h3 className="text-label-14 text-ink">{t('content::admin_home.footer.column', { number: at + 1 })}</h3>
                                <PairFields
                                    name={`column${at}`}
                                    labelAr={titleLabel.ar}
                                    labelEn={titleLabel.en}
                                    ar={column.title.ar}
                                    en={column.title.en}
                                    onChange={(language, value) => setColumn(at, (each) => ({ ...each, title: { ...each.title, [language]: value } }))}
                                    errors={{ [`column${at}_ar`]: firstColumn('ar') === at ? errors.column_title_ar : undefined, [`column${at}_en`]: firstColumn('en') === at ? errors.column_title_en : undefined }}
                                    checks={checks}
                                    translation={translation}
                                />
                                <SortableList testPrefix={`column-${at}-link`} items={linkRows(column, at)} onChange={(ids) => setColumn(at, (each) => ({ ...each, links: ids.flatMap((id) => each.links.filter((link) => link.key === id)) }))} />
                                {column.links.length < 8 ? (
                                    <div>
                                        <Button type="button" variant="outline" size="sm" onClick={() => setColumn(at, (each) => ({ ...each, links: [...each.links, { key: key(), text: { ar: '', en: '' }, link: '' }] }))} data-test={`add-link-${at}`}>
                                            <Plus aria-hidden />
                                            {t('content::admin_home.footer.add_link')}
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                        ),
                        extra: (
                            <RowMenu
                                name={column.title.ar || t('content::admin_home.footer.column', { number: at + 1 })}
                                first={at === 0}
                                last={at === columns.length - 1}
                                onTop={() => change(() => setColumns((was) => moved(was, at, 'top')))}
                                onBottom={() => change(() => setColumns((was) => moved(was, at, 'bottom')))}
                                onRemove={() => change(() => setColumns((was) => was.filter((_, i) => i !== at)))}
                                removeLabel={t('content::admin_home.footer.remove_column')}
                                test={`column-${at}`}
                            />
                        ),
                    }))}
                    onChange={(ids) => change(() => setColumns((was) => ids.flatMap((id) => was.filter((column) => column.key === id))))}
                />
                {columns.length < 3 ? (
                    <div>
                        <Button type="button" variant="outline" onClick={() => change(() => setColumns((was) => [...was, { key: key(), title: { ar: '', en: '' }, links: [] }]))} data-test="add-column">
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
