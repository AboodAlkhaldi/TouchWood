import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { PermissionPicker } from '@/components/PermissionPicker';
import { Button, ButtonLink, Input, Note } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { RoleEditorPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| D3 - making a role, or changing one (frontend.md §3.4), in Geist's fields and buttons (1.10).
|
| Both names are asked for on one screen, whichever language the panel is being read in, because a
| role has to be readable to everyone who will ever see it - not only to whoever made it.
|
| A role's level cannot change after it is made: an admin role holds the management actions, and
| moving a role between levels would silently change what its holders may do. Making a new one is
| the honest way to do that.
*/

type Props = RoleEditorPage;

export default function Edit({ id, nameAr, nameEn, level, permissions, groups, chosen, holderCount }: Props) {
    const t = useTranslator();
    const form = useForm({ name_ar: nameAr, name_en: nameEn, level, permissions: chosen });

    const isNew = id === null;

    return (
        <AdminLayout
            title={t(isNew ? 'access::roles.new_title' : 'access::roles.edit_title')}
            subtitle={t(`access::roles.level_${level.toLowerCase()}`)}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(isNew ? '/admin/roles' : `/admin/roles/${id}`);
                }}
                className="grid max-w-4xl gap-6"
            >
                <FormError />

                {/* Said before saving, not after: changing a saved role changes it for everyone
                    who holds it (frontend.md §3.4). A consequence to acknowledge, so Geist's
                    warning Note. */}
                {!isNew && holderCount > 0 ? (
                    <Note variant="warning">{t('access::roles.holders_warning', { count: holderCount })}</Note>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        id="name_ar"
                        label={t('access::roles.name_ar')}
                        error={form.errors.name_ar}
                        dir="rtl"
                        required
                        value={form.data.name_ar}
                        onChange={(event) => form.setData('name_ar', event.target.value)}
                    />

                    <Input
                        id="name_en"
                        label={t('access::roles.name_en')}
                        error={form.errors.name_en}
                        dir="ltr"
                        required
                        value={form.data.name_en}
                        onChange={(event) => form.setData('name_en', event.target.value)}
                    />
                </div>

                {!isNew ? <p className="text-copy-13 text-ink-muted">{t('access::roles.level_locked')}</p> : null}

                <PermissionPicker
                    permissions={permissions}
                    groups={groups}
                    chosen={form.data.permissions}
                    onChange={(next) => form.setData('permissions', next)}
                />

                <div className="flex gap-2">
                    <Button typeName="submit" loading={form.processing}>
                        {t('access::roles.save')}
                    </Button>
                    <ButtonLink href={isNew ? '/admin/roles' : `/admin/roles/${id}`} type="secondary">
                        {t('access::roles.cancel')}
                    </ButtonLink>
                </div>
            </form>
        </AdminLayout>
    );
}
