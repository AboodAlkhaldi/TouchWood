import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Ellipsis } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import {
    Badge,
    Button,
    EmptyState,
    Menu,
    MenuDivider,
    MenuItem,
    Note,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
    Tabs,
    type TabItem,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { StaffTypeListPage, StaffTypeRowData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { figure, FormNote, nameIn, useLocale } from '../shared';
import { DeactivateModal, TransferModal, TypeFormModal, type Kind } from './Modals';

/*
| The types page (b2b.md §1.3, §4.6, amendment 21): the company types and the document types of the
| store in the panel's header, a tab each. Another store's lists are reached by changing the store in
| the header (frontend.md §2.2).
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
    const kind: Kind = listed === 'document' ? 'document' : 'company';
    const [dialog, setDialog] = useState<Dialog>(null);
    const [reviewing, setReviewing] = useState(false);
    const close = (open: boolean) => (open ? undefined : setDialog(null));

    const tabs: TabItem[] = [
        ...(actions.mayReadCompanyTypes
            ? [{ title: t('b2b::admin_types.title.company'), href: HREF.company, active: kind === 'company', 'data-test': 'tab-company' }]
            : []),
        ...(actions.mayReadDocumentTypes
            ? [{ title: t('b2b::admin_types.title.document'), href: HREF.document, active: kind === 'document', 'data-test': 'tab-document' }]
            : []),
    ];

    const nextPosition = types.reduce((highest, type) => Math.max(highest, type.position), 0) + 10;

    return (
        <AdminLayout
            title={t(`b2b::admin_types.title.${kind}`)}
            subtitle={t(`b2b::admin_types.subtitle.${kind}`, { store: storeName })}
            action={
                actions.mayAdd ? (
                    <Button onClick={() => setDialog({ action: 'add', type: null })} data-test="add-type">
                        {t(`b2b::admin_types.list.${kind}.add`)}
                    </Button>
                ) : null
            }
        >
            <div className="grid gap-4">
                {tabs.length > 1 ? <Tabs aria-label={t('b2b::admin_types.tabs')} tabs={tabs} /> : null}

                <FormNote />

                {copiedNotReviewed ? (
                    <Note
                        variant="warning"
                        label={t('b2b::admin_types.copied.label')}
                        data-test="copied-notice"
                        action={
                            actions.mayMarkReviewed ? (
                                <Button
                                    type="secondary"
                                    size="small"
                                    loading={reviewing}
                                    onClick={() =>
                                        router.post(
                                            '/admin/type-lists/reviewed',
                                            {},
                                            { preserveScroll: true, onStart: () => setReviewing(true), onFinish: () => setReviewing(false) },
                                        )
                                    }
                                    data-test="mark-reviewed"
                                >
                                    {t('b2b::admin_types.copied.button')}
                                </Button>
                            ) : undefined
                        }
                    >
                        {t('b2b::admin_types.copied.body')}
                    </Note>
                ) : null}

                {types.length === 0 ? (
                    <EmptyState
                        title={t(`b2b::admin_types.empty.${kind}`)}
                        description={actions.mayAdd ? t('b2b::admin_types.empty.add') : t('b2b::admin_types.empty.none')}
                        data-test="types-empty"
                    />
                ) : (
                    <Table aria-label={t(`b2b::admin_types.title.${kind}`)}>
                        <TableHeader>
                            <TableRow>
                                <TableHead numeric>{t('b2b::admin_types.column.position')}</TableHead>
                                <TableHead>{t('b2b::admin_types.column.name_ar')}</TableHead>
                                <TableHead>{t('b2b::admin_types.column.name_en')}</TableHead>
                                <TableHead>{t('b2b::admin_types.column.status')}</TableHead>
                                {kind === 'company' ? (
                                    <TableHead numeric>{t('b2b::admin_types.column.holders')}</TableHead>
                                ) : (
                                    <TableHead>{t('b2b::admin_types.column.required')}</TableHead>
                                )}
                                <TableHead>
                                    <span className="sr-only">{t('b2b::admin_types.column.actions')}</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((type) => (
                                <Row key={type.id} kind={kind} type={type} actions={actions} open={(action) => setDialog({ action, type })} />
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>

            {dialog !== null && (dialog.action === 'add' || dialog.action === 'rename' || dialog.action === 'move') ? (
                <TypeFormModal mode={dialog.action} kind={kind} type={dialog.type} open onOpenChange={close} nextPosition={nextPosition} />
            ) : null}
            {dialog !== null && dialog.action === 'deactivate' && dialog.type !== null ? (
                <DeactivateModal
                    kind={kind}
                    type={dialog.type}
                    others={types.filter((each) => each.active && each.id !== dialog.type?.id)}
                    mayIntoNew={actions.mayDeactivateIntoNew}
                    open
                    onOpenChange={close}
                />
            ) : null}
            {dialog !== null && dialog.action === 'transfer' && dialog.type !== null ? (
                <TransferModal type={dialog.type} others={types.filter((each) => each.active && each.id !== dialog.type?.id)} open onOpenChange={close} />
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
    open: (action: 'rename' | 'move' | 'deactivate' | 'transfer') => void;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const url = `${HREF[kind]}/${type.id}`;
    const transfer = kind === 'company' && actions.mayTransfer && type.active;
    const require = kind === 'document' && actions.mayUpdate && type.required !== null;
    const anything = actions.mayUpdate || actions.mayDeactivate || transfer;
    const activate = actions.mayDeactivate && !type.active;
    const deactivate = actions.mayDeactivate && type.active;
    // An action taken at once from the menu says it is under way on the row's own trigger, as Geist's
    // `loading` does on a button.
    const [busy, setBusy] = useState(false);

    const at = (path: string, data: Record<string, boolean> = {}) =>
        router.post(`${url}/${path}`, data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <TableRow data-test={`type-${type.id}`}>
            <TableCell numeric>{figure(locale, type.position)}</TableCell>
            <TableCell>
                <span dir="rtl">{type.nameAr}</span>
            </TableCell>
            <TableCell>
                <span dir="ltr">{type.nameEn}</span>
            </TableCell>
            <TableCell>
                <Badge variant={type.active ? 'green-subtle' : 'gray-subtle'} data-test="type-state">
                    {type.active ? t('b2b::admin_types.state.active') : t(`b2b::admin_types.state.${type.inactiveDisplay ?? 'HIDDEN'}`)}
                </Badge>
            </TableCell>
            {kind === 'company' ? (
                <TableCell numeric data-test="type-holders">
                    {figure(locale, type.holders ?? 0)}
                </TableCell>
            ) : (
                <TableCell data-test="type-required">{type.required ? t('b2b::admin_types.required.yes') : t('b2b::admin_types.required.no')}</TableCell>
            )}
            <TableCell className="w-12 text-end">
                {anything ? (
                    <Menu
                        trigger={
                            <Button
                                type="tertiary"
                                size="small"
                                svgOnly
                                loading={busy}
                                aria-label={t('b2b::admin_types.row_actions', { name: nameIn(locale, type.nameAr, type.nameEn) })}
                                data-test={`type-actions-${type.id}`}
                            >
                                <Ellipsis className="size-4" />
                            </Button>
                        }
                    >
                        {actions.mayUpdate ? (
                            <>
                                <MenuItem onSelect={() => open('rename')} data-test="rename-type">
                                    {t(`b2b::admin_types.list.${kind}.rename`)}
                                </MenuItem>
                                <MenuItem onSelect={() => open('move')} data-test="move-type">
                                    {t(`b2b::admin_types.list.${kind}.move`)}
                                </MenuItem>
                            </>
                        ) : null}
                        {require ? (
                            <MenuItem onSelect={() => at('require', { required: !type.required })} data-test="require-type">
                                {type.required ? t('b2b::admin_types.list.document.make_optional') : t('b2b::admin_types.list.document.make_required')}
                            </MenuItem>
                        ) : null}
                        {transfer ? (
                            <MenuItem
                                onSelect={() => open('transfer')}
                                lockedReason={(type.holders ?? 0) === 0 ? t('b2b::admin_types.list.company.transfer_none') : undefined}
                                data-test="transfer-type"
                            >
                                {t('b2b::admin_types.list.company.transfer')}
                            </MenuItem>
                        ) : null}
                        {activate ? (
                            <MenuItem onSelect={() => at('activate')} data-test="activate-type">
                                {t(`b2b::admin_types.list.${kind}.activate`)}
                            </MenuItem>
                        ) : null}
                        {/* The destructive item last, after a divider (Geist's menu rules). */}
                        {deactivate ? (
                            <>
                                {actions.mayUpdate || transfer ? <MenuDivider /> : null}
                                <MenuItem type="error" onSelect={() => open('deactivate')} data-test="deactivate-type">
                                    {t(`b2b::admin_types.list.${kind}.deactivate`)}
                                </MenuItem>
                            </>
                        ) : null}
                    </Menu>
                ) : null}
            </TableCell>
        </TableRow>
    );
}
