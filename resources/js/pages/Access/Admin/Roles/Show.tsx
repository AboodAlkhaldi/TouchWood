import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import {
    Button,
    ButtonLink,
    DestructiveActionModal,
    EmptyState,
    Entity,
    Fieldset,
    Input,
    Select,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { RolePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D2 - one role: what it allows, and who holds it (frontend.md §3.4).
|
| The holders shown are only the ones this reader manages, with the total beside them, so an admin
| can see that a role reaches further than the people they can name (access.md amendment 8).
|
| D4 lives here too: deleting a role anybody holds needs a saved role of the same level to move them
| to. Access refuses without one; the screen asks for it first so nobody meets that refusal - and
| when there is none to move them to, the Delete button says so and stays inert, as Geist explains
| every disabled button (frontend.md 1.10).
|
| Deleting a role is confirmed in Geist's Destructive Action Modal: removing a role is serious
| enough to pause on, so the person types the role's name before it goes. The replacement is picked
| in the same dialog, because that is where the consequence is read.
*/

type Props = RolePage;

export default function Show({
    id,
    name,
    nameAr,
    nameEn,
    level,
    permissions,
    groups,
    holderCount,
    holders,
    editable,
    replacements,
}: Props) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [deleting, setDeleting] = useState(false);
    const [cloning, setCloning] = useState(false);
    const [refreshing, setRefreshing] = useState(false);

    const remove = useForm({ replacement: replacements[0]?.id ?? '' });
    const copy = useForm({ name_ar: `${nameAr} (2)`, name_en: `${nameEn} (2)` });

    // Nobody to move the holders to: the delete cannot happen, so it is never offered as if it could.
    const cannotDelete = holderCount > 0 && replacements.length === 0;

    return (
        <AdminLayout
            title={name}
            subtitle={t(`access::roles.level_${level.toLowerCase()}`)}
            action={
                editable ? (
                    <div className="flex flex-wrap gap-2">
                        <ButtonLink href={`/admin/roles/${id}/edit`}>{t('access::roles.edit')}</ButtonLink>
                        <Button type="secondary" onClick={() => setCloning((open) => !open)}>
                            {t('access::roles.clone')}
                        </Button>
                        <Button
                            type="secondary"
                            loading={refreshing}
                            onClick={() =>
                                router.post(
                                    `/admin/roles/${id}/refresh`,
                                    {},
                                    { onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) },
                                )
                            }
                        >
                            {t('access::roles.refresh')}
                        </Button>
                        <Button
                            type="error"
                            disabledReason={cannotDelete ? t('access::roles.delete_none_left') : undefined}
                            onClick={() => setDeleting(true)}
                        >
                            {t('access::roles.delete')}
                        </Button>
                    </div>
                ) : (
                    <p className="text-copy-13 text-ink-muted">{t('access::roles.not_editable')}</p>
                )
            }
        >
            <div className="grid gap-6">
                <FormError />

                {cloning ? (
                    <div className="max-w-xl">
                        <Fieldset
                            as="form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                copy.post(`/admin/roles/${id}/clone`);
                            }}
                            title={t('access::roles.clone')}
                            footerAction={
                                <Button typeName="submit" loading={copy.processing}>
                                    {t('access::roles.clone')}
                                </Button>
                            }
                        >
                            <Input
                                id="clone_ar"
                                label={t('access::roles.name_ar')}
                                error={copy.errors.name_ar}
                                dir="rtl"
                                value={copy.data.name_ar}
                                onChange={(event) => copy.setData('name_ar', event.target.value)}
                            />

                            <Input
                                id="clone_en"
                                label={t('access::roles.name_en')}
                                error={copy.errors.name_en}
                                dir="ltr"
                                value={copy.data.name_en}
                                onChange={(event) => copy.setData('name_en', event.target.value)}
                            />
                        </Fieldset>
                    </div>
                ) : null}

                <DestructiveActionModal
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={t('access::roles.delete_title')}
                    confirmLabel={t('access::roles.delete_confirm')}
                    verificationPhrase={name}
                    verificationLabel={t('access::roles.verification_label')}
                    loading={remove.processing}
                    error={errors.form ?? remove.errors.replacement}
                    onConfirm={() => remove.post(`/admin/roles/${id}/delete`)}
                    description={
                        <div className="grid gap-3">
                            <p>{t('access::roles.delete_body', { name })}</p>

                            {holderCount > 0 && replacements.length > 0 ? (
                                <>
                                    <p>{t('access::roles.delete_question')}</p>

                                    <Select
                                        id="replacement"
                                        label={t('access::roles.replacement')}
                                        error={remove.errors.replacement}
                                        value={remove.data.replacement}
                                        onChange={(event) => remove.setData('replacement', event.target.value)}
                                    >
                                        {replacements.map((role) => (
                                            <option key={role.id} value={role.id}>
                                                {role.name}
                                            </option>
                                        ))}
                                    </Select>
                                </>
                            ) : null}
                        </div>
                    }
                />

                <section className="grid gap-3">
                    <h2 className="text-heading-16 text-ink">{t('access::roles.actions')}</h2>

                    {groups.map((group) => {
                        const inGroup = permissions.filter((permission) => permission.group === group.key);

                        if (inGroup.length === 0) {
                            return null;
                        }

                        return (
                            <div key={group.key} className="material-base overflow-hidden">
                                <h3 className="border-b border-line px-4 py-2 text-label-13 font-medium text-ink-muted">
                                    {group.label}
                                </h3>
                                <ul className="grid gap-1 p-4">
                                    {inGroup.map((permission) => (
                                        <li key={permission.name} className="text-copy-14 text-ink">
                                            {permission.label}
                                            {permission.storeFree ? (
                                                <span className="ms-2 text-copy-13 text-ink-muted">
                                                    {t('access::roles.store_free')}
                                                </span>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </section>

                <section className="grid gap-3">
                    <div className="grid gap-1">
                        <h2 className="text-heading-16 text-ink">
                            {t('access::roles.holders')}{' '}
                            <span className="tw-figure text-ink-muted">({holderCount})</span>
                        </h2>
                        <p className="text-copy-13 text-ink-muted">{t('access::roles.holders_hint')}</p>
                    </div>

                    {holders.length === 0 ? (
                        <EmptyState title={t('access::roles.no_holders_title')} description={t('access::roles.no_holders')} />
                    ) : (
                        <ul className="material-base divide-y divide-line">
                            {holders.map((holder) => (
                                <li key={holder.staffId}>
                                    <Entity
                                        title={holder.name}
                                        description={
                                            holder.storeNames === null
                                                ? t('access::roles.every_store')
                                                : holder.storeNames.join('، ')
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
