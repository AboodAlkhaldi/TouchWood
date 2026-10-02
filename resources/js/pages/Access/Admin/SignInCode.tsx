import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { FormError } from '@/components/FormError';
import { Button, Checkbox, describedBy, FieldMessage } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SignInCodePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A3 - the SMS code (frontend.md §3.1), and A7, which is the same screen after an invitation. In
| Geist (1.10).
|
| One box per digit. How many boxes is a setting, 4 to 8 (Access amendment 22), so nothing here
| assumes six. The number the code went to is named but never shown: Access masks it to its last
| three digits before it reaches this page, so the whole number is not in a page anyone holding the
| browser can read (stage 2b, P4).
|
| "Trust this browser" and the number of days are both the server's to decide; this only shows them.
|
| Geist has no one-box-per-digit field, so the boxes are drawn in its field's look (the same line,
| hover and focus as its Input) and carry its message underneath: the error, tied to every box with
| aria-describedby, as Geist's own fields do.
*/

// The shape is generated from the PHP that produces it (frontend.md 1.6): renaming a field there
// breaks this build rather than the live page.
type Props = SignInCodePage;

const BOX =
    'tw-figure size-12 rounded-[var(--tw-radius)] bg-surface text-center text-label-20 text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] outline-none transition-shadow hover:shadow-[0_0_0_1px_var(--tw-ink-subtle)] focus:shadow-[0_0_0_1px_var(--tw-brand)]';
const BOX_ERROR = 'shadow-[0_0_0_1px_var(--tw-bad)] hover:shadow-[0_0_0_1px_var(--tw-bad)]';

export default function SignInCode({
    maskedPhone,
    length,
    trustDays,
    resendIn,
    action,
    resendAction,
}: Props) {
    const t = useTranslator();
    const form = useForm({ code: '', trust_browser: false });
    const [digits, setDigits] = useState<string[]>(() => Array.from({ length }, () => ''));
    const [waiting, setWaiting] = useState(resendIn);
    const boxes = useRef<(HTMLInputElement | null)[]>([]);
    const invalid = form.errors.code !== undefined && form.errors.code !== '';

    useEffect(() => {
        if (waiting <= 0) {
            return;
        }

        const tick = window.setInterval(() => setWaiting((left) => Math.max(0, left - 1)), 1000);

        return () => window.clearInterval(tick);
    }, [waiting > 0]);

    function put(index: number, value: string) {
        // Arabic-Indic digits are accepted too: a person typing on an Arabic keyboard is entering
        // the same number (frontend.md §1.8). They are turned into Latin ones here, where they were
        // typed - the code is compared as a hash, and a hash of ٠٥٩ is not a hash of 059. Keeping
        // them only until they were sent was the bug: the box accepted them and the sign-in then
        // refused the person for a code they had entered correctly.
        const typed = toLatinDigits(value).replace(/\D/g, '');

        // More than one digit means the whole code arrived at once - pasted from the message, or
        // filled in by the browser, which puts the lot into the first box because that is the one
        // marked one-time-code. Keeping only the last character would silently throw five digits
        // away (found by running it, 2026-09-22), so they are spread across the boxes from here on.
        const next = [...digits];

        for (let at = 0; at < typed.length && index + at < length; at++) {
            next[index + at] = typed[at] ?? '';
        }

        if (typed === '') {
            next[index] = '';
        }

        setDigits(next);
        form.setData('code', next.join(''));

        // Focus the box after the last one filled, so typing carries on where a person expects.
        const landed = Math.min(index + Math.max(typed.length, 1), length - 1);

        if (typed !== '') {
            boxes.current[landed]?.focus();
        }
    }

    return (
        <SignInLayout
            title={t('access::auth.code_title')}
            subtitle={
                maskedPhone === null
                    ? undefined
                    : t('access::auth.code_sent_to', { phone: maskedPhone })
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(action);
                }}
                className="grid gap-5"
            >
                <FormError />

                <div className="grid gap-1.5">
                    <div className="flex gap-2" dir="ltr">
                        {digits.map((digit, index) => (
                            <input
                                key={index}
                                ref={(box) => {
                                    boxes.current[index] = box;
                                }}
                                inputMode="numeric"
                                autoComplete={index === 0 ? 'one-time-code' : 'off'}
                                aria-label={t('access::auth.code_digit', { number: index + 1 })}
                                aria-invalid={invalid || undefined}
                                aria-describedby={describedBy('code', undefined, form.errors.code)}
                                autoFocus={index === 0}
                                value={digit}
                                onChange={(event) => put(index, event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Backspace' && digit === '' && index > 0) {
                                        boxes.current[index - 1]?.focus();
                                    }
                                }}
                                className={invalid ? `${BOX} ${BOX_ERROR}` : BOX}
                            />
                        ))}
                    </div>

                    <FieldMessage id="code" error={form.errors.code} />
                </div>

                <Checkbox
                    id="trust_browser"
                    checked={form.data.trust_browser}
                    onChange={(checked) => form.setData('trust_browser', checked)}
                >
                    {t('access::auth.trust_browser', { days: trustDays })}
                </Checkbox>

                <Button typeName="submit" loading={form.processing} className="w-full">
                    {t('access::auth.confirm')}
                </Button>

                {/* Out of reach until the wait is over, and says why (Geist: a disabled button
                    explains itself). The countdown in its label is the server's number. */}
                <Button
                    type="tertiary"
                    disabledReason={waiting > 0 ? t('access::auth.resend_wait_reason') : undefined}
                    onClick={() => {
                        router.post(resendAction, {}, { preserveScroll: true });
                        setWaiting(resendIn);
                    }}
                >
                    {waiting > 0
                        ? t('access::auth.resend_in', { seconds: waiting })
                        : t('access::auth.resend')}
                </Button>
            </form>
        </SignInLayout>
    );
}
