import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { PasswordInput } from '@/components/PasswordInput';
import { Button, Input, Modal, ModalCancel } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { AccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B2 - changing the phone (frontend.md §3.2), in Geist (1.10).
|
| Two steps in one modal. First the current password and the new number; then the code that went to
| that number. The password is asked for because the number is where every sign-in code goes: a
| stolen session must not be able to move the second factor on its own (owner, 2026-09-21). A wrong
| password here counts exactly like a wrong one at sign-in, so the lockout can arrive in this modal
| too - which is why the refusal is shown inside it rather than only as a toast behind it.
|
| The number in use does not change until the code is right. Until then the person still has the old
| one, and nothing about their sign-in has moved.
|
| The step is not on the server. Whether a code went out is exactly "the request succeeded", so the
| modal moves on when Inertia says it did, and a refusal leaves it where it was with the reason
| beside the field.
|
| Each step's button sits in the modal's footer (Geist: Cancel, then the primary action), outside
| its form, and is tied to it by the form's id - so Enter in a field still sends the step.
*/

type Props = {
    account: AccountPage;
};

/**
 * The figure face (§1.8) on the box inside Geist's Input. The Input takes its class on the field as
 * a whole - label and helper included - so `tw-figure` there would set the label in the mono face
 * too. This is the same rule aimed at the box alone: mono with even digits, and the Arabic face on
 * an Arabic page, exactly as `tw-figure` does.
 */
const FIGURES = '[&_input]:font-mono [&_input]:tabular-nums [[lang=ar]_&_input]:font-sans';

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
        <section className="material-base grid gap-3 p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid gap-1">
                    <h2 className="text-heading-16 text-ink">{t('access::account.phone')}</h2>

                    {/* Shown in full: this is the person's own account, and they cannot decide
                        whether to change a number they are not allowed to read (2026-09-23). Left
                        to right and in Latin digits, as a dialled number is everywhere. */}
                    <p className="tw-figure text-copy-14 text-ink-muted" dir="ltr">
                        {account.phone ?? ''}
                    </p>

                    {account.phone === null ? (
                        <p className="text-copy-13 text-ink-muted">{t('access::account.no_phone')}</p>
                    ) : (
                        <p className="text-copy-13 text-ink-muted">{t('access::account.phone_hint')}</p>
                    )}
                </div>

                <Button
                    type="secondary"
                    size="small"
                    onClick={() => setOpen(true)}
                    data-test="open-phone-dialog"
                >
                    {t('access::account.change_phone')}
                </Button>
            </div>

            <Modal
                open={open}
                onOpenChange={(next) => (next ? setOpen(true) : close())}
                title={t('access::account.phone_dialog_title')}
                description={t('access::account.phone_dialog_body')}
                actions={
                    step === 'phone' ? (
                        <>
                            <ModalCancel onClick={close} />
                            <Button
                                typeName="submit"
                                form="phone-request-form"
                                loading={request.processing}
                                data-test="send-phone-code"
                            >
                                {t('access::account.send_code')}
                            </Button>
                        </>
                    ) : (
                        <>
                            <ModalCancel onClick={close} />
                            <Button
                                typeName="submit"
                                form="phone-code-form"
                                loading={confirm.processing}
                                data-test="confirm-phone"
                            >
                                {t('access::account.confirm_phone')}
                            </Button>
                        </>
                    )
                }
            >
                {step === 'phone' ? (
                    <form
                        id="phone-request-form"
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

                        <Input
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
                            value={request.data.phone}
                            onChange={(event) =>
                                request.setData('phone', toLatinDigits(event.target.value))
                            }
                        />

                        <PasswordInput
                            id="phone_current_password"
                            name="current_password"
                            label={t('access::account.current_password')}
                            error={request.errors.current_password}
                            autoComplete="current-password"
                            required
                            value={request.data.current_password}
                            onChange={(event) =>
                                request.setData('current_password', event.target.value)
                            }
                        />
                    </form>
                ) : (
                    <form
                        id="phone-code-form"
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

                        <Input
                            id="phone_code"
                            name="code"
                            label={t('access::account.phone_code')}
                            error={confirm.errors.code}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            required
                            autoFocus
                            dir="ltr"
                            className={FIGURES}
                            value={confirm.data.code}
                            onChange={(event) =>
                                confirm.setData('code', toLatinDigits(event.target.value))
                            }
                        />
                    </form>
                )}
            </Modal>
        </section>
    );
}
