import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { Switch } from '@/components/ui/switch';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { HomePageData, HomeSectionData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { DragHandle, SortableGroup, SortableGroups, useSaveQueue, useSortableRow } from '../../../Catalog/Admin/SortableList';
import { HomeTabs } from './tabs';

/*
| W1 · Home Page (content.md §4.4, §1.4): tabs Sections and Footer (`?tab=`). The sections from top to bottom, each an
| `Item`: drag handle, the type's name, a one-line summary, Show (saved as flipped; refused with "Add the English
| first." while English is missing), Edit, and Remove Section… in its menu. Add Section offers the types not on the page.
*/

const HOME = '/admin/content/home';

type Pair = { ar?: string | null; en?: string | null } | undefined;

export default function Index({ stores, storeCode, sections, addable }: HomePageData) {
    const t = useTranslator();
    const store = stores.find((each) => each.code === storeCode);
    const storeId = store?.id ?? '';
    const [sent, setSent] = useState<string[] | null>(null);
    const queue = useSaveQueue(() => setSent(null));
    const order = sent ?? sections.map((section) => section.id);
    const byId = new Map(sections.map((section) => [section.id, section]));
    const rows = order.flatMap((id) => byId.get(id) ?? []);
    const [removing, setRemoving] = useState<HomeSectionData | null>(null);
    const [busy, setBusy] = useState(false);
    const names: Record<string, string> = {
        HERO: t('content::admin_home.type.HERO'),
        PROMISES: t('content::admin_home.type.PROMISES'),
        CATEGORIES: t('content::admin_home.type.CATEGORIES'),
        BEST_SELLERS: t('content::admin_home.type.BEST_SELLERS'),
        PROJECTS: t('content::admin_home.type.PROJECTS'),
        AUDIENCE: t('content::admin_home.type.AUDIENCE'),
        POINTS: t('content::admin_home.type.POINTS'),
        CLIENT_LOGOS: t('content::admin_home.type.CLIENT_LOGOS'),
    };
    const save = (ids: string[]) => {
        setSent(ids);
        queue((answered) => router.post(`${HOME}/sections/order`, { store: storeId, ids }, { async: true, preserveScroll: true, onFinish: answered }));
    };
    const add = (type: string) => router.post(`${HOME}/sections`, { store: storeId, type });
    const addButton =
        addable.length === 0 ? (
            <ActionButton type="button" disabledReason={t('content::admin_home.sections.all_on')} data-test="add-section">
                {t('content::admin_home.sections.add')}
            </ActionButton>
        ) : (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" data-test="add-section">
                        {t('content::admin_home.sections.add')}
                        <ChevronDown aria-hidden />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-52">
                    {addable.map((type) => (
                        <DropdownMenuItem key={type} onSelect={() => add(type)} data-test={`add-${type}`}>
                            {names[type] ?? type}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        );

    return (
        <AdminLayout
            title={t('content::admin_home.title')}
            subtitle={t('content::admin_home.subtitle')}
            action={
                <div className="flex flex-wrap items-center gap-2">
                    {/* Preview (§4.4 W1): the shop's home in View Store's staff view. */}
                    <Button type="button" variant="outline" onClick={() => router.post('/admin/staff-view', { store: storeCode })} data-test="preview-home">
                        {t('content::admin_home.sections.preview')}
                    </Button>
                    {addButton}
                </div>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} drop={['tab']} className="max-w-xs" />
                <HomeTabs tab="sections" storeCode={storeCode} sections={sections.length} />
                <div>
                        {rows.length === 0 ? (
                            <Empty className="material-base" data-test="sections-empty">
                                <EmptyHeader>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_home.sections.empty')}</EmptyTitle>
                                    <EmptyDescription>{t('content::admin_home.sections.empty_body')}</EmptyDescription>
                                </EmptyHeader>
                                <EmptyContent>{addButton}</EmptyContent>
                            </Empty>
                        ) : (
                            <SortableGroups groups={{ sections: order }} labelOf={(id) => names[byId.get(id)?.type ?? ''] ?? ''} onMove={(_, ids) => save(ids)}>
                                <div role="list" className="grid gap-2">
                                    <SortableGroup id="sections" ids={order}>
                                        {rows.map((section, index) => (
                                            <SectionItem
                                                key={section.id}
                                                section={section}
                                                name={names[section.type] ?? section.type}
                                                first={index === 0}
                                                last={index === rows.length - 1}
                                                onTop={() => save([section.id, ...order.filter((id) => id !== section.id)])}
                                                onBottom={() => save([...order.filter((id) => id !== section.id), section.id])}
                                                onRemove={() => setRemoving(section)}
                                            />
                                        ))}
                                    </SortableGroup>
                                </div>
                            </SortableGroups>
                        )}
                </div>
            </div>
            {removing === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(open) => (open ? undefined : setRemoving(null))}
                    title={t('content::admin_home.sections.remove_title')}
                    description={t('content::admin_home.sections.remove_body')}
                    busy={busy}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(
                                    `${HOME}/sections/${removing.id}/remove`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => {
                                            setBusy(false);
                                            setRemoving(null);
                                        },
                                    },
                                )
                            }
                            data-test="confirm-remove"
                        >
                            {t('content::admin_home.sections.remove')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            )}
        </AdminLayout>
    );
}

/** What a section holds, in one line (§4.4 W1): its own heading or title, or what it counts. */
function useSummary(): (section: HomeSectionData) => string {
    const t = useTranslator();
    const inLanguage = useInLanguage();

    return (section) => {
        const settings = section.settings as Record<string, unknown>;
        // In the panel's language, the Arabic when no English is typed yet.
        const words = (pair: Pair) => inLanguage(pair?.ar ?? null, pair?.en ?? null);
        const count = typeof settings.count === 'number' ? settings.count : 8;

        switch (section.type) {
            case 'HERO':
                return words(settings.title as Pair) || t('content::admin_home.sections.hero_banners');
            case 'PROMISES':
                return t('content::admin_home.sections.promises', { count: Array.isArray(settings.promises) ? settings.promises.length : 0 });
            case 'CATEGORIES':
                return t('content::admin_home.sections.tiles', { count });
            case 'BEST_SELLERS':
                return t('content::admin_home.sections.products', { count });
            case 'PROJECTS':
                return words(settings.heading as Pair) || t('content::admin_home.sections.newest');
            case 'AUDIENCE':
                return [words((settings.individuals as Record<string, Pair> | undefined)?.title), words((settings.companies as Record<string, Pair> | undefined)?.title)].filter(Boolean).join(' · ') || t('content::admin_home.sections.nothing');
            case 'POINTS':
                return words(settings.heading as Pair) || t('content::admin_home.sections.points_figures');
            default:
                return words(settings.heading as Pair) || t('content::admin_home.sections.nothing');
        }
    };
}

function SectionItem({ section, name, first, last, onTop, onBottom, onRemove }: { section: HomeSectionData; name: string; first: boolean; last: boolean; onTop: () => void; onBottom: () => void; onRemove: () => void }) {
    const t = useTranslator();
    const summary = useSummary();
    const sortable = useSortableRow(section.id);
    const [flipping, setFlipping] = useState(false);

    return (
        <Item
            ref={sortable.ref as never}
            style={sortable.style}
            role="listitem"
            variant="outline"
            data-dragging={sortable.dragging || undefined}
            className="material-base relative z-0 flex-nowrap data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            data-test={`section-${section.type}`}
        >
            <DragHandle handle={sortable.handle} label={name} test={`drag-${section.type}`} />
            <ItemContent className="min-w-0">
                <ItemTitle className="w-full min-w-0">
                    <Link href={`${HOME}/${section.id}`} className="truncate hover:underline" data-test="open-section">
                        {name}
                    </Link>
                </ItemTitle>
                <ItemDescription className="truncate" dir="auto">
                    {summary(section)}
                </ItemDescription>
            </ItemContent>
            <ItemActions className="shrink-0">
                <Button asChild variant="ghost" size="sm">
                    <Link href={`${HOME}/${section.id}`} aria-label={`${t('content::admin_home.sections.edit')}: ${name}`} data-test="edit-section">
                        {t('content::admin_home.sections.edit')}
                    </Link>
                </Button>
                <Switch
                    checked={section.isVisible}
                    disabled={flipping}
                    aria-label={`${t('content::admin_home.sections.show')}: ${name}`}
                    onCheckedChange={(visible) => router.post(`${HOME}/sections/${section.id}/show`, { visible }, { preserveScroll: true, onStart: () => setFlipping(true), onFinish: () => setFlipping(false) })}
                    data-test="show"
                />
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <MoreButton name={name} data-test={`more-${section.type}`} />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-48">
                        <DropdownMenuItem asChild>
                            <Link href={`${HOME}/${section.id}`} data-test="menu-edit">
                                {t('content::admin_home.sections.edit')}
                            </Link>
                        </DropdownMenuItem>
                        {first ? null : (
                            <DropdownMenuItem onSelect={onTop} data-test="move-top">
                                {t('content::admin_shared.order.top')}
                            </DropdownMenuItem>
                        )}
                        {last ? null : (
                            <DropdownMenuItem onSelect={onBottom} data-test="move-bottom">
                                {t('content::admin_shared.order.bottom')}
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" onSelect={onRemove} data-test="menu-remove">{`${t('content::admin_home.sections.remove')}…`}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </ItemActions>
        </Item>
    );
}
