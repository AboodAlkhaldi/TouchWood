import { useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { AllowedActions } from '@/components/AllowedActions';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { SelectField, TextField } from '@/components/Fields';
import { DialogError, FormError, useFreshRefusal } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { NativeSelectOption } from '@/components/ui/native-select';
import { initials } from '@/lib/initials';
import { useList } from '@/lib/list';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { RolePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D2 - one role: what it allows, and who holds it (frontend.md §3.4).
|
| The holders shown are only the ones this reader manages, with the total beside them, so an admin
| can see that a role reaches further than the people they can name (access.md amendment 8).
|
| D4 lives here too: deleting a role anybody holds needs a saved role of the same level to move them
| to. Access refuses without one; the screen asks for it first so nobody meets that refusal - and
| when there is none to move them to, Delete Role says so and does nothing, as Geist explains every
| disabled action.
|
| The header keeps one main button - Edit Role - and puts the rest in a ⋯ menu, Delete Role last
| after a divider (frontend.md §1.11, "Applied in the rebuild"). Cloning asks for the copy's two
| names in a dialog whose button repeats its title. Deleting is confirmed in Geist's Destructive
| Action Modal: removing a role is serious enough to pause on, so the person types the role's name
| before it goes; the replacement is picked in the same dialog, in its body, because that is where
| the consequence is read.
*/

type Props = RolePage;

export default function Show({ id, name, nameAr, nameEn, level, permissions, groups, holderCount, holders, editable, replacements }: Props) {
    const t = useTranslator();
    const list = useList();
    const more = useRef<HTMLButtonElement>(null);
    const [deleting, setDeleting] = useState(false);
    // Only the delete's own refusal, never an older one from Refresh or Clone (useFreshRefusal).
    const deleteRefusal = useFreshRefusal(deleting);
    const [cloning, setCloning] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const cloneFocus = useReturnFocus(cloning, more);

    const remove = useForm({ replacement: replacements[0]?.id ?? '' });
    const copy = useForm({ name_ar: `${nameAr} (2)`, name_en: `${nameEn} (2)` });

    // Nobody to move the holders to: the delete cannot happen, so it is never offered as if it could.
    const cannotDelete = holderCount > 0 && replacements.length === 0;

    const refresh = () =>
        router.post(`/admin/roles/${id}/refresh`, {}, { onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) });

    return (
        <AdminLayout
            title={name}
            subtitle={t(`access::roles.level_${level.toLowerCase()}`)}
            action={
                editable ? (
                    <div className="flex items-center gap-2">
                        <Button asChild data-test="edit-role">
                            <Link href={`/admin/roles/${id}/edit`}>{t('access::roles.edit')}</Link>
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button ref={more} variant="outline" size="icon" aria-label={t('admin.more_actions')} title={t('admin.more_actions')} data-test="more-actions">
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="min-w-56">
                                <DropdownMenuItem onSelect={() => setCloning(true)} data-test="action-clone">
                                    {`${t('access::roles.clone')}…`}
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={refresh} disabled={refreshing} data-test="action-refresh">
                                    {t('access::roles.refresh')}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    // Not Radix's `disabled`, which takes the item out of reach: a
                                    // disabled action still says why (Geist's Menu), so it stays
                                    // reachable, says it is unavailable, and choosing it does nothing.
                                    aria-disabled={cannotDelete ? 'true' : undefined}
                                    className={cannotDelete ? 'flex-col items-start gap-0.5 opacity-60' : undefined}
                                    onSelect={(event) => {
                                        if (cannotDelete) {
                                            event.preventDefault();

                                            return;
                                        }
                                        setDeleting(true);
                                    }}
                                    data-test="action-delete"
                                >
                                    {`${t('access::roles.delete')}…`}
                                    {cannotDelete ? <span className="text-copy-13 text-ink-muted">{t('access::roles.delete_none_left')}</span> : null}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-6">
                <FormError />

                {/* Neutral information about the page, not a control: Geist's secondary Note. */}
                {editable ? null : <Note variant="secondary">{t('access::roles.not_editable')}</Note>}

                <section className="grid gap-3">
                    <h2 className="text-heading-16 text-ink">{t('access::roles.actions')}</h2>

                    <AllowedActions
                        groups={groups}
                        rows={permissions.map((permission) => ({
                            key: permission.name,
                            group: permission.group,
                            label: permission.label,
                            detail: permission.storeFree ? t('access::roles.store_free') : undefined,
                        }))}
                    />
                </section>

                <section className="grid gap-3">
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">
                            {t('access::roles.holders')} <span className="tw-figure text-ink-muted">({holderCount})</span>
                        </h2>
                        <p className="text-copy-13 text-ink-muted">{t('access::roles.holders_hint')}</p>
                    </div>

                    {holders.length === 0 ? (
                        <Empty className="material-base">
                            <EmptyHeader>
                                <EmptyTitle>{t('access::roles.no_holders_title')}</EmptyTitle>
                                <EmptyDescription>{t('access::roles.no_holders')}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <ItemGroup className="material-base">
                            {holders.map((holder, index) => (
                                <div key={holder.staffId} role="listitem">
                                    {index === 0 ? null : <ItemSeparator />}
                                    <Item size="sm" className="rounded-none">
                                        <ItemMedia>
                                            <Avatar size="sm" aria-hidden="true" title={holder.name}>
                                                <AvatarFallback className="bg-brand-soft text-label-12 text-brand">{initials(holder.name)}</AvatarFallback>
                                            </Avatar>
                                        </ItemMedia>
                                        <ItemContent>
                                            <ItemTitle className="text-label-14 text-ink">{holder.name}</ItemTitle>
                                            <ItemDescription className="text-copy-13 text-ink-muted">
                                                {holder.storeNames === null ? t('access::roles.every_store') : list(holder.storeNames)}
                                            </ItemDescription>
                                        </ItemContent>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    )}
                </section>
            </div>

            <Dialog
                open={cloning}
                onOpenChange={(open) => {
                    if (!copy.processing) {
                        setCloning(open);
                    }
                }}
            >
                <DialogContent showCloseButton={false} onCloseAutoFocus={cloneFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            copy.post(`/admin/roles/${id}/clone`);
                        }}
                    >
                        <div className="grid gap-4 p-6">
                            <DialogHeader>
                                <DialogTitle className="text-heading-20 text-ink">{t('access::roles.clone')}</DialogTitle>
                            </DialogHeader>
                            <TextField
                                id="clone_ar"
                                label={t('access::roles.name_ar')}
                                error={copy.errors.name_ar}
                                dir="rtl"
                                autoFocus
                                value={copy.data.name_ar}
                                onChange={(event) => copy.setData('name_ar', event.target.value)}
                            />
                            <TextField
                                id="clone_en"
                                label={t('access::roles.name_en')}
                                error={copy.errors.name_en}
                                dir="ltr"
                                value={copy.data.name_en}
                                onChange={(event) => copy.setData('name_en', event.target.value)}
                            />
                            <DialogError open={cloning} />
                        </div>
                        <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <Button type="button" variant="outline" disabled={copy.processing} onClick={() => setCloning(false)}>
                                {t('ui.cancel')}
                            </Button>
                            <ActionButton type="submit" loading={copy.processing} data-test="clone-role">
                                {t('access::roles.clone')}
                            </ActionButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <DestructiveActionDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={t('access::roles.delete_title')}
                confirmLabel={t('access::roles.delete_confirm')}
                description={t('access::roles.delete_body', { name })}
                irreversibleDescription={t('access::roles.delete_irreversible', { name })}
                verificationPhrase={name}
                verificationLabel={t('access::roles.verification_label')}
                loading={remove.processing}
                // Only the refusal: a field's own error is said under the field.
                error={deleteRefusal}
                onConfirm={() => remove.post(`/admin/roles/${id}/delete`)}
                returnFocusTo={more}
                body={
                    holderCount > 0 && replacements.length > 0 ? (
                        <>
                            <p className="text-copy-14 text-ink-muted">{t('access::roles.delete_question')}</p>
                            <SelectField
                                id="replacement"
                                label={t('access::roles.replacement')}
                                error={remove.errors.replacement}
                                value={remove.data.replacement}
                                onChange={(event) => remove.setData('replacement', event.target.value)}
                            >
                                {replacements.map((role) => (
                                    <NativeSelectOption key={role.id} value={role.id}>
                                        {role.name}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                        </>
                    ) : undefined
                }
            />
        </AdminLayout>
    );
}
