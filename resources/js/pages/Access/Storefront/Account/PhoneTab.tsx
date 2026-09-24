import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F7 - the phone tab (frontend.md §3.6).
|
| Two steps in one panel: the number, then the code that went to it. **No password is asked for**,
| unlike the panel's. A staff member's number is where every sign-in code goes, so moving it moves
| a second factor; a customer signs in with an email and a password alone, and their number is for
| an order and a delivery (access.md §1.3, §1.8).
|
| The number in use does not change until the code is right. Until then they still have the old
| one, and a mistyped number has changed nothing.
|
| The step is not on the server. Whether a code went out is exactly "the request succeeded", so the
| panel moves on when Inertia says it did, and a refusal leaves it where it was with the reason
| beside the field.
*/

type Props = {
    account: CustomerAccountPage;
};

export function PhoneTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const [step, setStep] = useState<'number' | 'code'>('number');

    const request = useForm({ phone: '' });
    const confirm = useForm({ code: '' });

    return (
        <div className="grid gap-6">
            <div className="grid gap-0.5">
                <p className="text-sm font-medium text-ink">{t('access::account.phone')}</p>

                {/* Shown in full: this is their own account, and they cannot decide whether to
                    change a number they are not allowed to read. Left to right and in Latin
                    digits, as a dialled number is everywhere. */}
                {account.phone === null ? (
                    <p className="text-sm text-ink-muted">{t('access::account.shop_no_phone')}</p>
                ) : (
                    <p className="tw-figure text-sm text-ink-muted" dir="ltr">
                        {account.phone}
                    </p>
                )}

                <p className="text-xs text-ink-muted">{t('access::account.shop_phone_hint')}</p>
            </div>

            <div className="grid gap-4 border-t border-line pt-6">
                <div className="grid gap-1">
                    <p className="text-sm font-medium text-ink">
                        {account.phone === null
                            ? t('access::account.shop_add_phone')
                            : t('access::account.shop_change_phone')}
                    </p>
                    <p className="text-xs text-ink-muted">
                        {t('access::account.shop_phone_dialog_body')}
                    </p>
                </div>

                {step === 'number' ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            request.post(link('storefront.account.phone'), {
                                preserveScroll: true,
                                preserveState: true,
                                onSuccess: () => setStep('code'),
                            });
                        }}
                        className="grid gap-4"
                    >
                        <FormError />

                        <Field
                            id="phone"
                            label={t('access::account.new_phone')}
                            hint={t('access::account.new_phone_hint')}
                            error={request.errors.phone}
                        >
                            <Input
                                id="phone"
                                name="phone"
                                type="tel"
                                required
                                dir="ltr"
                                autoComplete="tel"
                                className="tw-figure"
                                value={request.data.phone}
                                onChange={(event) =>
                                    // Arabic-Indic digits are what an Arabic keyboard gives, and
                                    // E.164 is Latin: converted here so a number typed in Arabic
                                    // is not refused for how it was written.
                                    request.setData('phone', toLatinDigits(event.target.value))
                                }
                            />
                        </Field>

                        <Button
                            type="submit"
                            disabled={request.processing}
                            data-test="send-phone-code"
                            className="w-fit"
                        >
                            {t('access::account.send_code')}
                        </Button>
                    </form>
                ) : (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            confirm.post(link('storefront.account.phone.confirm'), {
                                preserveScroll: true,
                                preserveState: true,
                                onSuccess: () => {
                                    setStep('number');
                                    request.reset();
                                    confirm.reset();
                                },
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

                            {/* Back to the number, for somebody who mistyped it: the code they are
                                being asked for went to a number they cannot change from here. */}
                            <Button
                                type="button"
                                variant="ghost"
                                data-test="cancel-phone"
                                onClick={() => {
                                    setStep('number');
                                    confirm.reset();
                                }}
                            >
                                {t('access::account.cancel')}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
