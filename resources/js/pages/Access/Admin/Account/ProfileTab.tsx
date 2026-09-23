import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslator } from '@/lib/t';
import { EmailBlock } from '@/pages/Access/Admin/Account/EmailBlock';
import { PhoneBlock } from '@/pages/Access/Admin/Account/PhoneBlock';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1 - the account tab (frontend.md §3.2).
|
| The picture, the profile, the communication language, and beside them the two things that cannot
| simply be typed over: the email, which travels by link, and the phone, which travels by code.
|
| The language here is the **communication** language - what emails and sign-in codes are written in
| (Access amendment 16). It is labelled apart from the ع / EN toggle in the sidebar, which changes
| only what this browser displays and is nobody else's business. Confusing the two is the whole
| reason the spec asks for the label.
*/

type Props = {
    account: AccountPage;
};

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
        locale: account.locale,
        avatar: null,
        remove_avatar: false,
    });

    return (
        <div className="grid gap-6">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/admin/account/profile', { preserveScroll: true });
                }}
                className="grid gap-5 rounded-lg border border-line bg-surface p-6 shadow-card"
            >
                {/* The picture. What is stored is never sent back from here: the form says
                    "a new one", "none" or nothing at all, and the server keeps the rest. A media id
                    coming back from a browser would let anybody wear any public image we hold. */}
                <div className="grid gap-2">
                    <p className="text-sm font-medium text-ink">{t('access::account.picture')}</p>
                    <p className="text-xs text-ink-muted">{t('access::account.picture_hint')}</p>

                    <div className="mt-1 flex flex-wrap items-center gap-4">
                        {account.avatarUrl === null || form.data.remove_avatar ? (
                            <span className="grid size-16 place-items-center rounded-pill bg-surface-sunken text-lg text-ink-muted">
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
                            <label className="inline-flex w-fit cursor-pointer items-center rounded-md border border-line-strong px-3 py-2 text-xs text-ink transition-colors hover:border-brand hover:text-brand">
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="sr-only"
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
                                <button
                                    type="button"
                                    onClick={() => {
                                        form.setData((was) => ({
                                            ...was,
                                            avatar: null,
                                            remove_avatar: true,
                                        }));
                                        setChosen(null);
                                    }}
                                    className="w-fit text-xs text-bad hover:underline"
                                >
                                    {t('access::account.remove_picture')}
                                </button>
                            ) : null}

                            <p className="text-xs text-ink-muted">
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

                    {form.errors.avatar ? (
                        <p role="alert" className="text-xs text-bad">
                            {form.errors.avatar}
                        </p>
                    ) : null}
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="first_name"
                        label={t('access::account.first_name')}
                        error={form.errors.first_name}
                    >
                        <Input
                            id="first_name"
                            name="first_name"
                            required
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="last_name"
                        label={t('access::account.last_name')}
                        error={form.errors.last_name}
                    >
                        <Input
                            id="last_name"
                            name="last_name"
                            required
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="job_title"
                        label={t('access::account.job_title')}
                        error={form.errors.job_title}
                    >
                        <Input
                            id="job_title"
                            name="job_title"
                            required
                            value={form.data.job_title}
                            onChange={(event) => form.setData('job_title', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="date_of_birth"
                        label={t('access::account.date_of_birth')}
                        error={form.errors.date_of_birth}
                    >
                        {/* A date input speaks the browser's own language and always hands back
                            YYYY-MM-DD, which is what Access asks for - so nothing here parses a
                            date, and an Arabic reader still gets an Arabic calendar. */}
                        <Input
                            id="date_of_birth"
                            name="date_of_birth"
                            type="date"
                            required
                            dir="ltr"
                            value={form.data.date_of_birth}
                            onChange={(event) => form.setData('date_of_birth', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="country"
                        label={t('access::account.country')}
                        error={form.errors.country}
                    >
                        <select
                            id="country"
                            name="country"
                            required
                            value={form.data.country}
                            onChange={(event) => form.setData('country', event.target.value)}
                            className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                        >
                            {account.countries.map((country) => (
                                <option key={country.code} value={country.code}>
                                    {country.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        id="locale"
                        label={t('access::account.communication_language')}
                        hint={t('access::account.communication_language_hint')}
                        error={form.errors.locale}
                    >
                        <select
                            id="locale"
                            name="locale"
                            required
                            value={form.data.locale}
                            onChange={(event) => form.setData('locale', event.target.value)}
                            className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                        >
                            <option value="ar">{t('access::account.language.ar')}</option>
                            <option value="en">{t('access::account.language.en')}</option>
                        </select>
                    </Field>
                </div>

                <Field
                    id="address"
                    label={t('access::account.address')}
                    hint={t('access::account.address_hint')}
                    error={form.errors.address}
                >
                    <Input
                        id="address"
                        name="address"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                    />
                </Field>

                <Button
                    type="submit"
                    disabled={form.processing}
                    className="w-fit"
                    data-test="save-profile"
                >
                    {t('access::account.save')}
                </Button>
            </form>

            <EmailBlock account={account} />
            <PhoneBlock account={account} />
        </div>
    );
}
