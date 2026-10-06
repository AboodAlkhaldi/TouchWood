import { useRef, useState, type KeyboardEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { cn } from 'cn';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { StaffTypeListPage, StaffTypeRowData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { figure, nameIn, useLocale } from '../shared';
import { DeactivateModal, TransferModal, TypeFormModal, type Kind } from './Modals';

/*
| The types page (b2b.md §1.3, §4.6, amendment 21): the company types and the document types of the
| store in the panel's header, a tab each, on shadcn's parts with Geist's rules (frontend.md §1.11).
| Another store's lists are reached by changing the store in the header (frontend.md §2.2).
|
| The two lists are two addresses, so each tab is a link (Geist's Tabs: the open tab is in the
| address) inside shadcn's Tabs, which gives them the tab keys: the arrows move between them and
| Enter or Space opens one - by hand, never by focus alone, since opening one loads a page.
|
| Every button is offered only for a job the reader holds in this store (ViewTypeLists), and every
| handler asks again. A type is never deleted: it is deactivated - hidden or greyed out - and may be
| activated again (amendment 10(c)). While the store's lists are marked as copied from the starting
| lists, a note says so until any change to either list, or Mark Lists Reviewed (amendment 10(d)).
*/

type Dialog = { action: 'add' | 'rename' | 'move' | 'deactivate' | 'transfer'; type: StaffTypeRowData | null } | null;

const HREF: Record<Kind, string> = { company: '/admin/company-types', document: '/admin/document-types' };

export default function Index({ kind: listed, storeName, copiedNotReviewed, types, actions }: StaffTypeListPage) {
    const t = useTranslator();
    const locale = useLocale();
    const kind: Kind = listed === 'document' ? 'document' : 'company';
    const [dialog, setDialog] = useState<Dialog>(null);
    const [reviewing, setReviewing] = useState(false);
    // The ⋯ button of the row whose menu opened the dialog: focus goes back to it on close.
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const add = () => setDialog({ action: 'add', type: null });

    const tabs: { kind: Kind; title: string }[] = [
        ...(actions.mayReadCompanyTypes ? [{ kind: 'company' as const, title: t('b2b::admin_types.title.company') }] : []),
        ...(actions.mayReadDocumentTypes ? [{ kind: 'document' as const, title: t('b2b::admin_types.title.document') }] : []),
    ];

    const nextPosition = types.reduce((highest, type) => Math.max(highest, type.position), 0) + 10;

    const list = (
        <div className="grid gap-4">
            <FormError />

            {copiedNotReviewed ? (
                <Note
                    variant="warning"
                    label={t('b2b::admin_types.copied.label')}
                    data-test="copied-notice"
                    action={
                        actions.mayMarkReviewed ? (
                            <ActionButton
                                variant="outline"
                                size="sm"
                                loading={reviewing}
                                onClick={() =>
                                    router.post('/admin/type-lists/reviewed', {}, { preserveScroll: true, onStart: () => setReviewing(true), onFinish: () => setReviewing(false) })
                                }
                                data-test="mark-reviewed"
                            >
                                {t('b2b::admin_types.copied.button')}
                            </ActionButton>
                        ) : undefined
                    }
                >
                    {t('b2b::admin_types.copied.body')}
                </Note>
            ) : null}

            {types.length === 0 ? (
                <Empty className="material-base" data-test="types-empty">
                    <EmptyHeader>
                        <EmptyTitle className="text-heading-16 text-ink">
                            {kind === 'company' ? t('b2b::admin_types.empty.company') : t('b2b::admin_types.empty.document')}
                        </EmptyTitle>
                        <EmptyDescription className="text-copy-14 text-ink-muted">
                            {actions.mayAdd ? t('b2b::admin_types.empty.add') : t('b2b::admin_types.empty.none')}
                        </EmptyDescription>
                    </EmptyHeader>
                    {/* The blank slate names its next step, and offers it (Geist's Empty State). */}
                    {actions.mayAdd ? (
                        <EmptyContent>
                            <Button type="button" onClick={add} data-test="add-type-empty">
                                {kind === 'company' ? t('b2b::admin_types.list.company.add') : t('b2b::admin_types.list.document.add')}
                            </Button>
                        </EmptyContent>
                    ) : null}
                </Empty>
            ) : (
                <div className="material-base overflow-hidden">
                    <Table>
                        <TableCaption className="sr-only">{t(`b2b::admin_types.title.${kind}`)}</TableCaption>
                        <TableHeader className="bg-surface-sunken">
                            <TableRow>
                                {/* The position, a narrow column of its own: aligned to its far edge
                                    it read as part of the name beside it (b2b.md amendment 25). */}
                                <TableHead className="w-12" data-test="column-position">
                                    <span aria-hidden="true">#</span>
                                    <span className="sr-only">{t('b2b::admin_types.column.position')}</span>
                                </TableHead>
                                {/* The two names, the page's language first (amendment 25). */}
                                {locale === 'ar' ? (
                                    <>
                                        <TableHead data-test="column-name-first">{t('b2b::admin_types.column.name_ar')}</TableHead>
                                        <TableHead>{t('b2b::admin_types.column.name_en')}</TableHead>
                                    </>
                                ) : (
                                    <>
                                        <TableHead data-test="column-name-first">{t('b2b::admin_types.column.name_en')}</TableHead>
                                        <TableHead>{t('b2b::admin_types.column.name_ar')}</TableHead>
                                    </>
                                )}
                                <TableHead>{t('b2b::admin_types.column.status')}</TableHead>
                                {kind === 'company' ? (
                                    <TableHead className="text-end">{t('b2b::admin_types.column.holders')}</TableHead>
                                ) : (
                                    <TableHead>{t('b2b::admin_types.column.required')}</TableHead>
                                )}
                                <TableHead className="w-12">
                                    <span className="sr-only">{t('b2b::admin_types.column.actions')}</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((type) => (
                                <Row
                                    key={type.id}
                                    kind={kind}
                                    type={type}
                                    actions={actions}
                                    open={(action, trigger) => {
                                        opener.current = trigger;
                                        setDialog({ action, type });
                                    }}
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );

    return (
        <AdminLayout
            title={t(`b2b::admin_types.title.${kind}`)}
            subtitle={t(`b2b::admin_types.subtitle.${kind}`, { store: storeName })}
            action={
                actions.mayAdd ? (
                    <Button type="button" onClick={add} data-test="add-type">
                        {t(`b2b::admin_types.list.${kind}.add`)}
                    </Button>
                ) : null
            }
        >
            {tabs.length > 1 ? (
                <Tabs value={kind} activationMode="manual" className="gap-6">
                    <TabsList variant="line" aria-label={t('b2b::admin_types.tabs')} className="h-10 w-full justify-start overflow-x-auto border-b border-line p-0">
                        {tabs.map((tab) => (
                            <TabsTrigger key={tab.kind} value={tab.kind} asChild className="flex-none px-3 text-label-14">
                                <Link
                                    href={HREF[tab.kind]}
                                    data-test={`tab-${tab.kind}`}
                                    // Enter follows the link as any link does; Space, which a link
                                    // ignores, opens it too, as a tab should (Geist's Tabs).
                                    onKeyDown={(event: KeyboardEvent<HTMLAnchorElement>) => {
                                        if (event.key === ' ') {
                                            event.preventDefault();
                                            router.visit(HREF[tab.kind]);
                                        }
                                    }}
                                >
                                    {tab.title}
                                </Link>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    <TabsContent value={kind}>{list}</TabsContent>
                </Tabs>
            ) : (
                list
            )}

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'rename' || dialog.action === 'move') ? (
                <TypeFormModal mode={dialog.action} kind={kind} type={dialog.type} open onOpenChange={close} nextPosition={nextPosition} returnFocusTo={opener} />
            ) : null}
            {dialog !== null && dialog.action === 'deactivate' && dialog.type !== null ? (
                <DeactivateModal
                    kind={kind}
                    type={dialog.type}
                    others={types.filter((each) => each.active && each.id !== dialog.type?.id)}
                    mayIntoNew={actions.mayDeactivateIntoNew}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                />
            ) : null}
            {dialog !== null && dialog.action === 'transfer' && dialog.type !== null ? (
                <TransferModal type={dialog.type} others={types.filter((each) => each.active && each.id !== dialog.type?.id)} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </AdminLayout>
    );
}

function Row({
    kind,
    type,
    actions,
    open,
}: {
    kind: Kind;
    type: StaffTypeRowData;
    actions: StaffTypeListPage['actions'];
    open: (action: 'rename' | 'move' | 'deactivate' | 'transfer', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const url = `${HREF[kind]}/${type.id}`;
    const name = nameIn(locale, type.nameAr, type.nameEn);
    const transfer = kind === 'company' && actions.mayTransfer && type.active;
    const nobodyToMove = (type.holders ?? 0) === 0;
    const require = kind === 'document' && actions.mayUpdate && type.required !== null;
    const activate = actions.mayDeactivate && !type.active;
    const deactivate = actions.mayDeactivate && type.active;
    const anything = actions.mayUpdate || transfer || activate || deactivate;
    // An action taken at once from the menu says it is under way on the row's own trigger.
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const show = (action: 'rename' | 'move' | 'deactivate' | 'transfer') => open(action, more.current);

    const at = (path: string, data: Record<string, boolean> = {}) =>
        router.post(`${url}/${path}`, data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <TableRow data-test={`type-${type.id}`}>
            <TableCell className="tw-figure text-ink-muted" data-test="type-position">
                {figure(locale, type.position)}
            </TableCell>
            {locale === 'ar' ? (
                <>
                    <TableCell>
                        <span dir="rtl">{type.nameAr}</span>
                    </TableCell>
                    <TableCell>
                        <span dir="ltr">{type.nameEn}</span>
                    </TableCell>
                </>
            ) : (
                <>
                    <TableCell>
                        <span dir="ltr">{type.nameEn}</span>
                    </TableCell>
                    <TableCell>
                        <span dir="rtl">{type.nameAr}</span>
                    </TableCell>
                </>
            )}
            <TableCell>
                <Badge className={tone(type.active ? 'green-subtle' : 'gray-subtle')} data-test="type-state">
                    {type.active ? t('b2b::admin_types.state.active') : t(`b2b::admin_types.state.${type.inactiveDisplay ?? 'HIDDEN'}`)}
                </Badge>
            </TableCell>
            {kind === 'company' ? (
                <TableCell className="tw-figure text-end" data-test="type-holders">
                    {figure(locale, type.holders ?? 0)}
                </TableCell>
            ) : (
                <TableCell data-test="type-required">{type.required ? t('b2b::admin_types.required.yes') : t('b2b::admin_types.required.no')}</TableCell>
            )}
            <TableCell className="text-end">
                {anything ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                ref={more}
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`${t('ui.more_actions')}: ${name}`}
                                aria-busy={busy || undefined}
                                title={t('ui.more_actions')}
                                data-test={`type-actions-${type.id}`}
                            >
                                {/* Busy, the spinner stands in for the dots, as Geist's loading button does. */}
                                {busy ? <Spinner aria-hidden="true" role={undefined} aria-label={undefined} /> : <MoreHorizontal aria-hidden="true" />}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            {actions.mayUpdate ? (
                                <>
                                    <DropdownMenuItem onSelect={() => show('rename')} data-test="rename-type">
                                        {t(`b2b::admin_types.list.${kind}.rename`)}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => show('move')} data-test="move-type">
                                        {t(`b2b::admin_types.list.${kind}.move`)}
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                            {require ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => at('require', { required: !type.required })} data-test="require-type">
                                    {type.required ? t('b2b::admin_types.list.document.make_optional') : t('b2b::admin_types.list.document.make_required')}
                                </DropdownMenuItem>
                            ) : null}
                            {transfer ? (
                                // Out of reach while no company holds the type, its reason written
                                // under it: still in the menu, so nobody wonders where it went.
                                <DropdownMenuItem
                                    aria-disabled={nobodyToMove || undefined}
                                    className={cn(nobodyToMove && 'cursor-not-allowed')}
                                    onSelect={(event) => {
                                        if (nobodyToMove) {
                                            event.preventDefault();

                                            return;
                                        }
                                        show('transfer');
                                    }}
                                    data-test="transfer-type"
                                >
                                    <span className="grid gap-0.5">
                                        {/* The name dimmed; the reason in full ink, as on the roles page. */}
                                        <span className={nobodyToMove ? 'opacity-60' : undefined}>{t('b2b::admin_types.list.company.transfer')}</span>
                                        {nobodyToMove ? <span className="text-copy-12 text-ink-muted">{t('b2b::admin_types.list.company.transfer_none')}</span> : null}
                                    </span>
                                </DropdownMenuItem>
                            ) : null}
                            {activate ? (
                                <DropdownMenuItem disabled={busy} onSelect={() => at('activate')} data-test="activate-type">
                                    {t(`b2b::admin_types.list.${kind}.activate`)}
                                </DropdownMenuItem>
                            ) : null}
                            {/* The destructive item last, after a divider (Geist's menu rules). */}
                            {deactivate ? (
                                <>
                                    {actions.mayUpdate || transfer ? <DropdownMenuSeparator /> : null}
                                    <DropdownMenuItem variant="destructive" onSelect={() => show('deactivate')} data-test="deactivate-type">
                                        {t(`b2b::admin_types.list.${kind}.deactivate`)}
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </TableCell>
        </TableRow>
    );
}
