import { Link, router, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { CodeInput } from '@/components/CodeInput';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { AuthenticatorSetupPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| Setting up an authenticator app, or changing it (access.md amendment 91), on the sign-in pages'
| frame and shadcn's parts.
|
| Two steps, each the server's: first the password (decision 6) - the QR code is never on the page
| before it is given, in this browser - then the QR code, the same key to type by hand, and one code
| from the app (shadcn's InputOTP, six boxes) that proves the app reads it. The QR code is an SVG the
| server drew, shown as an image: the secret inside it never leaves our server.
|
| A session the panel waits on (the app is its method and none is set up) has nowhere to go back to,
| so it is offered Sign Out; anyone else goes back to their account.
*/

const APP_CODE_LENGTH = 6;

type Props = AuthenticatorSetupPage;

export default function AuthenticatorSetup({ hasApp, required, qrSvg, manualKey }: Props) {
    const t = useTranslator();
    const password = useForm({ current_password: '' });
    const confirm = useForm({ code: '' });
    const checks = useChecks([
        { id: 'current_password', label: t('access::account.current_password'), value: password.data.current_password, rules: { required: true, keepSpaces: true }, off: qrSvg !== null },
    ]);
    const incomplete = confirm.data.code.length < APP_CODE_LENGTH ? t('access::auth.code_incomplete', { count: APP_CODE_LENGTH }) : undefined;
    const title = t(hasApp ? 'access::account.setup_change_title' : 'access::account.setup_title');

    const away = required ? (
        <Button type="button" variant="ghost" onClick={() => router.post('/admin/sign-out')} data-test="setup-sign-out">
            {t('access::account.setup_sign_out')}
        </Button>
    ) : (
        <FieldDescription className="text-center">
            <Link href="/admin/account?tab=security" className="underline underline-offset-4">
                {t('access::account.back_to_account')}
            </Link>
        </FieldDescription>
    );

    if (qrSvg === null || manualKey === null) {
        return (
            <SignInLayout title={title} subtitle={required ? t('access::account.setup_required') : t('access::account.setup_password_body')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        checks.submit(() => password.post('/admin/authenticator/setup', { onFinish: () => password.reset() }));
                    }}
                >
                    <FieldGroup className="gap-5">
                        <FormError />
                        {required ? <FieldDescription>{t('access::account.setup_password_body')}</FieldDescription> : null}
                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            label={t('access::account.current_password')}
                            check={checks.box('current_password', password.errors.current_password)}
                            autoComplete="current-password"
                            required
                            autoFocus
                            value={password.data.current_password}
                            onChange={(event) => password.setData('current_password', event.target.value)}
                        />
                        <Field>
                            <ActionButton type="submit" loading={password.processing} disabledReason={checks.reason} className="w-full" data-test="show-qr">
                                {t('access::account.show_qr')}
                            </ActionButton>
                            {away}
                        </Field>
                    </FieldGroup>
                </form>
            </SignInLayout>
        );
    }

    return (
        <SignInLayout title={title} subtitle={t('access::account.setup_scan')}>
            <form
                onKeyDown={(event) => (event.key === 'Enter' && incomplete !== undefined ? confirm.setError('code', incomplete) : undefined)}
                onSubmit={(event) => {
                    event.preventDefault();

                    if (incomplete !== undefined) {
                        confirm.setError('code', incomplete);

                        return;
                    }

                    confirm.post('/admin/authenticator/setup/code');
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    {/* White behind the code in both themes: a scanner reads dark squares on light. */}
                    <div className="flex justify-center">
                        <img
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrSvg)}`}
                            alt={t('access::account.qr_alt')}
                            width={200}
                            height={200}
                            className="size-50 rounded-md bg-white p-2"
                            data-test="setup-qr"
                        />
                    </div>

                    <Field>
                        <FieldDescription>{t('access::account.setup_manual')}</FieldDescription>
                        {/* Left to right on an Arabic page too, and selectable whole. */}
                        <code dir="ltr" className="block rounded-md bg-surface-sunken px-3 py-2 text-center font-mono text-label-14 tracking-wide break-all text-ink select-all" data-test="setup-key">
                            {manualKey}
                        </code>
                        <FieldDescription>{t('access::account.setup_expires')}</FieldDescription>
                    </Field>

                    <CodeInput
                        id="code"
                        label={t('access::account.setup_code_label')}
                        length={APP_CODE_LENGTH}
                        value={confirm.data.code}
                        onChange={(code) => {
                            confirm.setData('code', code);
                            confirm.clearErrors('code');
                        }}
                        error={confirm.errors.code}
                    />

                    <Field>
                        <ActionButton type="submit" loading={confirm.processing} disabledReason={incomplete} className="w-full" data-test="confirm-app">
                            {t('access::account.setup_confirm')}
                        </ActionButton>
                        {away}
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
