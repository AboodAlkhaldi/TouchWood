import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { CodeInput } from '@/components/CodeInput';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F7 - the phone tab (frontend.md §3.6), one shadcn Card in Geist's Fieldset form (§1.11), the code
| in shadcn's InputOTP, one box per digit, as many as the setting says.
|
| Two steps in one card: the number, then the code that went to it. **No password is asked for**,
| unlike the panel's. A staff member's number is where every sign-in code goes, so moving it moves
| a second factor; a customer signs in with an email and a password alone, and their number is for
| an order and a delivery (access.md §1.3, §1.8).
|
| The number in use does not change until the code is right. Until then they still have the old
| one, and a mistyped number has changed nothing.
|
| The step is not on the server. Whether a code went out is exactly "the request succeeded", so the
| card moves on when Inertia says it did, and a refusal leaves it where it was with the reason
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
        <Card className="material-base gap-0 border-0 py-0">
            <form
                onSubmit={(event) => {
                    event.preventDefault();

                    if (step === 'number') {
                        request.post(link('storefront.account.phone'), {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => setStep('code'),
                        });

                        return;
                    }

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
            >
                <CardHeader className="px-6 pt-5 pb-4">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{account.phone === null ? t('access::account.shop_add_phone') : t('access::account.shop_change_phone')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.shop_phone_dialog_body')}</CardDescription>
                </CardHeader>

                <CardContent className="grid gap-6 px-6 pb-5">
                    <Description
                        columns={1}
                        items={[
                            {
                                title: t('access::account.phone'),
                                content: (
                                    <span className="grid gap-0.5">
                                        {/* Shown in full: this is their own account, and they cannot
                                            decide whether to change a number they are not allowed to
                                            read. Left to right and in Latin digits, as a dialled
                                            number is everywhere. */}
                                        {account.phone === null ? (
                                            <span className="text-ink-muted">{t('access::account.shop_no_phone')}</span>
                                        ) : (
                                            <span className="tw-figure">
                                                <bdi dir="ltr">{account.phone}</bdi>
                                            </span>
                                        )}
                                        <span className="text-copy-13 text-ink-muted">{t('access::account.shop_phone_hint')}</span>
                                    </span>
                                ),
                            },
                        ]}
                    />

                    <FieldGroup className="gap-5">
                        <FormError />

                        {step === 'number' ? (
                            <TextField
                                id="phone"
                                name="phone"
                                type="tel"
                                label={t('access::account.new_phone')}
                                helper={t('access::account.new_phone_hint')}
                                error={request.errors.phone}
                                required
                                dir="ltr"
                                autoComplete="tel"
                                inputClassName="tw-figure"
                                value={request.data.phone}
                                onChange={(event) =>
                                    // Arabic-Indic digits are what an Arabic keyboard gives, and E.164
                                    // is Latin: converted here so a number typed in Arabic is not
                                    // refused for how it was written.
                                    request.setData('phone', toLatinDigits(event.target.value))
                                }
                            />
                        ) : (
                            <CodeInput
                                id="phone_code"
                                label={t('access::account.phone_code')}
                                length={account.codeLength}
                                value={confirm.data.code}
                                onChange={(code) => confirm.setData('code', code)}
                                error={confirm.errors.code}
                                autoFocus
                            />
                        )}
                    </FieldGroup>
                </CardContent>

                <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                    {step === 'number' ? (
                        <ActionButton type="submit" loading={request.processing} data-test="send-phone-code">
                            {t('access::account.send_code')}
                        </ActionButton>
                    ) : (
                        <>
                            {/* Back to the number, for somebody who mistyped it: the code they are
                                being asked for went to a number they cannot change from here. */}
                            <Button
                                type="button"
                                variant="ghost"
                                data-test="cancel-phone"
                                onClick={() => {
                                    setStep('number');
                                    confirm.reset();
                                    confirm.clearErrors();
                                }}
                            >
                                {t('access::account.cancel')}
                            </Button>
                            <ActionButton type="submit" loading={confirm.processing} data-test="confirm-phone">
                                {t('access::account.confirm_phone')}
                            </ActionButton>
                        </>
                    )}
                </CardFooter>
            </form>
        </Card>
    );
}
