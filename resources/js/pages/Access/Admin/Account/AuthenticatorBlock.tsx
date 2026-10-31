import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DialogError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Field, FieldLabel, FieldSet } from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The authenticator app on the Security tab (access.md amendment 91), on shadcn's Card, RadioGroup and
| Dialog with Geist's rules (frontend.md §1.11).
|
| What it says is the server's: which methods the Super Admins' setting has on, whether an app is set
| up and since when, and the second step this person takes now. With both methods on, they choose
| theirs here (decision 1); choosing the app with none set up goes straight to its setup.
|
| Choosing SMS over the app asks for the password in the form (owner, 2026-10-31). New Recovery Codes
| and Remove App each ask for the password first (decision 6), in a dialog of their
| own - a wrong password counts as at sign-in, so the refusal shows inside it (DialogError). Change App
| and Set Up App open the setup page, which asks for it there.
*/

type Props = {
    account: AccountPage;
};

type Asking = 'renew' | 'remove' | null;

export function AuthenticatorBlock({ account }: Props) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const app = account.authenticator;
    const both = app.smsEnabled && app.authenticatorEnabled;
    const method = useForm({ method: app.method, current_password: '' });
    // Setting the app aside for SMS asks for the password first (owner, 2026-10-31).
    const toSms = method.data.method === 'SMS' && app.method !== 'SMS';
    const [asking, setAsking] = useState<Asking>(null);
    const returnFocus = useReturnFocus(asking !== null);
    const password = useForm({ current_password: '' });
    const checks = useChecks(
        [{ id: 'authenticator_password', label: t('access::account.current_password'), value: password.data.current_password, rules: { required: true, keepSpaces: true } }],
        asking !== null,
    );
    // Removing the app while it is how everyone signs in leads straight to a new setup: said first.
    const removeLeadsToSetup = app.authenticatorEnabled && !app.smsEnabled;

    function close() {
        setAsking(null);
        password.reset();
        password.clearErrors();
    }

    return (
        <Card className="material-base gap-0 border-0 py-0" data-test="authenticator-block">
            <CardHeader className="px-6 pt-5 pb-4">
                <CardTitle className="text-heading-20 text-ink">
                    <h2>{t('access::account.authenticator_title')}</h2>
                </CardTitle>
                <CardDescription className="grid gap-1 text-copy-14 text-ink-muted">
                    <span data-test="authenticator-state">
                        {app.hasApp && app.confirmedAt !== null ? (
                            <>
                                {t('access::account.authenticator_on')} <Time value={app.confirmedAt} inSentence />
                            </>
                        ) : (
                            t('access::account.authenticator_off')
                        )}
                    </span>
                    <span data-test="authenticator-method">{t(app.method === 'AUTHENTICATOR' ? 'access::account.authenticator_uses_app' : 'access::account.authenticator_uses_sms')}</span>
                    {app.hasApp ? <span className="tw-figure">{t('access::account.codes_left', { count: figure(locale, app.recoveryCodesLeft) })}</span> : null}
                </CardDescription>
            </CardHeader>

            {both ? (
                <CardContent className="px-6 pb-5">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            method.post('/admin/account/sign-in-method', { preserveScroll: true, onFinish: () => method.setData('current_password', '') });
                        }}
                        className="grid gap-3"
                    >
                        <FieldSet>
                            <FieldLabel className="text-label-14 text-ink">{t('access::account.sign_in_method')}</FieldLabel>
                            <CardDescription className="text-copy-13 text-ink-muted">{t('access::account.sign_in_method_hint')}</CardDescription>
                            <RadioGroup value={method.data.method} onValueChange={(value) => method.setData('method', value)} className="grid gap-2" data-test="sign-in-method">
                                {(['SMS', 'AUTHENTICATOR'] as const).map((choice) => (
                                    <Field key={choice} orientation="horizontal">
                                        <RadioGroupItem value={choice} id={`method-${choice}`} />
                                        <FieldLabel htmlFor={`method-${choice}`} className="text-label-14 font-normal text-ink">
                                            {t(choice === 'SMS' ? 'access::account.method_sms' : 'access::account.method_authenticator')}
                                        </FieldLabel>
                                    </Field>
                                ))}
                            </RadioGroup>
                        </FieldSet>
                        {toSms ? (
                            <PasswordInput
                                id="method_password"
                                name="current_password"
                                label={t('access::account.current_password')}
                                helper={t('access::account.method_sms_password')}
                                error={method.errors.current_password}
                                autoComplete="current-password"
                                required
                                value={method.data.current_password}
                                onChange={(event) => method.setData('current_password', event.target.value)}
                            />
                        ) : null}
                        <div className="flex justify-end">
                            <ActionButton
                                type="submit"
                                size="sm"
                                loading={method.processing}
                                disabledReason={
                                    method.data.method === app.method
                                        ? t('access::account.method_unchanged_reason')
                                        : toSms && method.data.current_password === ''
                                          ? t('access::account.method_sms_password')
                                          : undefined
                                }
                                data-test="save-method"
                            >
                                {t('access::account.save_method')}
                            </ActionButton>
                        </div>
                    </form>
                </CardContent>
            ) : null}

            {app.authenticatorEnabled || app.hasApp ? (
                <CardFooter className="flex flex-wrap justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                    {app.hasApp ? (
                        <>
                            <Button type="button" variant="ghost" size="sm" className="text-bad" onClick={() => setAsking('remove')} data-test="remove-app">
                                {t('access::account.remove_app')}
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => setAsking('renew')} data-test="renew-codes">
                                {t('access::account.renew_codes')}
                            </Button>
                            {app.authenticatorEnabled ? (
                                <Button asChild variant="outline" size="sm" data-test="change-app">
                                    <Link href="/admin/authenticator/setup">{t('access::account.change_app')}</Link>
                                </Button>
                            ) : null}
                        </>
                    ) : (
                        <Button asChild size="sm" data-test="set-up-app">
                            <Link href="/admin/authenticator/setup">{t('access::account.set_up_app')}</Link>
                        </Button>
                    )}
                </CardFooter>
            ) : null}

            <Dialog open={asking !== null} onOpenChange={(next) => (password.processing || next ? undefined : close())}>
                <DialogContent showCloseButton={false} onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            checks.submit(() =>
                                password.post(asking === 'remove' ? '/admin/account/authenticator/remove' : '/admin/account/authenticator/recovery-codes', {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: close,
                                }),
                            );
                        }}
                    >
                        <div className="grid gap-4 p-6">
                            <DialogHeader>
                                <DialogTitle className="text-heading-20 text-ink">{t(asking === 'remove' ? 'access::account.remove_app' : 'access::account.renew_codes')}</DialogTitle>
                                <DialogDescription className="text-copy-14 text-ink-muted">
                                    {t(asking === 'remove' ? 'access::account.remove_app_body' : 'access::account.renew_codes_body')}
                                    {asking === 'remove' && removeLeadsToSetup ? ` ${t('access::account.remove_app_required_body')}` : ''}
                                </DialogDescription>
                            </DialogHeader>
                            <DialogError open={asking !== null} />
                            <PasswordInput
                                id="authenticator_password"
                                name="current_password"
                                label={t('access::account.current_password')}
                                check={checks.box('authenticator_password', password.errors.current_password)}
                                autoComplete="current-password"
                                required
                                autoFocus
                                value={password.data.current_password}
                                onChange={(event) => password.setData('current_password', event.target.value)}
                            />
                        </div>
                        <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                            <Button type="button" variant="outline" disabled={password.processing} onClick={close} data-test="modal-cancel">
                                {t('ui.cancel')}
                            </Button>
                            <ActionButton
                                type="submit"
                                variant={asking === 'remove' ? 'destructive' : 'default'}
                                loading={password.processing}
                                disabledReason={checks.reason}
                                data-test="confirm-authenticator-password"
                            >
                                {t(asking === 'remove' ? 'access::account.remove_app' : 'access::account.renew_codes')}
                            </ActionButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
