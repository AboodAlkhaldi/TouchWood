import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Button } from '@/components/ui/button';
import { FormError } from '@/components/FormError';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslator } from '@/lib/t';
import type { SignInCodePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A3 - the SMS code (frontend.md §3.1), and A7, which is the same screen after an invitation.
|
| One box per digit. How many boxes is a setting, 4 to 8 (Access amendment 22), so nothing here
| assumes six. The number the code went to is named but never shown: Access masks it to its last
| three digits before it reaches this page, so the whole number is not in a page anyone holding the
| browser can read (stage 2b, P4).
|
| "Trust this browser" and the number of days are both the server's to decide; this only shows them.
*/

// The shape is generated from the PHP that produces it (frontend.md 1.6): renaming a field there
// breaks this build rather than the live page.
type Props = SignInCodePage;

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

    useEffect(() => {
        if (waiting <= 0) {
            return;
        }

        const tick = window.setInterval(() => setWaiting((left) => Math.max(0, left - 1)), 1000);

        return () => window.clearInterval(tick);
    }, [waiting > 0]);

    function put(index: number, value: string) {
        // Arabic-Indic digits are accepted too: a person typing on an Arabic keyboard is entering
        // the same number (frontend.md §1.8).
        const typed = value.replace(/[^0-9٠-٩]/g, '');

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
                                autoFocus={index === 0}
                                value={digit}
                                onChange={(event) => put(index, event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Backspace' && digit === '' && index > 0) {
                                        boxes.current[index - 1]?.focus();
                                    }
                                }}
                                className="tw-figure size-12 rounded-md border border-line-strong bg-surface text-center text-lg text-ink"
                            />
                        ))}
                    </div>

                    {form.errors.code ? (
                        <p role="alert" className="text-xs text-bad">
                            {form.errors.code}
                        </p>
                    ) : null}
                </div>

                <label className="flex items-center gap-2 text-sm text-ink">
                    <Checkbox
                        checked={form.data.trust_browser}
                        onCheckedChange={(checked) => form.setData('trust_browser', checked === true)}
                    />
                    {t('access::auth.trust_browser', { days: trustDays })}
                </label>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.confirm')}
                </Button>

                <button
                    type="button"
                    disabled={waiting > 0}
                    onClick={() => {
                        router.post(resendAction, {}, { preserveScroll: true });
                        setWaiting(resendIn);
                    }}
                    className="text-sm text-brand disabled:text-ink-subtle"
                >
                    {waiting > 0
                        ? t('access::auth.resend_in', { seconds: waiting })
                        : t('access::auth.resend')}
                </button>
            </form>
        </SignInLayout>
    );
}
