import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { CodeInput } from '@/components/CodeInput';
import { TextField } from '@/components/Fields';
import { DialogError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B2 - changing the phone (frontend.md §3.2), on shadcn's Card and Dialog with Geist's rules (§1.11).
|
| Two steps in one dialog. First the current password and the new number; then the code that went to
| that number, in shadcn's InputOTP, one box per digit, as many as the server's setting says
| (§1.11, "SMS codes"). The password is asked for because the number is where every sign-in code
| goes: a stolen session must not be able to move the second factor on its own (owner, 2026-09-21).
| A wrong password here counts exactly like a wrong one at sign-in, so the lockout can arrive in this
| dialog too - which is why the refusal is shown inside it (DialogError) rather than only as a toast
| behind it.
|
| The number in use does not change until the code is right. Until then the person still has the old
| one, and nothing about their sign-in has moved.
|
| The step is not on the server. Whether a code went out is exactly "the request succeeded", so the
| dialog moves on when Inertia says it did, and a refusal leaves it where it was with the reason
| beside the field. The last step's button repeats the dialog's title (§1.11, "Applied in the
| rebuild"); the first step's sends the code it names.
*/

type Props = {
    account: AccountPage;
};

export function PhoneBlock({ account }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<'phone' | 'code'>('phone');
    const returnFocus = useReturnFocus(open);

    const request = useForm({ phone: '', current_password: '' });
    const confirm = useForm({ code: '' });
    const busy = request.processing || confirm.processing;

    function close() {
        setOpen(false);
        setStep('phone');
        request.reset();
        request.clearErrors();
        confirm.reset();
        confirm.clearErrors();
    }

    return (
        <Card className="material-base gap-3 border-0 py-5">
            <CardHeader className="px-6">
                <CardTitle className="text-heading-20 text-ink">
                    <h2>{t('access::account.phone')}</h2>
                </CardTitle>
                <CardDescription className="grid gap-1">
                    {/* Shown in full: this is the person's own account, and they cannot decide
                        whether to change a number they are not allowed to read (2026-09-23). Left
                        to right and in Latin digits, as a dialled number is everywhere: isolated in
                        a bdi, so on an Arabic page it still sits at the start, under the title. */}
                    {account.phone === null ? null : (
                        <span className="tw-figure text-copy-14 text-ink-muted">
                            <bdi dir="ltr">{account.phone}</bdi>
                        </span>
                    )}
                    <span className="text-copy-13 text-ink-muted">{t(account.phone === null ? 'access::account.no_phone' : 'access::account.phone_hint')}</span>
                </CardDescription>
                <CardAction>
                    <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)} data-test="open-phone-dialog">
                        {t('access::account.change_phone')}
                    </Button>
                </CardAction>
            </CardHeader>

            <Dialog open={open} onOpenChange={(next) => (busy ? undefined : next ? setOpen(true) : close())}>
                <DialogContent showCloseButton={false} onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 sm:max-w-md">
                    {step === 'phone' ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                request.post('/admin/account/phone', {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: () => setStep('code'),
                                });
                            }}
                        >
                            <div className="grid gap-4 p-6">
                                <DialogHeader>
                                    <DialogTitle className="text-heading-20 text-ink">{t('access::account.phone_dialog_title')}</DialogTitle>
                                    <DialogDescription className="text-copy-14 text-ink-muted">{t('access::account.phone_dialog_body')}</DialogDescription>
                                </DialogHeader>
                                <DialogError open={open} />
                                <TextField
                                    id="new_phone"
                                    name="phone"
                                    type="tel"
                                    label={t('access::account.new_phone')}
                                    helper={t('access::account.new_phone_hint')}
                                    error={request.errors.phone}
                                    required
                                    autoFocus
                                    dir="ltr"
                                    autoComplete="tel"
                                    inputClassName="tw-figure"
                                    value={request.data.phone}
                                    onChange={(event) => request.setData('phone', toLatinDigits(event.target.value))}
                                />
                                <PasswordInput
                                    id="phone_current_password"
                                    name="current_password"
                                    label={t('access::account.current_password')}
                                    error={request.errors.current_password}
                                    autoComplete="current-password"
                                    required
                                    value={request.data.current_password}
                                    onChange={(event) => request.setData('current_password', event.target.value)}
                                />
                            </div>
                            <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                                <Button type="button" variant="outline" disabled={busy} onClick={close} data-test="modal-cancel">
                                    {t('ui.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={request.processing} data-test="send-phone-code">
                                    {t('access::account.send_code')}
                                </ActionButton>
                            </DialogFooter>
                        </form>
                    ) : (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                confirm.post('/admin/account/phone/code', { preserveScroll: true, preserveState: true, onSuccess: close });
                            }}
                        >
                            <div className="grid gap-4 p-6">
                                <DialogHeader>
                                    <DialogTitle className="text-heading-20 text-ink">{t('access::account.phone_dialog_title')}</DialogTitle>
                                    <DialogDescription className="text-copy-14 text-ink-muted">{t('access::account.phone_dialog_body')}</DialogDescription>
                                </DialogHeader>
                                <DialogError open={open} />
                                <CodeInput
                                    id="phone_code"
                                    label={t('access::account.phone_code')}
                                    length={account.codeLength}
                                    value={confirm.data.code}
                                    onChange={(code) => confirm.setData('code', code)}
                                    error={confirm.errors.code}
                                    autoFocus
                                />
                            </div>
                            <DialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                                <Button type="button" variant="outline" disabled={busy} onClick={close} data-test="modal-cancel">
                                    {t('ui.cancel')}
                                </Button>
                                <ActionButton type="submit" loading={confirm.processing} data-test="confirm-phone">
                                    {t('access::account.phone_dialog_title')}
                                </ActionButton>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </Card>
    );
}
