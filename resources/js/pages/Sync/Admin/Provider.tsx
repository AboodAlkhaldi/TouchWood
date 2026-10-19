import { type RefObject, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { SelectField, TextField } from '@/components/Fields';
import { FormError, useFreshRefusal } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PasswordInput } from '@/components/PasswordInput';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { Time } from '@/components/Time';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Description, type DescriptionItem } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { NativeSelectOption } from '@/components/ui/native-select';
import { figure } from '@/lib/digits';
import { useChecks, type Box } from '@/lib/use-checks';
import type { ConnectionData, ProviderPage, SyncFrameData } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { addressProblem } from './checks';
import { FailureText, plural, R, StoreFrame, StoreStateBadge, useLocale, useT } from './parts';

/*
| **The Provider page** (sync.md §4.1 Y3, `?store=`), a Super Admin's: where a store's prices and stock come
| from. Until Odoo's connection is built a Note says so above the form, which is drawn and whose test the
| adapter refuses (Q38). A store not wired: **Wire Store** - the provider, its address, the provider's own
| settings, the key and the secret - tested first (up to 10 seconds) and saved only if the test read works.
| A wired store: its Description - never the key or the secret, only that they are set -, **Change
| Connection** (the same form, filled, the key and the secret left empty to keep them) and ⋯ **Unwire
| Store…**, confirmed by typing the store's code, saying first how many sizes stop being orderable.
|
| Each box checks itself as typed (frontend.md §1.7); a refusal naming a box is said under it - one of the
| provider's settings under its own (Q34) -, any other under the form.
*/

const P = 'sync::admin_provider';

export default function Provider(page: ProviderPage) {
    const { storeFrame: frame, connection } = page;
    const t = useT();
    const wired = connection.provider !== null;
    const [editing, setEditing] = useState(false);
    const [unwiring, setUnwiring] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const formShown = !wired || editing;

    const actions =
        wired && !editing ? (
            <div className="flex items-center gap-2">
                <ActionButton type="button" onClick={() => setEditing(true)} data-test="change-connection">
                    {t(`${P}.menu.change`)}
                </ActionButton>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <MoreButton ref={more} name={frame.storeName} data-test="provider-actions" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        <Guarded destructive label={t(`${P}.menu.unwire`)} onSelect={() => setUnwiring(true)} test="unwire-store" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        ) : undefined;

    // A menu entry of its own: no trail - the menu's default would read /admin/sync/provider as under the Sync Report.
    return (
        <AdminLayout title={t(`${P}.title`)} subtitle={t(`${P}.subtitle`)} action={actions} breadcrumbs={[]}>
            <div className="grid gap-4">
                {/* With the form drawn, a refusal is said under it, where the person is looking. */}
                {formShown ? null : <FormError />}
                <StoreFrame frame={frame} />

                {connection.providerReady ? null : <Note data-test="not-ready">{t(`${P}.not_ready`)}</Note>}

                {formShown ? (
                    <ConnectionForm key={wired ? 'change' : 'wire'} frame={frame} connection={connection} mode={wired ? 'change' : 'wire'} onDone={() => setEditing(false)} />
                ) : (
                    <Wired connection={connection} />
                )}
            </div>

            {wired ? <UnwireDialog open={unwiring} onOpenChange={setUnwiring} frame={frame} connection={connection} returnFocusTo={more} /> : null}
        </AdminLayout>
    );
}

/** The form's boxes' ids. */
const ID = { provider: 'provider-kind', address: 'provider-address', key: 'provider-key', secret: 'provider-secret' } as const;
const settingId = (name: string) => `provider-setting-${name}`;

/**
 * **Wire Store**, or **Change Connection** once wired: tested first - "Testing…" while the test read runs -,
 * nothing saved when it fails. Changing, an empty key or secret keeps the current one.
 */
function ConnectionForm({ frame, connection, mode, onDone }: { frame: SyncFrameData; connection: ConnectionData; mode: 'wire' | 'change'; onDone: () => void }) {
    const t = useT();
    const { errors } = usePage<SharedProps>().props;
    const [provider, setProvider] = useState(connection.provider ?? connection.providers[0] ?? '');
    const [address, setAddress] = useState(connection.address ?? '');
    const [settings, setSettings] = useState<Record<string, string>>(() => Object.fromEntries(connection.fields.map((name) => [name, connection.settings[name] ?? ''])));
    const [key, setKey] = useState('');
    const [secret, setSecret] = useState('');
    const [busy, setBusy] = useState(false);
    const changing = mode === 'change';
    const F = `${P}.fields`;

    const boxes: Box[] = [
        { id: ID.address, label: t(`${F}.address`), value: address, rules: { required: true, own: addressProblem } },
        ...connection.fields.map((name): Box => ({ id: settingId(name), label: name, value: settings[name] ?? '', rules: { required: true } })),
        { id: ID.key, label: t(`${F}.key`), value: key, rules: { required: !changing, keepSpaces: true, own: (text) => ([...text].length > 1000 ? `${P}.checks.key_long` : null) } },
        { id: ID.secret, label: t(`${F}.secret`), value: secret, rules: { required: !changing, keepSpaces: true, own: (text) => ([...text].length > 1000 ? `${P}.checks.secret_long` : null) } },
    ];
    const checks = useChecks(boxes);
    const error = (name: string): string | undefined => errors[name] ?? undefined;
    const boxErrors = [error('provider'), error('address'), error('key'), error('secret'), ...connection.fields.map((name) => error(`settings.${name}`))];
    // A refusal naming no box - the provider's test failing - is said under the form; one naming a box, under it only.
    const formError = errors.form !== undefined && errors.form !== '' && !boxErrors.includes(errors.form) ? errors.form : undefined;

    const send = () =>
        checks.submit(() =>
            router.post(
                `/admin/sync/provider/${mode}`,
                { store: frame.storeCode, provider, address, key, secret, settings },
                {
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => setBusy(false),
                    // The key and the secret are never kept, whatever the answer.
                    onError: () => {
                        setKey('');
                        setSecret('');
                    },
                    onSuccess: onDone,
                },
            ),
        );

    return (
        <Card className="material-base gap-0 border-0 py-0" data-test="connection-form">
            {changing ? null : (
                <CardHeader className="px-5 pt-5 pb-0">
                    <CardTitle className="text-heading-16 text-ink">{t(`${P}.wire.title`)}</CardTitle>
                </CardHeader>
            )}
            <CardContent className="px-5 py-5">
                <form
                    className="grid max-w-xl gap-4"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!busy) {
                            send();
                        }
                    }}
                >
                    <SelectField id={ID.provider} label={t(`${F}.provider`)} value={provider} onChange={(event) => setProvider(event.target.value)} error={error('provider')} data-test="provider-kind">
                        {connection.providers.map((each) => (
                            <NativeSelectOption key={each} value={each}>
                                {t(`${R}.providers.${each}`)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                    <TextField
                        id={ID.address}
                        label={t(`${F}.address`)}
                        value={address}
                        onChange={(event) => setAddress(event.target.value)}
                        placeholder={t(`${F}.address_placeholder`)}
                        dir="ltr"
                        inputMode="url"
                        autoComplete="off"
                        spellCheck={false}
                        maxLength={600}
                        check={checks.box(ID.address, error('address'))}
                    />
                    {connection.fields.map((name) => (
                        <TextField
                            key={name}
                            id={settingId(name)}
                            label={name}
                            value={settings[name] ?? ''}
                            onChange={(event) => setSettings((was) => ({ ...was, [name]: event.target.value }))}
                            dir="ltr"
                            autoComplete="off"
                            spellCheck={false}
                            check={checks.box(settingId(name), error(`settings.${name}`))}
                        />
                    ))}
                    <PasswordInput
                        id={ID.key}
                        label={t(`${F}.key`)}
                        value={key}
                        onChange={(event) => setKey(event.target.value)}
                        autoComplete="new-password"
                        helper={changing ? t(`${P}.change.keep_key`) : undefined}
                        check={checks.box(ID.key, error('key'))}
                        dir="ltr"
                    />
                    <PasswordInput
                        id={ID.secret}
                        label={t(`${F}.secret`)}
                        value={secret}
                        onChange={(event) => setSecret(event.target.value)}
                        autoComplete="new-password"
                        helper={changing ? t(`${P}.change.keep_secret`) : undefined}
                        check={checks.box(ID.secret, error('secret'))}
                        dir="ltr"
                    />

                    {formError === undefined ? null : (
                        <Note variant="error" alert data-test="form-error">
                            {formError}
                        </Note>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <ActionButton type="submit" loading={busy} disabledReason={checks.reason} data-test="connection-submit">
                            {busy ? t(`${P}.wire.testing`) : changing ? t(`${P}.change.submit`) : t(`${P}.wire.submit`)}
                        </ActionButton>
                        {changing ? (
                            <Button type="button" variant="outline" disabled={busy} onClick={onDone} data-test="connection-cancel">
                                {t(`${P}.change.cancel`)}
                            </Button>
                        ) : null}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

/** A wired store's connection: never the key or the secret, only that they are set; the address its notices come to. */
function Wired({ connection }: { connection: ConnectionData }) {
    const t = useT();
    const locale = useLocale();
    const W = `${P}.wired`;
    const never = t(`${W}.never`);
    const settings = Object.entries(connection.settings);

    const items: DescriptionItem[] = [
        { title: t(`${W}.provider`), content: connection.providerName, 'data-test': 'wired-provider' },
        {
            title: t(`${W}.address`),
            content: (
                <span className="font-mono text-copy-13 break-all" dir="ltr">
                    {connection.address}
                </span>
            ),
            'data-test': 'wired-address',
        },
        {
            title: t(`${W}.settings`),
            content:
                settings.length === 0 ? null : (
                    <span className="grid gap-0.5 font-mono text-copy-13" dir="ltr">
                        {settings.map(([name, value]) => (
                            <span key={name} className="break-all">{`${name}: ${value}`}</span>
                        ))}
                    </span>
                ),
        },
        { title: t(`${W}.key_and_secret`), content: connection.keySet ? t(`${W}.set`) : null, 'data-test': 'wired-key' },
        { title: t(`${W}.last_changed`), content: connection.changedAt === null ? null : <Time value={connection.changedAt} mode="absolute" /> },
        {
            title: t(`${W}.state`),
            content: (
                <span className="grid justify-items-start gap-1">
                    <StoreStateBadge state={connection.state} />
                    {connection.failure === null ? null : (
                        <span className="text-copy-13 text-ink-muted">
                            <FailureText failure={connection.failure} />
                        </span>
                    )}
                </span>
            ),
            'data-test': 'wired-state',
        },
        { title: t(`${W}.last_read`), content: connection.lastReadAt === null ? never : <Time value={connection.lastReadAt} mode="absolute" /> },
        { title: t(`${W}.last_full_read`), content: connection.lastFullReadAt === null ? never : <Time value={connection.lastFullReadAt} mode="absolute" /> },
    ];

    return (
        <Card className="material-base gap-0 border-0 py-0" data-test="wired">
            <CardContent className="grid gap-6 px-5 py-5">
                <Description items={items} columns={2} />
                <div className="grid gap-2" data-test="notice">
                    <span className="text-label-13 text-ink-muted">{t(`${W}.notice_address`)}</span>
                    <span className="flex items-center gap-1">
                        <code className="font-mono text-copy-13 break-all text-ink" dir="ltr" data-test="notice-address">
                            {connection.noticeAddress}
                        </code>
                        <CopyButton text={connection.noticeAddress} label={t(`${W}.copy_notice`)} />
                    </span>
                    <span className="text-copy-13 text-ink-muted">{t(`${W}.notice_sends`)}</span>
                    {/* In the page's direction: an Arabic sentence holding a header's name lays it out on its own. */}
                    <ul className="grid list-disc gap-1 ps-5 text-copy-13 text-ink-muted">
                        <li>{t(`${W}.notice_timestamp`)}</li>
                        <li>{t(`${W}.notice_signature`)}</li>
                        <li>{t(`${W}.notice_body`, { most: figure(locale, connection.noticeMostCodes) })}</li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    );
}

/** **Unwire Store…**: how many sizes stop being orderable said first, confirmed by typing the store's code. */
function UnwireDialog({
    open,
    onOpenChange,
    frame,
    connection,
    returnFocusTo,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    frame: SyncFrameData;
    connection: ConnectionData;
    returnFocusTo: RefObject<HTMLElement | null>;
}) {
    const t = useT();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const refusal = useFreshRefusal(open);
    const U = `${P}.unwire`;
    const count = connection.stopsWhenUnwired;
    // "No size stops being orderable." - English has no plural form of its own for 0.
    const stops = count === 0 ? t(`${U}.stops.zero`) : plural(t, locale, `${U}.stops`, count);

    return (
        <DestructiveActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${U}.title`)}
            description={
                <span className="grid gap-2">
                    <span data-test="unwire-stops">{stops}</span>
                    <span>{t(`${U}.stays`)}</span>
                </span>
            }
            verificationPhrase={frame.storeCode}
            verificationLabel={t(`${U}.code_label`)}
            confirmLabel={t(`${U}.confirm`)}
            loading={busy}
            error={refusal}
            returnFocusTo={returnFocusTo}
            onConfirm={() =>
                router.post('/admin/sync/provider/unwire', { store: frame.storeCode }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
            }
        />
    );
}
