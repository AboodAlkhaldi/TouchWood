import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreFilter } from '@/components/StoreFilter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { CategoriesPage, CategoryData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { FatesDialog } from '../FatesDialog';
import { ImageField, MoreButton, MoreButtonOff, StateBadge, nameIn, useAllStoresReason, useLocale, type Locale } from '../parts';
import { SortableList } from '../SortableList';

/*
| The categories screen (catalog.md §1.5, §4.4 S2): **the whole tree** in one table, a parent's
| children folded under it (P7 - Geist's only tree, File Tree, is for illustrating project layouts,
| with no columns or actions; shadcn has none, so the table's own rows are shown and hidden by a
| button that says whether it is open), with each category's products in it and under it.
|
| **The store filter** (`?store=`, frontend.md §2.2) chooses whose menu order the page shows and
| orders: the stores where the reader orders the menu (`catalog.category.rank`), a Super Admin's off
| ones marked Off. A category the store has not placed shows the base store's place, marked so
| (amendment 5(a)). Ordering one parent's children is a drag in a dialog (P8), saved for that store.
|
| The tree itself changes only with `catalog.category.manage` and All stores (§3); for anyone else its
| buttons stay in sight, out of reach, with that reason (P2).
*/

type Dialog =
    | { action: 'add' | 'edit' | 'move' | 'deactivate' | 'delete'; category: CategoryData | null; parent?: string | null }
    | { action: 'order'; category: CategoryData | null }
    | null;

type Node = { category: CategoryData; children: Node[]; depth: number };

/*
| A web address as Slug::of takes one typed (catalog.md §1.1, §5.3): at most 200 characters
| (Slug::MAX), words joined by single hyphens - in English lower-case a-z and digits, in Arabic the
| Arabic letters (U+0621-U+063F, U+0641-U+064A, U+0671-U+06D3) and digits. Arabic-Indic digits are let
| through in both, as the server turns them into 0-9 before it reads the shape (CatalogText).
*/
const SLUG_EN = { length: { max: 200 }, format: { pattern: /^[a-z0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+(-[a-z0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+)*$/u, key: 'catalog::admin.check.slug_en' } };
const SLUG_AR = {
    length: { max: 200 },
    format: {
        pattern: /^[\u{0621}-\u{063F}\u{0641}-\u{064A}\u{0671}-\u{06D3}0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+(-[\u{0621}-\u{063F}\u{0641}-\u{064A}\u{0671}-\u{06D3}0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+)*$/u,
        key: 'catalog::admin.check.slug_ar',
    },
};

/** The tree, each parent's children in the store's order, else the base store's, else by name. */
function tree(categories: CategoryData[], locale: Locale): Node[] {
    const byParent = new Map<string, CategoryData[]>();

    for (const category of categories) {
        const key = category.parentId ?? '';
        byParent.set(key, [...(byParent.get(key) ?? []), category]);
    }

    const place = (category: CategoryData) => category.storeRank ?? category.baseRank ?? Number.MAX_SAFE_INTEGER;
    const branch = (parent: string, depth: number): Node[] =>
        [...(byParent.get(parent) ?? [])]
            .sort((a, b) => place(a) - place(b) || nameIn(locale, a.nameAr, a.nameEn).localeCompare(nameIn(locale, b.nameAr, b.nameEn)))
            .map((category) => ({ category, depth, children: branch(category.id, depth + 1) }));

    return branch('', 0);
}

/** Every id under a category, itself included. */
function below(node: Node): string[] {
    return [node.category.id, ...node.children.flatMap(below)];
}

export default function Index({ categories, storeCode, storeName, stores, mayManage, mayRank, reached, reachedFor }: CategoriesPage) {
    const t = useTranslator();
    const locale = useLocale();
    const nodes = useMemo(() => tree(categories, locale), [categories, locale]);
    const byId = useMemo(() => new Map(categories.map((category) => [category.id, category])), [categories]);
    const [open, setOpen] = useState<Set<string>>(() => new Set(nodes.map((node) => node.category.id)));
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const manageReason = useAllStoresReason(mayManage);
    const close = (next: boolean) => (next ? undefined : setDialog(null));
    const show = (next: Dialog, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog(next);
    };

    const nodeOf = (id: string): Node | null => {
        const find = (list: Node[]): Node | null => {
            for (const node of list) {
                if (node.category.id === id) {
                    return node;
                }

                const found = find(node.children);

                if (found !== null) {
                    return found;
                }
            }

            return null;
        };

        return find(nodes);
    };

    const rows: ReactNode[] = [];
    const walk = (list: Node[]) => {
        for (const node of list) {
            rows.push(
                <Row
                    key={node.category.id}
                    node={node}
                    open={open.has(node.category.id)}
                    toggle={() => {
                        const next = new Set(open);
                        next.has(node.category.id) ? next.delete(node.category.id) : next.add(node.category.id);
                        setOpen(next);
                    }}
                    storeShown={storeCode !== null}
                    manageReason={manageReason}
                    mayRank={mayRank}
                    show={show}
                />,
            );

            if (open.has(node.category.id)) {
                walk(node.children);
            }
        }
    };
    walk(nodes);

    // Where a product may be moved: an active lowest category outside what is deactivated.
    const moveTargets = (outside: string[]) =>
        categories
            .filter((category) => category.active && !categories.some((other) => other.parentId === category.id) && !outside.includes(category.id))
            .map((category) => ({ id: category.id, name: nameIn(locale, category.nameAr, category.nameEn) }));

    return (
        <AdminLayout
            title={t('catalog::admin_categories.title')}
            subtitle={storeName === null ? t('catalog::admin_categories.subtitle') : t('catalog::admin_categories.subtitle_store', { store: storeName })}
            action={
                <ActionButton type="button" disabledReason={manageReason} onClick={(event) => show({ action: 'add', category: null, parent: null }, event.currentTarget)} data-test="add-category">
                    {t('catalog::admin_categories.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <div className="flex flex-wrap items-end gap-3">
                    <StoreFilter stores={stores} value={storeCode} className="max-w-xs" />
                    {mayRank && nodes.length > 1 ? (
                        <Button type="button" variant="outline" onClick={(event) => show({ action: 'order', category: null }, event.currentTarget)} data-test="order-top">
                            {t('catalog::admin_categories.order_top')}
                        </Button>
                    ) : null}
                </div>

                {nodes.length === 0 ? (
                    <Empty className="material-base" data-test="categories-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_categories.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_categories.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" disabledReason={manageReason} onClick={(event) => show({ action: 'add', category: null, parent: null }, event.currentTarget)}>
                                {t('catalog::admin_categories.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_categories.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('catalog::admin.column.name')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                    {storeCode !== null ? <TableHead>{t('catalog::admin_categories.column.place')}</TableHead> : null}
                                    <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>{rows}</TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit' || dialog.action === 'move') ? (
                <CategoryDialog
                    mode={dialog.action}
                    category={dialog.category}
                    parent={dialog.action === 'add' ? (dialog.parent ?? null) : null}
                    categories={categories}
                    excluded={dialog.category !== null ? (nodeOf(dialog.category.id) ? below(nodeOf(dialog.category.id) as Node) : []) : []}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
            {dialog !== null && dialog.action === 'deactivate' && dialog.category !== null ? (
                <FatesDialog
                    kind="category"
                    target={{ id: dialog.category.id, name: nameIn(locale, dialog.category.nameAr, dialog.category.nameEn) }}
                    reached={reachedFor === dialog.category.id ? reached : null}
                    moveTargets={moveTargets(nodeOf(dialog.category.id) ? below(nodeOf(dialog.category.id) as Node) : [dialog.category.id])}
                    goingWith={(nodeOf(dialog.category.id) ? below(nodeOf(dialog.category.id) as Node) : [])
                        .filter((id) => id !== dialog.category?.id && byId.get(id)?.active === true)
                        .map((id) => nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null))}
                    placeOf={(id) => (id === null ? null : nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null) || null)}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
            {dialog !== null && dialog.action === 'delete' && dialog.category !== null ? (
                <DeleteCategoryDialog category={dialog.category} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
            {dialog !== null && dialog.action === 'order' && storeCode !== null ? (
                <OrderDialog
                    parent={dialog.category}
                    siblings={(dialog.category === null ? nodes : (nodeOf(dialog.category.id)?.children ?? [])).map((node) => node.category)}
                    storeCode={storeCode}
                    storeName={storeName ?? storeCode}
                    byId={byId}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
        </AdminLayout>
    );
}

function Row({
    node,
    open,
    toggle,
    storeShown,
    manageReason,
    mayRank,
    show,
}: {
    node: Node;
    open: boolean;
    toggle: () => void;
    storeShown: boolean;
    /** Why the tree cannot be changed by this reader (P2); undefined when it can. */
    manageReason: string | undefined;
    mayRank: boolean;
    show: (dialog: Dialog, trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const { category } = node;
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const name = nameIn(locale, category.nameAr, category.nameEn);
    const other = locale === 'ar' ? category.nameEn : category.nameAr;
    const parentHere = node.children.length > 0;
    const deleteReason = parentHere ? t('catalog::admin_categories.reason.children') : category.products > 0 ? t('catalog::admin_categories.reason.products') : null;
    const subReason = category.productsHere > 0 ? t('catalog::admin_categories.reason.holds_products') : !category.active ? t('catalog::admin_categories.reason.inactive') : null;
    const ordersHere = mayRank && storeShown && parentHere;

    return (
        <TableRow data-test={`category-${category.id}`}>
            <TableCell>
                <span className="flex items-center gap-2" style={{ paddingInlineStart: `${node.depth * 1.5}rem` }}>
                    {parentHere ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-expanded={open}
                            aria-label={open ? t('catalog::admin_categories.fold', { name }) : t('catalog::admin_categories.unfold', { name })}
                            onClick={toggle}
                            data-test={`toggle-${category.id}`}
                        >
                            {open ? <ChevronDown aria-hidden="true" /> : <ChevronRight aria-hidden="true" className="rtl:rotate-180" />}
                        </Button>
                    ) : (
                        <span className="inline-block size-8" aria-hidden="true" />
                    )}
                    {category.image !== null ? <img src={category.image} alt="" className="size-8 rounded-md border border-line object-cover" /> : null}
                    <span className="grid">
                        <span className="text-label-14 text-ink">{name}</span>
                        <span className="text-copy-12 text-ink-muted" dir={locale === 'ar' ? 'ltr' : 'rtl'}>
                            {other}
                        </span>
                    </span>
                </span>
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, category.products)}</TableCell>
            {storeShown ? (
                <TableCell data-test="category-place">
                    {category.storeRank !== null ? (
                        <span className="tw-figure">{figure(locale, category.storeRank)}</span>
                    ) : category.baseRank !== null ? (
                        <span className="flex items-center gap-2">
                            <span className="tw-figure">{figure(locale, category.baseRank)}</span>
                            <Badge className={tone('gray-subtle')}>{t('catalog::admin_categories.base_place')}</Badge>
                        </span>
                    ) : (
                        <span className="text-ink-muted">—</span>
                    )}
                </TableCell>
            ) : null}
            <TableCell>
                <StateBadge active={category.active} />
            </TableCell>
            <TableCell className="text-end">
                {manageReason !== undefined && !ordersHere ? (
                    <MoreButtonOff name={name} reason={manageReason} />
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} busy={busy} data-test={`category-actions-${category.id}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            {/* Without the tree's job, its items stay in sight, out of reach, with the reason (P2). */}
                            <Item label={t('catalog::admin_categories.edit')} reason={manageReason ?? null} onSelect={() => show({ action: 'edit', category }, more.current)} test="edit-category" />
                            <Item label={t('catalog::admin_categories.add_sub')} reason={manageReason ?? subReason} onSelect={() => show({ action: 'add', category: null, parent: category.id }, more.current)} test="add-sub" />
                            <Item label={t('catalog::admin_categories.move')} reason={manageReason ?? null} onSelect={() => show({ action: 'move', category }, more.current)} test="move-category" />
                            {ordersHere ? (
                                <DropdownMenuItem onSelect={() => show({ action: 'order', category }, more.current)} data-test="order-children">
                                    {t('catalog::admin_categories.order_children')}
                                </DropdownMenuItem>
                            ) : null}
                            {manageReason === undefined && !category.active ? (
                                <DropdownMenuItem
                                    disabled={busy}
                                    onSelect={() => router.post(`/admin/categories/${category.id}/activate`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                                    data-test="activate-category"
                                >
                                    {t('catalog::admin_categories.activate')}
                                </DropdownMenuItem>
                            ) : null}
                            <DropdownMenuSeparator />
                            {category.active ? <Item destructive label={t('catalog::admin_categories.deactivate')} reason={manageReason ?? null} onSelect={() => show({ action: 'deactivate', category }, more.current)} test="deactivate-category" /> : null}
                            <Item destructive label={t('catalog::admin_categories.delete')} reason={manageReason ?? deleteReason} onSelect={() => show({ action: 'delete', category }, more.current)} test="delete-category" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

/** A menu item, out of reach with its reason written under it when it cannot be used. */
function Item({ label, reason, onSelect, test, destructive = false }: { label: string; reason: string | null; onSelect: () => void; test: string; destructive?: boolean }) {
    return (
        <DropdownMenuItem
            variant={destructive ? 'destructive' : 'default'}
            aria-disabled={reason !== null || undefined}
            className={reason !== null ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (reason !== null) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={reason !== null ? 'opacity-60' : undefined}>{label}</span>
                {reason !== null ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
}

type CategoryForm = {
    name_ar: string;
    name_en: string;
    slug_ar: string;
    slug_en: string;
    parent_id: string;
    rank: string;
    image_media_id: string;
    image: File | null;
    remove_image: boolean;
};

/** Add a category (under a parent, or at the top), edit one's names and photo, or move one. */
function CategoryDialog({
    mode,
    category,
    parent,
    categories,
    excluded,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    mode: 'add' | 'edit' | 'move';
    category: CategoryData | null;
    parent: string | null;
    categories: CategoryData[];
    /** Itself and everything under it: never its own new parent (CategoryLoop). */
    excluded: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = (): CategoryForm => ({
        name_ar: category?.nameAr ?? '',
        name_en: category?.nameEn ?? '',
        slug_ar: category?.slugAr ?? '',
        slug_en: category?.slugEn ?? '',
        parent_id: mode === 'add' ? (parent ?? '') : (category?.parentId ?? ''),
        rank: '0',
        image_media_id: category?.imageMediaId ?? '',
        image: null,
        remove_image: false,
    });
    const form = useForm<CategoryForm>(initial());
    const [addresses, setAddresses] = useState(false);
    // A refused address is never left folded away out of sight.
    const addressRefused = form.errors.slug_ar !== undefined || form.errors.slug_en !== undefined;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
            setAddresses(false);
        }
    }, [open, category?.id, parent]);

    // A parent is an active category holding no products itself (CategoryHoldsProducts).
    const parents = categories.filter((each) => each.active && each.productsHere === 0 && !excluded.includes(each.id));
    const title = mode === 'add' ? (parent === null ? t('catalog::admin_categories.add') : t('catalog::admin_categories.add_sub_title')) : mode === 'edit' ? t('catalog::admin_categories.edit_title') : t('catalog::admin_categories.move_title');
    const body = mode === 'add' ? t('catalog::admin_categories.add_body') : mode === 'edit' ? t('catalog::admin_categories.edit_body') : t('catalog::admin_categories.move_body');

    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 100 characters
    // (Category::NAME_MAX, LocalizedName), not sent by a move; a place among the siblings from 0 to
    // 10,000 (ListPosition, CategoryInput::placeEverywhere; the request reads an empty or broken number
    // as -1, so it is required), not sent by an edit; and the web addresses (Slug, above), not sent by a
    // move and not checked while folded away.
    const name = { required: true, length: { max: 100 } };
    const folded = mode === 'move' || !(addresses || addressRefused);
    const checks = useChecks([
        { id: 'category-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name, off: mode === 'move' },
        { id: 'category-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name, off: mode === 'move' },
        { id: 'category-rank', label: t('catalog::admin_categories.field.place'), value: form.data.rank, rules: { required: true, number: { min: 0, max: 10000 } }, off: mode === 'edit' },
        { id: 'category-slug-ar', label: t('catalog::admin.field.slug_ar'), value: form.data.slug_ar, rules: SLUG_AR, off: folded },
        { id: 'category-slug-en', label: t('catalog::admin.field.slug_en'), value: form.data.slug_en, rules: SLUG_EN, off: folded },
    ]);

    function submit() {
        const target = mode === 'add' ? '/admin/categories' : mode === 'edit' ? `/admin/categories/${category?.id ?? ''}` : `/admin/categories/${category?.id ?? ''}/move`;
        // Sent as multipart only when a photo file is attached (Inertia does that by itself).
        checks.submit(() => form.post(target, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            wide={mode !== 'move'}
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={body}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test={`confirm-${mode}-category`}>
                    {mode === 'add' ? title : mode === 'edit' ? t('catalog::admin_categories.save') : t('catalog::admin_categories.move_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {mode === 'move' ? null : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="category-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('category-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="category-name-ar" />
                        <TextField id="category-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('category-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="category-name-en" />
                    </div>
                )}

                {mode === 'edit' ? null : (
                    <>
                        <SelectField id="category-parent" label={t('catalog::admin_categories.field.parent')} value={form.data.parent_id} error={form.errors.parent_id} onChange={(event) => form.setData('parent_id', event.target.value)} data-test="category-parent">
                            <NativeSelectOption value="">{t('catalog::admin_categories.field.top')}</NativeSelectOption>
                            {parents.map((each) => (
                                <NativeSelectOption key={each.id} value={each.id}>
                                    {nameIn(locale, each.nameAr, each.nameEn)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                        <TextField
                            id="category-rank"
                            dir="ltr"
                            inputMode="numeric"
                            className="max-w-40"
                            inputClassName="tw-figure"
                            label={t('catalog::admin_categories.field.place')}
                            helper={t('catalog::admin_categories.field.place_helper')}
                            value={form.data.rank}
                            check={checks.box('category-rank', form.errors.rank)}
                            onChange={(event) => form.setData('rank', toLatinDigits(event.target.value))}
                            data-test="category-rank"
                        />
                    </>
                )}

                {mode === 'move' ? null : (
                    <>
                        <ImageField
                            id="category-image"
                            label={t('catalog::admin_categories.field.image')}
                            held={(category?.imageMediaId ?? null) !== null}
                            current={category?.image ?? null}
                            onFile={(file) => form.setData('image', file)}
                            remove={form.data.remove_image}
                            onRemove={(remove) => form.setData('remove_image', remove)}
                            error={form.errors.image_media_id}
                        />
                        <Collapsible open={addresses || addressRefused} onOpenChange={setAddresses}>
                            <CollapsibleTrigger asChild>
                                <Button type="button" variant="ghost" size="sm" className="justify-start px-0" data-test="category-addresses">
                                    {t('catalog::admin.addresses.title')}
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="grid gap-4 pt-2 sm:grid-cols-2">
                                <TextField id="category-slug-ar" dir="rtl" label={t('catalog::admin.field.slug_ar')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_ar} check={checks.box('category-slug-ar', form.errors.slug_ar)} onChange={(event) => form.setData('slug_ar', event.target.value)} />
                                <TextField id="category-slug-en" dir="ltr" label={t('catalog::admin.field.slug_en')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_en} check={checks.box('category-slug-en', form.errors.slug_en)} onChange={(event) => form.setData('slug_en', event.target.value)} />
                            </CollapsibleContent>
                        </Collapsible>
                    </>
                )}
            </div>
        </PanelDialog>
    );
}

/** One parent's children (or the top categories) dragged into their order in the store shown (P8). */
function OrderDialog({
    parent,
    siblings,
    storeCode,
    storeName,
    byId,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    parent: CategoryData | null;
    siblings: CategoryData[];
    storeCode: string;
    storeName: string;
    byId: Map<string, CategoryData>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const [order, setOrder] = useState<string[]>(() => siblings.map((each) => each.id));
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setOrder(siblings.map((each) => each.id));
        }
    }, [open, parent?.id]);

    function save() {
        // Places ten apart, in the order dragged, so a category added later can go between two.
        const ranks = Object.fromEntries(order.map((id, index) => [id, (index + 1) * 10]));
        router.post('/admin/categories/order', { store: storeCode, ranks }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) });
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={parent === null ? t('catalog::admin_categories.order_top_title') : t('catalog::admin_categories.order_title', { name: nameIn(locale, parent.nameAr, parent.nameEn) })}
            description={t('catalog::admin_categories.order_body', { store: storeName })}
            busy={busy}
            confirm={
                <ActionButton loading={busy} onClick={save} data-test="confirm-order">
                    {t('catalog::admin_categories.order_save')}
                </ActionButton>
            }
        >
            <SortableList
                testPrefix="order"
                items={order.map((id) => {
                    const each = byId.get(id);

                    return { id, label: each === undefined ? id : nameIn(locale, each.nameAr, each.nameEn) };
                })}
                onChange={setOrder}
            />
        </PanelDialog>
    );
}

function DeleteCategoryDialog({ category, open, onOpenChange, returnFocusTo }: { category: CategoryData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_categories.delete_title')}
            description={t('catalog::admin_categories.delete_body', { name: nameIn(locale, category.nameAr, category.nameEn) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/categories/${category.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-category"
                >
                    {t('catalog::admin_categories.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
