import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import type { RolePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D2 - one role: what it allows, and who holds it (frontend.md §3.4).
|
| The holders shown are only the ones this reader manages, with the total beside them, so an admin
| can see that a role reaches further than the people they can name (access.md amendment 8).
|
| D4 lives here too: deleting a role anybody holds needs a saved role of the same level to move them
| to. Access refuses without one; the screen asks for it first so nobody meets that refusal.
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
    const [deleting, setDeleting] = useState(false);
    const [cloning, setCloning] = useState(false);

    const remove = useForm({ replacement: replacements[0]?.id ?? '' });
    const copy = useForm({ name_ar: `${nameAr} (2)`, name_en: `${nameEn} (2)` });

    return (
        <AdminLayout
            title={name}
            subtitle={t(`access::roles.level_${level.toLowerCase()}`)}
            action={
                <div className="flex flex-wrap gap-2">
                    {editable ? (
                        <>
                            <Button asChild>
                                <Link href={`/admin/roles/${id}/edit`}>{t('access::roles.edit')}</Link>
                            </Button>
                            <Button variant="outline" onClick={() => setCloning((open) => !open)}>
                                {t('access::roles.clone')}
                            </Button>
                            <Button variant="outline" onClick={() => router.post(`/admin/roles/${id}/refresh`)}>
                                {t('access::roles.refresh')}
                            </Button>
                            <Button variant="destructive" onClick={() => setDeleting((open) => !open)}>
                                {t('access::roles.delete')}
                            </Button>
                        </>
                    ) : (
                        <span className="text-xs text-ink-muted">{t('access::roles.not_editable')}</span>
                    )}
                </div>
            }
        >
            <div className="grid gap-6">
                <FormError />

                {cloning ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            copy.post(`/admin/roles/${id}/clone`);
                        }}
                        className="grid max-w-md gap-4 rounded-lg border border-line bg-surface p-4"
                    >
                        <h2 className="text-sm font-semibold text-ink">{t('access::roles.clone')}</h2>

                        <Field id="clone_ar" label={t('access::roles.name_ar')} error={copy.errors.name_ar}>
                            <Input
                                id="clone_ar"
                                dir="rtl"
                                value={copy.data.name_ar}
                                onChange={(event) => copy.setData('name_ar', event.target.value)}
                            />
                        </Field>

                        <Field id="clone_en" label={t('access::roles.name_en')} error={copy.errors.name_en}>
                            <Input
                                id="clone_en"
                                dir="ltr"
                                value={copy.data.name_en}
                                onChange={(event) => copy.setData('name_en', event.target.value)}
                            />
                        </Field>

                        <Button type="submit" disabled={copy.processing}>
                            {t('access::roles.clone')}
                        </Button>
                    </form>
                ) : null}

                {deleting ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            remove.post(`/admin/roles/${id}/delete`);
                        }}
                        className="grid max-w-md gap-4 rounded-lg border border-bad/30 bg-bad-soft p-4"
                    >
                        <h2 className="text-sm font-semibold text-bad">{t('access::roles.delete_title')}</h2>

                        {holderCount > 0 ? (
                            replacements.length === 0 ? (
                                <p className="text-sm text-bad">{t('access::roles.delete_none_left')}</p>
                            ) : (
                                <>
                                    <p className="text-sm text-ink">{t('access::roles.delete_question')}</p>

                                    <Field
                                        id="replacement"
                                        label={t('access::roles.replacement')}
                                        error={remove.errors.replacement}
                                    >
                                        <select
                                            id="replacement"
                                            value={remove.data.replacement}
                                            onChange={(event) => remove.setData('replacement', event.target.value)}
                                            className="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink"
                                        >
                                            {replacements.map((role) => (
                                                <option key={role.id} value={role.id}>
                                                    {role.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </>
                            )
                        ) : null}

                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={remove.processing || (holderCount > 0 && replacements.length === 0)}
                        >
                            {t('access::roles.delete_confirm')}
                        </Button>
                    </form>
                ) : null}

                <section className="grid gap-3">
                    <h2 className="text-sm font-semibold text-ink">{t('access::roles.actions')}</h2>

                    {groups.map((group) => {
                        const inGroup = permissions.filter((permission) => permission.group === group.key);

                        if (inGroup.length === 0) {
                            return null;
                        }

                        return (
                            <div key={group.key} className="rounded-lg border border-line bg-surface">
                                <h3 className="border-b border-line px-4 py-2 text-xs font-semibold text-ink-muted uppercase">
                                    {group.label}
                                </h3>
                                <ul className="grid gap-1 p-4">
                                    {inGroup.map((permission) => (
                                        <li key={permission.name} className="text-sm text-ink">
                                            {permission.label}
                                            {permission.storeFree ? (
                                                <span className="ms-2 text-xs text-ink-muted">
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

                <section className="grid gap-2">
                    <div className="grid gap-0.5">
                        <h2 className="text-sm font-semibold text-ink">
                            {t('access::roles.holders')}{' '}
                            <span className="tw-figure text-ink-muted">({holderCount})</span>
                        </h2>
                        <p className="text-xs text-ink-muted">{t('access::roles.holders_hint')}</p>
                    </div>

                    {holders.length === 0 ? (
                        <p className="rounded-lg border border-line bg-surface p-4 text-sm text-ink-muted">
                            {t('access::roles.no_holders')}
                        </p>
                    ) : (
                        <ul className="grid gap-1 rounded-lg border border-line bg-surface p-4">
                            {holders.map((holder) => (
                                <li key={holder.staffId} className="flex justify-between gap-4 text-sm">
                                    <span className="text-ink">{holder.name}</span>
                                    <span className="text-xs text-ink-muted">
                                        {holder.storeNames === null
                                            ? t('access::roles.every_store')
                                            : holder.storeNames.join('، ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
