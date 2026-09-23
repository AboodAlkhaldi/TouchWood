import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Dialog } from '@/components/Dialog';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B2 - changing the phone (frontend.md §3.2).
|
| Two steps in one dialog. First the current password and the new number; then the code that went to
| that number. The password is asked for because the number is where every sign-in code goes: a
| stolen session must not be able to move the second factor on its own (owner, 2026-09-21). A wrong
| password here counts exactly like a wrong one at sign-in, so the lockout can arrive in this dialog
| too - which is why the refusal is shown inside it rather than only as a toast behind it.
|
| The number in use does not change until the code is right. Until then the person still has the old
| one, and nothing about their sign-in has moved.
|
| The step is not on the server. Whether a code went out is exactly "the request succeeded", so the
| dialog moves on when Inertia says it did, and a refusal leaves it where it was with the reason
| beside the field.
*/

type Props = {
    account: AccountPage;
};

export function PhoneBlock({ account }: Props) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState<'phone' | 'code'>('phone');

    const request = useForm({ phone: '', current_password: '' });
    const confirm = useForm({ code: '' });

    function close() {
        setOpen(false);
        setStep('phone');
        request.reset();
        confirm.reset();
    }

    return (
        <div className="grid gap-3 rounded-lg border border-line bg-surface p-6 shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <p className="text-sm font-medium text-ink">{t('access::account.phone')}</p>

                    {/* Shown in full: this is the person's own account, and they cannot decide
                        whether to change a number they are not allowed to read (2026-09-23). Left
                        to right and in Latin digits, as a dialled number is everywhere. */}
                    <p className="tw-figure text-sm text-ink-muted" dir="ltr">
                        {account.phone ?? ''}
                    </p>

                    {account.phone === null ? (
                        <p className="text-xs text-ink-muted">{t('access::account.no_phone')}</p>
                    ) : (
                        <p className="text-xs text-ink-muted">{t('access::account.phone_hint')}</p>
                    )}
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                    data-test="open-phone-dialog"
                >
                    {t('access::account.change_phone')}
                </Button>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? setOpen(true) : close())}
                title={t('access::account.phone_dialog_title')}
                description={t('access::account.phone_dialog_body')}
            >
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
                        className="grid gap-4"
                    >
                        <FormError />

                        <Field
                            id="new_phone"
                            label={t('access::account.new_phone')}
                            hint={t('access::account.new_phone_hint')}
                            error={request.errors.phone}
                        >
                            <Input
                                id="new_phone"
                                name="phone"
                                type="tel"
                                required
                                autoFocus
                                dir="ltr"
                                autoComplete="tel"
                                value={request.data.phone}
                                onChange={(event) =>
                                    request.setData('phone', toLatinDigits(event.target.value))
                                }
                            />
                        </Field>

                        <Field
                            id="phone_current_password"
                            label={t('access::account.current_password')}
                            error={request.errors.current_password}
                        >
                            <PasswordInput
                                id="phone_current_password"
                                name="current_password"
                                autoComplete="current-password"
                                required
                                value={request.data.current_password}
                                onChange={(event) =>
                                    request.setData('current_password', event.target.value)
                                }
                            />
                        </Field>

                        <div className="flex gap-2">
                            <Button
                                type="submit"
                                disabled={request.processing}
                                data-test="send-phone-code"
                            >
                                {t('access::account.send_code')}
                            </Button>

                            <Button type="button" variant="ghost" onClick={close}>
                                {t('access::account.cancel')}
                            </Button>
                        </div>
                    </form>
                ) : (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            confirm.post('/admin/account/phone/code', {
                                preserveScroll: true,
                                preserveState: true,
                                onSuccess: close,
                            });
                        }}
                        className="grid gap-4"
                    >
                        <FormError />

                        <Field
                            id="phone_code"
                            label={t('access::account.phone_code')}
                            error={confirm.errors.code}
                        >
                            <Input
                                id="phone_code"
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                required
                                autoFocus
                                dir="ltr"
                                className="tw-figure"
                                value={confirm.data.code}
                                onChange={(event) =>
                                    confirm.setData('code', toLatinDigits(event.target.value))
                                }
                            />
                        </Field>

                        <div className="flex gap-2">
                            <Button
                                type="submit"
                                disabled={confirm.processing}
                                data-test="confirm-phone"
                            >
                                {t('access::account.confirm_phone')}
                            </Button>

                            <Button type="button" variant="ghost" onClick={close}>
                                {t('access::account.cancel')}
                            </Button>
                        </div>
                    </form>
                )}
            </Dialog>
        </div>
    );
}
