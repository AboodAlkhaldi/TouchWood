import { Fragment, lazy, Suspense, useMemo, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreFilter } from '@/components/StoreFilter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CategoriesPage, CategoryData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { FatesDialog } from '../FatesDialog';
import { MoreButton, MoreButtonOff, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { DragHandle, MoveItems, SortableGroup, SortableGroups, useSaveQueue, useSortableRow } from '../SortableList';

// The add, edit and move dialog - its form and its checks as typed - loads when it is first opened, not
// with the page, which the drag handles' code shares (frontend.md §5, the page budget).
const CategoryDialog = lazy(() => import('./CategoryDialog').then((module) => ({ default: module.CategoryDialog })));

/*
| The categories screen (catalog.md §1.5, §4.4 S2): **the whole tree** in one table, a parent's
| children folded under it (P7 - Geist's only tree, File Tree, is for illustrating project layouts,
| with no columns or actions; shadcn has none, so the table's own rows are shown and hidden by a
| button that says whether it is open), with each category's products in it and under it.
|
| **The store filter** (`?store=`, frontend.md §2.2) chooses whose menu order the page shows and
| orders: the stores where the reader orders the menu (`catalog.category.rank`), a Super Admin's off
| ones marked Off. A category the store has not placed shows the base store's place, marked so
| (amendment 5(a)). **The rows of one parent are dragged into their order** by each row's handle,
| saved for that store when dropped, or moved to the top or the bottom among their siblings from the
| row's ⋯ menu (amendment 16(d), P8): a row moves among its own siblings only. A category added or
| moved goes last among its siblings, in every store - no place is typed.
|
| The tree itself changes only with `catalog.category.manage` and All stores (§3); for anyone else its
| buttons stay in sight, out of reach, with that reason (P2).
*/

type Dialog = { action: 'add' | 'edit' | 'move' | 'deactivate' | 'delete'; category: CategoryData | null; parent?: string | null } | null;

type Node = { category: CategoryData; children: Node[]; depth: number };

/** The drag group of the top categories; every other group is named by its parent's id. */
const TOP = 'top';

/**
 * The tree, each parent's children in the store's order, else the base store's, those with neither
 * last - or, for the group whose order was just made, that order while it is saved. Places alike
 * keep the order the categories come in, English name then id: the order the server orders them
 * in, so an order that sends one id (Move to Top) puts the others after it as the page shows them.
 */
function tree(categories: CategoryData[], sent: Record<string, string[]>): Node[] {
    const byParent = new Map<string, CategoryData[]>();

    for (const category of categories) {
        const key = category.parentId ?? TOP;
        byParent.set(key, [...(byParent.get(key) ?? []), category]);
    }

    const place = (category: CategoryData) => category.storeRank ?? category.baseRank ?? Number.MAX_SAFE_INTEGER;
    const sorted = (parent: string, list: CategoryData[]) =>
        sent[parent] !== undefined
            ? [...list].sort((a, b) => (sent[parent] ?? []).indexOf(a.id) - (sent[parent] ?? []).indexOf(b.id))
            : // A stable sort: places alike stay in the server's order.
              [...list].sort((a, b) => place(a) - place(b));
    const branch = (parent: string, depth: number): Node[] =>
        sorted(parent, byParent.get(parent) ?? []).map((category) => ({ category, depth, children: branch(category.id, depth + 1) }));

    return branch(TOP, 0);
}

/** Every group's ids in their order: the top categories, and each parent's children. */
function groupsOf(nodes: Node[]): Record<string, string[]> {
    const groups: Record<string, string[]> = { [TOP]: nodes.map((node) => node.category.id) };
    const walk = (list: Node[]) => {
        for (const node of list) {
            if (node.children.length > 0) {
                groups[node.category.id] = node.children.map((child) => child.category.id);
                walk(node.children);
            }
        }
    };
    walk(nodes);

    return groups;
}

/** Every id under a category, itself included. */
function below(node: Node): string[] {
    return [node.category.id, ...node.children.flatMap(below)];
}

export default function Index({ categories, storeCode, storeName, stores, mayManage, mayRank, reached, reachedFor }: CategoriesPage) {
    const t = useTranslator();
    const locale = useLocale();
    // The groups' orders just made, shown while they are saved: the server's comes back with the page.
    const [sent, setSent] = useState<Record<string, string[]>>({});
    const queue = useSaveQueue(() => setSent({}));
    const nodes = useMemo(() => tree(categories, sent), [categories, sent]);
    const groups = useMemo(() => groupsOf(nodes), [nodes]);
    const byId = useMemo(() => new Map(categories.map((category) => [category.id, category])), [categories]);
    const [open, setOpen] = useState<Set<string>>(() => new Set(nodes.map((node) => node.category.id)));
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const manageReason = useAllStoresReason(mayManage);
    // The store's menu is ordered here only when a store is shown, by someone who orders it.
    const orderable = mayRank && storeCode !== null;
    const close = (next: boolean) => (next ? undefined : setDialog(null));
    const show = (next: Dialog, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog(next);
    };

    /**
     * One parent's children in their new order, in the store shown (P29): the ids posted first,
     * every other sibling after them in the order the store's menu has then - so Move to Top posts
     * that one id, as every list's order does (useSavedOrder).
     */
    function order(group: string, ids: string[], post: string[] = ids) {
        if (storeCode === null) {
            return;
        }

        setSent((was) => ({ ...was, [group]: ids }));
        // Async: a visit elsewhere on the page never cancels a save under way.
        queue((answered) =>
            router.post('/admin/categories/order', { store: storeCode, parent: group === TOP ? null : group, ids: post }, { async: true, preserveScroll: true, onFinish: answered }),
        );
    }

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

    // The rows stand flat in the table, each parent's children right under it; each group of siblings
    // is its own drag group around them, drawing nothing, so a row moves among its siblings only.
    const rowsOf = (list: Node[], group: string): ReactNode => {
        const ids = list.map((node) => node.category.id);

        return (
            <SortableGroup key={`group-${group}`} id={group} ids={ids}>
                {list.map((node, index) => (
                    <Fragment key={node.category.id}>
                        <Row
                            node={node}
                            open={open.has(node.category.id)}
                            toggle={() => {
                                const next = new Set(open);
                                next.has(node.category.id) ? next.delete(node.category.id) : next.add(node.category.id);
                                setOpen(next);
                            }}
                            storeShown={storeCode !== null}
                            manageReason={manageReason}
                            orderable={orderable}
                            first={index === 0}
                            last={index === list.length - 1}
                            toTop={() => order(group, [node.category.id, ...ids.filter((id) => id !== node.category.id)], [node.category.id])}
                            toBottom={() => order(group, [...ids.filter((id) => id !== node.category.id), node.category.id])}
                            show={show}
                        />
                        {open.has(node.category.id) && node.children.length > 0 ? rowsOf(node.children, node.category.id) : null}
                    </Fragment>
                ))}
            </SortableGroup>
        );
    };

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
                    <SortableGroups groups={groups} labelOf={(id) => nameIn(locale, byId.get(id)?.nameAr ?? null, byId.get(id)?.nameEn ?? null)} onMove={order}>
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_categories.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        {orderable ? (
                                            <TableHead className="w-10">
                                                <span className="sr-only">{t('catalog::admin.order.column')}</span>
                                            </TableHead>
                                        ) : null}
                                        <TableHead>{t('catalog::admin.column.name')}</TableHead>
                                        <TableHead className="text-end">{t('catalog::admin.column.products')}</TableHead>
                                        <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>{rowsOf(nodes, TOP)}</TableBody>
                            </Table>
                        </div>
                    </SortableGroups>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'edit' || dialog.action === 'move') ? (
                <Suspense fallback={null}>
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
                </Suspense>
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
        </AdminLayout>
    );
}

function Row({
    node,
    open,
    toggle,
    storeShown,
    manageReason,
    orderable,
    first,
    last,
    toTop,
    toBottom,
    show,
}: {
    node: Node;
    open: boolean;
    toggle: () => void;
    storeShown: boolean;
    /** Why the tree cannot be changed by this reader (P2); undefined when it can. */
    manageReason: string | undefined;
    /** Whether the reader orders the store's menu shown here. */
    orderable: boolean;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
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
    const row = useSortableRow(category.id, !orderable);
    // Not placed by this store's admins yet: it shows at the base store's place (amendment 5(a)).
    const basePlace = storeShown && category.storeRank === null && category.baseRank !== null;

    return (
        <TableRow
            ref={row.ref}
            style={row.style}
            data-dragging={row.dragging || undefined}
            className="relative z-0 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`category-${category.id}`}
        >
            {orderable ? (
                <TableCell>
                    <DragHandle handle={row.handle} label={name} test={`drag-category-${category.id}`} />
                </TableCell>
            ) : null}
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
                    {basePlace ? (
                        <Badge className={tone('gray-subtle')} data-test="base-place">
                            {t('catalog::admin_categories.base_place')}
                        </Badge>
                    ) : null}
                </span>
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, category.products)}</TableCell>
            <TableCell>
                <StateBadge active={category.active} />
            </TableCell>
            <TableCell className="text-end">
                {manageReason !== undefined && !orderable ? (
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
                            {/* Among its siblings, in the store shown (catalog.category.rank there). */}
                            {orderable ? <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} /> : null}
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
