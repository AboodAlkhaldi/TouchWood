import { useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { ActionButton } from '@/components/ActionButton';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { AttributeData, AttributesPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MoreButtonOff, StateBadge, nameIn, useAllStoresReason, useLocale } from '../parts';
import { AttributeDialog, AttributeMenu, DeleteAttributeDialog } from './AttributeDialog';

/*
| The attributes screen (catalog.md §1.7, §4.4 S3): one shared library - its name, its job, its unit,
| whether it is a colour, how many values it has, its state. **The whole row opens the attribute**,
| where its details are edited and its values kept (and a colour's swatches, so there is no Colours
| screen - owner, 2026-10-07). Every change needs `catalog.attribute.manage` with All stores (§3); for
| anyone else its buttons stay in sight, out of reach, with that reason (P2).
*/

type Dialog = { action: 'add' | 'delete'; attribute: AttributeData | null } | null;

export default function Index({ attributes, mayChange }: AttributesPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const nextPosition = attributes.reduce((highest, each) => Math.max(highest, each.position), 0) + 10;
    const add = () => setDialog({ action: 'add', attribute: null });
    const reason = useAllStoresReason(mayChange);

    return (
        <AdminLayout
            title={t('catalog::admin_attributes.title')}
            subtitle={t('catalog::admin_attributes.subtitle')}
            action={
                <ActionButton type="button" onClick={add} disabledReason={reason} data-test="add-attribute">
                    {t('catalog::admin_attributes.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                {attributes.length === 0 ? (
                    <Empty className="material-base" data-test="attributes-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_attributes.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_attributes.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={reason}>
                                {t('catalog::admin_attributes.add')}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_attributes.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead className="w-12">
                                        <span aria-hidden="true">#</span>
                                        <span className="sr-only">{t('catalog::admin.column.position')}</span>
                                    </TableHead>
                                    <TableHead>{t('catalog::admin.column.name')}</TableHead>
                                    <TableHead>{t('catalog::admin_attributes.column.kind')}</TableHead>
                                    <TableHead>{t('catalog::admin_attributes.column.unit')}</TableHead>
                                    <TableHead>{t('catalog::admin_attributes.colour')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin_attributes.column.values')}</TableHead>
                                    <TableHead>{t('catalog::admin.column.state')}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attributes.map((attribute) => {
                                    const name = nameIn(locale, attribute.nameAr, attribute.nameEn);
                                    const other = locale === 'ar' ? attribute.nameEn : attribute.nameAr;

                                    return (
                                        <TableRow key={attribute.id} className="relative hover:bg-surface-sunken" data-test={`attribute-${attribute.id}`}>
                                            <TableCell className="tw-figure text-ink-muted">{figure(locale, attribute.position)}</TableCell>
                                            <TableCell>
                                                {/* The whole row opens the attribute: the name's link stretches over it. */}
                                                <span className="grid">
                                                    <Link href={`/admin/attributes/${attribute.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]">
                                                        {name}
                                                    </Link>
                                                    <span className="text-copy-12 text-ink-muted" dir={locale === 'ar' ? 'ltr' : 'rtl'}>
                                                        {other}
                                                    </span>
                                                </span>
                                            </TableCell>
                                            <TableCell>{t(`catalog::admin_attributes.kind.${attribute.kind}`)}</TableCell>
                                            <TableCell>{(locale === 'ar' ? attribute.unitAr : attribute.unitEn) ?? '—'}</TableCell>
                                            <TableCell>{attribute.isColour ? t('catalog::admin_attributes.colour_yes') : '—'}</TableCell>
                                            <TableCell className="tw-figure text-end">{attribute.kind === 'INFORMATIONAL' ? '—' : figure(locale, attribute.values)}</TableCell>
                                            <TableCell>
                                                <StateBadge active={attribute.active} />
                                            </TableCell>
                                            <TableCell className="relative z-10 text-end">
                                                {reason !== undefined ? (
                                                    <MoreButtonOff name={name} reason={reason} />
                                                ) : (
                                                    <AttributeMenu
                                                        attribute={attribute}
                                                        name={name}
                                                        open={(action, trigger) => {
                                                            opener.current = trigger;
                                                            setDialog({ action, attribute });
                                                        }}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog !== null && dialog.action === 'add' ? <AttributeDialog nextPosition={nextPosition} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog !== null && dialog.action === 'delete' && dialog.attribute !== null ? (
                <DeleteAttributeDialog attribute={dialog.attribute} open onOpenChange={close} returnFocusTo={opener} />
            ) : null}
        </AdminLayout>
    );
}
