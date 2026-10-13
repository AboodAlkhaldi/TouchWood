import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { SelectField, TextareaField, TextField } from '@/components/Fields';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldDescription, FieldError, FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { initials } from '@/lib/initials';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { EmailBlock } from '@/pages/Access/Admin/Account/EmailBlock';
import { PhoneBlock } from '@/pages/Access/Admin/Account/PhoneBlock';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B1 - the account tab (frontend.md §3.2), on shadcn's parts with Geist's rules (§1.11).
|
| The picture, the profile, the communication language, and beside them the two things that cannot
| simply be typed over: the email, which travels by link, and the phone, which travels by code.
|
| The language here is the **communication** language - what emails and sign-in codes are written in
| (Access amendment 16). It is labelled apart from the language button in the person menu, which
| changes only what this browser displays and is nobody else's business.
|
| The profile is one Card that is itself the form: its fields, and in its footer the one button that
| saves them. The picture is shadcn's Avatar - the image, or the person's initials when there is
| none or it fails to load - and choosing one is our own "Choose Picture" button over a hidden file
| input (owner, 2026-10-03), so its words are the page's language, not the browser's. The country is
| a combobox, since the list is the whole world (Geist's Select is for short lists); the address a
| Textarea, since an address wraps (Geist's Input: switch to a Textarea once content can wrap).
*/

type Props = {
    account: AccountPage;
};

export function ProfileTab({ account }: Props) {
    const t = useTranslator();
    const [chosen, setChosen] = useState<string | null>(null);
    const picker = useRef<HTMLInputElement>(null);

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

    // Each box as typed (frontend.md §1.7), with Access's rules for a staff profile (StaffProfile):
    // first name, last name and job title required, at most 100 characters each (MAX_TEXT); a date
    // of birth (its range, 1900 to yesterday, is the server's to say); a country picked from the
    // list; an address of at most 500 characters if given (MAX_ADDRESS).
    const text = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'first_name', label: t('access::account.first_name'), value: form.data.first_name, rules: text },
        { id: 'last_name', label: t('access::account.last_name'), value: form.data.last_name, rules: text },
        { id: 'job_title', label: t('access::account.job_title'), value: form.data.job_title, rules: text },
        { id: 'date_of_birth', label: t('access::account.date_of_birth'), value: form.data.date_of_birth, rules: { required: true } },
        { id: 'country', label: t('access::account.country'), value: form.data.country, rules: { required: true } },
        { id: 'address', label: t('access::account.address'), value: form.data.address, rules: { length: { max: 500 } } },
    ]);

    const name = `${account.firstName} ${account.lastName}`.trim();
    const showImage = account.avatarUrl !== null && !form.data.remove_avatar;
    const status = form.data.remove_avatar
        ? t('access::account.picture_removed')
        : chosen !== null
          ? t('access::account.picture_chosen', { name: chosen })
          : account.avatarUrl === null
            ? t('access::account.no_picture')
            : '';

    return (
        <div className="grid gap-6">
            <Card className="material-base gap-0 border-0 py-0">
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => form.post('/admin/account/profile', { preserveScroll: true }));
                    }}
                >
                    <CardHeader className="px-6 pt-5 pb-4">
                        <CardTitle className="text-heading-20 text-ink">
                            <h2>{t('access::account.profile_title')}</h2>
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="grid gap-6 px-6 pb-5">
                        {/* The picture. What is stored is never sent back from here: the form says
                            "a new one", "none" or nothing at all, and the server keeps the rest. A
                            media id coming back from a browser would let anybody wear any public
                            image we hold. */}
                        <FieldSet className="gap-2" aria-describedby="avatar-hint avatar-status">
                            <FieldLegend variant="label" className="text-label-14 text-ink">
                                {t('access::account.picture')}
                            </FieldLegend>
                            <FieldDescription id="avatar-hint">{t('access::account.picture_hint')}</FieldDescription>

                            <div className="mt-1 flex flex-wrap items-center gap-4">
                                <Avatar className="size-16" title={name}>
                                    {showImage ? <AvatarImage src={account.avatarUrl ?? undefined} alt="" /> : null}
                                    <AvatarFallback className="bg-surface-sunken text-heading-20 text-ink-muted">{initials(name)}</AvatarFallback>
                                </Avatar>

                                <div className="grid gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {/* Out of the tab order and hidden from a screen reader: the
                                            button below is the control, in the page's words. */}
                                        <input
                                            ref={picker}
                                            id="avatar"
                                            type="file"
                                            accept="image/*"
                                            tabIndex={-1}
                                            aria-hidden="true"
                                            className="sr-only"
                                            onChange={(event) => {
                                                const file = event.target.files?.[0] ?? null;
                                                form.setData((was) => ({ ...was, avatar: file, remove_avatar: false }));
                                                setChosen(file === null ? null : file.name);
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            aria-describedby={form.errors.avatar ? 'avatar-error' : undefined}
                                            onClick={() => picker.current?.click()}
                                            data-test="choose-picture"
                                        >
                                            {t(account.avatarUrl === null ? 'access::account.choose_picture' : 'access::account.replace_picture')}
                                        </Button>

                                        {showImage ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    form.setData((was) => ({ ...was, avatar: null, remove_avatar: true }));
                                                    setChosen(null);

                                                    if (picker.current !== null) {
                                                        picker.current.value = '';
                                                    }
                                                }}
                                            >
                                                {t('access::account.remove_picture')}
                                            </Button>
                                        ) : null}
                                    </div>

                                    <p id="avatar-status" role="status" className="text-copy-13 text-ink-muted">
                                        {status}
                                    </p>
                                </div>
                            </div>

                            {form.errors.avatar ? <FieldError id="avatar-error">{form.errors.avatar}</FieldError> : null}
                        </FieldSet>

                        <FieldGroup className="grid gap-5 sm:grid-cols-2">
                            <TextField
                                id="first_name"
                                name="first_name"
                                label={t('access::account.first_name')}
                                check={checks.box('first_name', form.errors.first_name)}
                                required
                                value={form.data.first_name}
                                onChange={(event) => form.setData('first_name', event.target.value)}
                            />
                            <TextField
                                id="last_name"
                                name="last_name"
                                label={t('access::account.last_name')}
                                check={checks.box('last_name', form.errors.last_name)}
                                required
                                value={form.data.last_name}
                                onChange={(event) => form.setData('last_name', event.target.value)}
                            />
                            <TextField
                                id="job_title"
                                name="job_title"
                                label={t('access::account.job_title')}
                                check={checks.box('job_title', form.errors.job_title)}
                                required
                                value={form.data.job_title}
                                onChange={(event) => form.setData('job_title', event.target.value)}
                            />
                            {/* A date input speaks the browser's own language and always hands back
                                YYYY-MM-DD, which is what Access asks for - so nothing here parses a
                                date, and an Arabic reader still gets an Arabic calendar. */}
                            <TextField
                                id="date_of_birth"
                                name="date_of_birth"
                                type="date"
                                label={t('access::account.date_of_birth')}
                                check={checks.box('date_of_birth', form.errors.date_of_birth)}
                                required
                                dir="ltr"
                                value={form.data.date_of_birth}
                                onChange={(event) => form.setData('date_of_birth', event.target.value)}
                            />
                            <CountryCombobox
                                id="country"
                                label={t('access::account.country')}
                                countries={account.countries}
                                value={form.data.country}
                                onChange={(code) => form.setData('country', code)}
                                error={checks.box('country', form.errors.country).message}
                                words={{ search: t('access::account.country_search'), none: (query) => t('access::account.country_none', { query }) }}
                            />
                            <SelectField
                                id="locale"
                                name="locale"
                                label={t('access::account.communication_language')}
                                helper={t('access::account.communication_language_hint')}
                                error={form.errors.locale}
                                required
                                value={form.data.locale}
                                onChange={(event) => form.setData('locale', event.target.value)}
                            >
                                <NativeSelectOption value="ar">{t('access::account.language.ar')}</NativeSelectOption>
                                <NativeSelectOption value="en">{t('access::account.language.en')}</NativeSelectOption>
                            </SelectField>
                        </FieldGroup>

                        <TextareaField
                            id="address"
                            name="address"
                            label={t('access::account.address')}
                            helper={t('access::account.address_hint')}
                            check={checks.box('address', form.errors.address)}
                            rows={3}
                            value={form.data.address}
                            onChange={(event) => form.setData('address', event.target.value)}
                        />
                    </CardContent>

                    <CardFooter className="justify-end border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                        <ActionButton type="submit" loading={form.processing} disabledReason={checks.reason} data-test="save-profile">
                            {t('access::account.save')}
                        </ActionButton>
                    </CardFooter>
                </form>
            </Card>

            <EmailBlock account={account} />
            <PhoneBlock account={account} />
        </div>
    );
}
