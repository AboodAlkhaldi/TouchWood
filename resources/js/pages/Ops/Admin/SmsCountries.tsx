import { useRef, useState, type RefObject } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { checked, describedBy, Messages, SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { PhoneCards } from '@/components/PhoneCards';
import { MoreButton } from '@/components/RowMenu';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { SmsCountriesPageData, SmsCountryData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';

/*
| O10 · Store Settings › SMS Countries (ops.md §4.15; Q24), Super Admins only: which countries' phones get SMS
| codes, each one's calling code and provider. A ⋯ menu per row opens a dialog for the provider or the calling
| code; Remove says who it affects. Every answer comes back as the whole list.
*/

const LIST = '/admin/sms-countries';
/** The server's rule (SmsCountry::callingCodeOf), said as it is typed. */
const CALLING_CODE = /^[1-9][0-9]{0,2}$/;

type Dialog = { action: 'add' } | { action: 'provider' | 'calling_code' | 'remove'; country: SmsCountryData } | null;

export default function SmsCountries({ countries, providers, options }: SmsCountriesPageData) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const addButton = (test: string) => (
        <Button type="button" onClick={() => setDialog({ action: 'add' })} data-test={test}>
            {`${t('ops::admin_sms.add')}…`}
        </Button>
    );
    const providerName = (provider: string) => (provider === 'log' ? t('ops::admin_sms.log') : provider);

    return (
        <AdminLayout title={t('ops::admin_sms.title')} subtitle={t('ops::admin_sms.subtitle')} action={addButton('add-country')}>
            <div className="grid gap-4">
                <FormError />
                <Note variant="secondary" data-test="log-note">
                    {t('ops::admin_sms.log_note')}
                </Note>

                {countries.length === 0 ? (
                    <Empty className="material-base" data-test="countries-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('ops::admin_sms.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('ops::admin_sms.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>{addButton('empty-add-country')}</EmptyContent>
                    </Empty>
                ) : (
                    <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                        <Table>
                            <TableCaption className="sr-only">{t('ops::admin_sms.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('ops::admin_sms.columns.country')}</TableHead>
                                    <TableHead>{t('ops::admin_sms.columns.code')}</TableHead>
                                    <TableHead>{t('ops::admin_sms.columns.calling_code')}</TableHead>
                                    <TableHead>{t('ops::admin_sms.columns.provider')}</TableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {countries.map((country) => (
                                    <TableRow key={country.countryCode} data-test={`country-${country.countryCode}`}>
                                        <TableCell className="text-label-14 text-ink">{country.name}</TableCell>
                                        <TableCell className="text-ink-muted">{country.countryCode}</TableCell>
                                        <TableCell>
                                            <bdi dir="ltr" className="tw-figure" data-test="calling-code">{`+${country.callingCode}`}</bdi>
                                        </TableCell>
                                        <TableCell className="text-ink-muted" data-test="provider">
                                            {providerName(country.provider)}
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <RowActions
                                                country={country}
                                                onChoose={(action, from) => {
                                                    opener.current = from;
                                                    setDialog({ action, country });
                                                }}
                                                test={`more-${country.countryCode}`}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                {/* On a phone each country a card (round 3 #15), its menu at the end. */}
                {countries.length === 0 ? null : (
                    <PhoneCards
                        test="country"
                        cards={countries.map((country) => ({
                            id: country.countryCode,
                            title: country.name,
                            lines: [
                                <span key="code">
                                    {`${t('ops::admin_sms.columns.code')}: `}
                                    <bdi dir="ltr">{country.countryCode}</bdi>
                                </span>,
                                <span key="calling">
                                    {`${t('ops::admin_sms.columns.calling_code')}: `}
                                    <bdi dir="ltr" className="tw-figure">{`+${country.callingCode}`}</bdi>
                                </span>,
                                <span key="provider">{`${t('ops::admin_sms.columns.provider')}: ${providerName(country.provider)}`}</span>,
                            ],
                            menu: (
                                <RowActions
                                    country={country}
                                    onChoose={(action, from) => {
                                        opener.current = from;
                                        setDialog({ action, country });
                                    }}
                                    test={`more-card-${country.countryCode}`}
                                />
                            ),
                        }))}
                    />
                )}
            </div>

            {dialog?.action === 'add' ? <AddDialog countries={countries} providers={providers} options={options} providerName={providerName} onOpenChange={close} /> : null}
            {dialog?.action === 'provider' ? <ProviderDialog country={dialog.country} providers={providers} providerName={providerName} onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'calling_code' ? <CallingCodeDialog country={dialog.country} onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'remove' ? <RemoveDialog country={dialog.country} onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

/** A country's ⋯ menu: the row's, and the same on its phone card under its own test name. */
function RowActions({ country, onChoose, test }: { country: SmsCountryData; onChoose: (action: 'provider' | 'calling_code' | 'remove', from: HTMLElement | null) => void; test: string }) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={country.name} data-test={test} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-52">
                <DropdownMenuItem onSelect={() => onChoose('provider', more.current)} data-test="change-provider">{`${t('ops::admin_sms.menu.provider')}…`}</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onChoose('calling_code', more.current)} data-test="change-calling-code">{`${t('ops::admin_sms.menu.calling_code')}…`}</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => onChoose('remove', more.current)} data-test="remove-country">{`${t('ops::admin_sms.menu.remove')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function CallingCodeBox({ value, onChange, check }: { value: string; onChange: (value: string) => void; check: ReturnType<ReturnType<typeof useChecks>['box']> }) {
    const t = useTranslator();

    const helper = t('ops::admin_sms.fields.calling_code_rule');

    return (
        <Field>
            <FieldLabel htmlFor="calling-code">{t('ops::admin_sms.fields.calling_code')}</FieldLabel>
            {/* Left to right on an Arabic page too: a phone number's + comes first. */}
            <InputGroup dir="ltr">
                <InputGroupAddon>
                    <span className="tw-figure" data-test="calling-code-plus">
                        +
                    </span>
                </InputGroupAddon>
                <InputGroupInput
                    {...checked<HTMLInputElement>(check, {})}
                    id="calling-code"
                    dir="ltr"
                    inputMode="numeric"
                    className="tw-figure"
                    aria-invalid={check.message ? true : undefined}
                    aria-describedby={describedBy('calling-code', helper, check.message)}
                    value={value}
                    onChange={(event) => {
                        onChange(toLatinDigits(event.target.value));
                        check.onType();
                    }}
                    data-test="calling-code-box"
                />
            </InputGroup>
            <Messages id="calling-code" helper={helper} error={check.message} check={check} />
        </Field>
    );
}

function AddDialog({
    countries,
    providers,
    options,
    providerName,
    onOpenChange,
}: SmsCountriesPageData & { providerName: (provider: string) => string; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const form = useForm({ country_code: '', calling_code: '', provider: providers[0] ?? '' });
    const listed = new Set(countries.map((country) => country.countryCode));
    const checks = useChecks([
        {
            id: 'calling-code',
            label: t('ops::admin_sms.fields.calling_code'),
            value: form.data.calling_code,
            rules: { required: true, own: (text) => (CALLING_CODE.test(text) ? null : 'ops::admin_sms.fields.calling_code_rule') },
        },
    ]);
    const countryMissing = form.data.country_code === '';

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            title={t('ops::admin_sms.add')}
            description={t('ops::admin_sms.add_body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason ?? (countryMissing ? t('ops::admin_sms.fields.country_rule') : undefined)}
                    onClick={() => checks.submit(() => form.post(LIST, { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-add-country"
                >
                    {t('ops::admin_sms.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <CountryCombobox
                    id="country"
                    label={t('ops::admin_sms.fields.country')}
                    countries={options.map((option) => ({ code: option.code, name: option.name, ours: false, reason: listed.has(option.code) ? t('ops::admin_sms.fields.listed') : undefined }))}
                    value={form.data.country_code}
                    onChange={(code) => form.setData('country_code', code)}
                    error={form.errors.country_code}
                    words={{ search: t('ops::admin_sms.fields.search'), none: (query) => t('ops::admin_sms.fields.none', { query }) }}
                />
                <CallingCodeBox value={form.data.calling_code} onChange={(value) => form.setData('calling_code', value)} check={checks.box('calling-code', form.errors.calling_code)} />
                <SelectField id="provider" label={t('ops::admin_sms.fields.provider')} error={form.errors.provider} value={form.data.provider} onChange={(event) => form.setData('provider', event.target.value)} data-test="provider-box">
                    {providers.map((provider) => (
                        <NativeSelectOption key={provider} value={provider}>
                            {providerName(provider)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
            </div>
        </PanelDialog>
    );
}

function ProviderDialog({
    country,
    providers,
    providerName,
    onOpenChange,
    returnFocusTo,
}: {
    country: SmsCountryData;
    providers: string[];
    providerName: (provider: string) => string;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm({ provider: country.provider });

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('ops::admin_sms.provider_title', { country: country.name })}
            description={t('ops::admin_sms.provider_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} onClick={() => form.post(`${LIST}/${country.countryCode}/provider`, { preserveScroll: true, onSuccess: () => onOpenChange(false) })} data-test="confirm-provider">
                    {t('ops::admin_sms.save')}
                </ActionButton>
            }
        >
            <SelectField id="provider" label={t('ops::admin_sms.fields.provider')} error={form.errors.provider} value={form.data.provider} onChange={(event) => form.setData('provider', event.target.value)} data-test="provider-box">
                {providers.map((provider) => (
                    <NativeSelectOption key={provider} value={provider}>
                        {providerName(provider)}
                    </NativeSelectOption>
                ))}
            </SelectField>
        </PanelDialog>
    );
}

function CallingCodeDialog({ country, onOpenChange, returnFocusTo }: { country: SmsCountryData; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const form = useForm({ calling_code: country.callingCode });
    const checks = useChecks([
        {
            id: 'calling-code',
            label: t('ops::admin_sms.fields.calling_code'),
            value: form.data.calling_code,
            rules: { required: true, own: (text) => (CALLING_CODE.test(text) ? null : 'ops::admin_sms.fields.calling_code_rule') },
        },
    ]);

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('ops::admin_sms.calling_code_title', { country: country.name })}
            description={t('ops::admin_sms.calling_code_body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => form.post(`${LIST}/${country.countryCode}/calling-code`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-calling-code"
                >
                    {t('ops::admin_sms.save')}
                </ActionButton>
            }
        >
            <CallingCodeBox value={form.data.calling_code} onChange={(value) => form.setData('calling_code', value)} check={checks.box('calling-code', form.errors.calling_code)} />
        </PanelDialog>
    );
}

function RemoveDialog({ country, onOpenChange, returnFocusTo }: { country: SmsCountryData; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('ops::admin_sms.remove.title', { country: country.name })}
            description={t('ops::admin_sms.remove.body')}
            busy={busy}
            cancelLabel={t('ops::admin_sms.remove.keep')}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`${LIST}/${country.countryCode}/remove`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-remove-country"
                >
                    {t('ops::admin_sms.remove.confirm')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
