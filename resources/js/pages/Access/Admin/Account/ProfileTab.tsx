import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, FieldMessage, Fieldset, Input, Select } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import { EmailBlock } from '@/pages/Access/Admin/Account/EmailBlock';
import { PhoneBlock } from '@/pages/Access/Admin/Account/PhoneBlock';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1 - the account tab (frontend.md §3.2), in Geist (1.10).
|
| The picture, the profile, the communication language, and beside them the two things that cannot
| simply be typed over: the email, which travels by link, and the phone, which travels by code.
|
| The language here is the **communication** language - what emails and sign-in codes are written in
| (Access amendment 16). It is labelled apart from the ع / EN toggle in the sidebar, which changes
| only what this browser displays and is nobody else's business. Confusing the two is the whole
| reason the spec asks for the label.
|
| The profile is one Geist Fieldset that is itself the form: its fields, and in its footer the one
| button that saves them.
*/

type Props = {
    account: AccountPage;
};

/** The file chooser's label, drawn as Geist's small secondary button, with the ring the hidden input would have. */
const CHOOSER =
    'inline-flex h-8 w-fit cursor-pointer items-center rounded-[var(--tw-radius)] bg-surface px-2 text-button-14 text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] transition-colors hover:bg-surface-sunken has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand';

export function ProfileTab({ account }: Props) {
    const t = useTranslator();
    const [chosen, setChosen] = useState<string | null>(null);

    const form = useForm<{
        first_name: string;
        last_name: string;
        job_title: string;
        date_of_birth: string;
        country: string;
        address: string;
        locale: string;
        avatar: File | null;
        remove_avatar: boolean;
    }>({
        first_name: account.firstName,
        last_name: account.lastName,
        job_title: account.jobTitle,
        date_of_birth: account.dateOfBirth,
        country: account.country,
        address: account.address ?? '',
        locale: account.communicationLocale,
        avatar: null,
        remove_avatar: false,
    });

    return (
        <div className="grid gap-6">
            <Fieldset
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/account/profile', { preserveScroll: true });
                }}
                title={t('access::account.profile_title')}
                footerAction={
                    <Button typeName="submit" loading={form.processing} data-test="save-profile">
                        {t('access::account.save')}
                    </Button>
                }
            >
                {/* The picture. What is stored is never sent back from here: the form says
                    "a new one", "none" or nothing at all, and the server keeps the rest. A media id
                    coming back from a browser would let anybody wear any public image we hold. */}
                <div className="grid gap-2">
                    <p className="text-label-14 font-medium text-ink">{t('access::account.picture')}</p>
                    <p className="text-copy-13 text-ink-muted">{t('access::account.picture_hint')}</p>

                    <div className="mt-1 flex flex-wrap items-center gap-4">
                        {account.avatarUrl === null || form.data.remove_avatar ? (
                            <span className="grid size-16 place-items-center rounded-pill bg-surface-sunken text-heading-20 text-ink-muted">
                                {account.firstName.slice(0, 1)}
                            </span>
                        ) : (
                            <img
                                src={account.avatarUrl}
                                alt=""
                                className="size-16 rounded-pill object-cover"
                            />
                        )}

                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <label className={CHOOSER}>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        className="sr-only"
                                        aria-describedby={form.errors.avatar ? 'avatar-error' : undefined}
                                        onChange={(event) => {
                                            const file = event.target.files?.[0] ?? null;
                                            form.setData((was) => ({
                                                ...was,
                                                avatar: file,
                                                remove_avatar: false,
                                            }));
                                            setChosen(file === null ? null : file.name);
                                        }}
                                    />
                                    {t(
                                        account.avatarUrl === null
                                            ? 'access::account.choose_picture'
                                            : 'access::account.replace_picture',
                                    )}
                                </label>

                                {account.avatarUrl !== null && !form.data.remove_avatar ? (
                                    <Button
                                        type="secondary"
                                        size="small"
                                        onClick={() => {
                                            form.setData((was) => ({
                                                ...was,
                                                avatar: null,
                                                remove_avatar: true,
                                            }));
                                            setChosen(null);
                                        }}
                                    >
                                        {t('access::account.remove_picture')}
                                    </Button>
                                ) : null}
                            </div>

                            <p className="text-copy-13 text-ink-muted">
                                {form.data.remove_avatar
                                    ? t('access::account.picture_removed')
                                    : chosen !== null
                                      ? t('access::account.picture_chosen', { name: chosen })
                                      : account.avatarUrl === null
                                        ? t('access::account.no_picture')
                                        : ''}
                            </p>
                        </div>
                    </div>

                    <FieldMessage id="avatar" error={form.errors.avatar} />
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Input
                        id="first_name"
                        name="first_name"
                        label={t('access::account.first_name')}
                        error={form.errors.first_name}
                        required
                        value={form.data.first_name}
                        onChange={(event) => form.setData('first_name', event.target.value)}
                    />

                    <Input
                        id="last_name"
                        name="last_name"
                        label={t('access::account.last_name')}
                        error={form.errors.last_name}
                        required
                        value={form.data.last_name}
                        onChange={(event) => form.setData('last_name', event.target.value)}
                    />

                    <Input
                        id="job_title"
                        name="job_title"
                        label={t('access::account.job_title')}
                        error={form.errors.job_title}
                        required
                        value={form.data.job_title}
                        onChange={(event) => form.setData('job_title', event.target.value)}
                    />

                    {/* A date input speaks the browser's own language and always hands back
                        YYYY-MM-DD, which is what Access asks for - so nothing here parses a
                        date, and an Arabic reader still gets an Arabic calendar. */}
                    <Input
                        id="date_of_birth"
                        name="date_of_birth"
                        type="date"
                        label={t('access::account.date_of_birth')}
                        error={form.errors.date_of_birth}
                        required
                        dir="ltr"
                        value={form.data.date_of_birth}
                        onChange={(event) => form.setData('date_of_birth', event.target.value)}
                    />

                    <Select
                        id="country"
                        name="country"
                        label={t('access::account.country')}
                        error={form.errors.country}
                        required
                        value={form.data.country}
                        onChange={(event) => form.setData('country', event.target.value)}
                    >
                        {account.countries.map((country) => (
                            <option key={country.code} value={country.code}>
                                {country.name}
                            </option>
                        ))}
                    </Select>

                    <Select
                        id="locale"
                        name="locale"
                        label={t('access::account.communication_language')}
                        helper={t('access::account.communication_language_hint')}
                        error={form.errors.locale}
                        required
                        value={form.data.locale}
                        onChange={(event) => form.setData('locale', event.target.value)}
                    >
                        <option value="ar">{t('access::account.language.ar')}</option>
                        <option value="en">{t('access::account.language.en')}</option>
                    </Select>
                </div>

                <Input
                    id="address"
                    name="address"
                    label={t('access::account.address')}
                    helper={t('access::account.address_hint')}
                    error={form.errors.address}
                    value={form.data.address}
                    onChange={(event) => form.setData('address', event.target.value)}
                />
            </Fieldset>

            <EmailBlock account={account} />
            <PhoneBlock account={account} />
        </div>
    );
}
