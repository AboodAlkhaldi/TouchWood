import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { checked, describedBy, Messages } from '@/components/Fields';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { sentenceOf, subjectOf } from '@/lib/checks';
import { figure, intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { NewsletterWords, SharedProps } from '@/types/page';

/*
| The shop's newsletter box (ops.md §4.15 O11, §4.9), in the footer of every page under a store, for a guest alone:
| the server sends its words only to one (Ops' part of the frame), and the layout draws it only then. It reads
| nothing until it is used. Loaded after the page has drawn, so no shop page carries it on first paint.
|
| Sent as JSON, and each answer said where it belongs:
| - 202: the form gives way to the `sent` line, the same whether or not the email was on the list;
| - 409 `ops.subscribe_in_your_account`: the problem's words, with the way to sign in;
| - 422 `email`: the box's own rule, which is also checked as typed;
| - 429 `{retryAfter}`: how many minutes to wait;
| - 503 `ops.newsletter_unavailable`: the problem's words, the email kept;
| - anything else, no answer included: "try again", the email kept (owner, 2026-11-05).
*/

type Refusal = { text: string; signIn: boolean };

export default function NewsletterBox({ words }: { words: NewsletterWords }) {
    const t = useTranslator();
    const { shop, locale, csrfToken } = usePage<SharedProps>().props;
    const [email, setEmail] = useState('');
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [boxRefusal, setBoxRefusal] = useState<string | undefined>(undefined);
    const sentLine = useRef<HTMLParagraphElement>(null);
    const form = useRef<HTMLFormElement>(null);

    // The server's rule: 3 to 254 characters in an address's shape (§4.9).
    const checks = useChecks([{ id: 'newsletter-email', label: words.email, value: email, rules: { required: true, email: true, length: { min: 3, max: 254 } } }]);
    const check = checks.box('newsletter-email', boxRefusal);

    useEffect(() => {
        if (sent) {
            sentLine.current?.focus();
        }
    }, [sent]);

    if (shop === null || shop === undefined) {
        return null;
    }

    const address = `/${shop.code}/${locale}/newsletter`;

    async function send() {
        setSending(true);
        setRefusal(null);
        setBoxRefusal(undefined);

        try {
            const response = await fetch(address, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ email }),
            });
            const answer = (await response.json().catch(() => ({}))) as { detail?: unknown; retryAfter?: unknown };

            if (response.status === 202) {
                setSent(true);
            } else if (response.status === 409 && typeof answer.detail === 'string') {
                setRefusal({ text: answer.detail, signIn: true });
            } else if (response.status === 422) {
                setBoxRefusal(sentenceOf({ rule: 'email', values: {} }, subjectOf(words.email, locale), t, locale));
            } else if (response.status === 429 && typeof answer.retryAfter === 'number') {
                const minutes = Math.max(1, Math.ceil(answer.retryAfter / 60));
                const sentence = words.too_many[new Intl.PluralRules(intlLocale(locale)).select(minutes)] ?? words.too_many.other ?? '';
                setRefusal({ text: sentence.replace(':count', figure(locale, minutes)), signIn: false });
            } else if (response.status === 503 && typeof answer.detail === 'string') {
                setRefusal({ text: answer.detail, signIn: false });
            } else {
                setRefusal({ text: words.failed, signIn: false });
            }
        } catch {
            setRefusal({ text: words.failed, signIn: false });
        } finally {
            setSending(false);
        }
    }

    return (
        <section aria-labelledby="newsletter-title" className="grid gap-3" data-test="newsletter-box">
            <div className="grid gap-1">
                <h2 id="newsletter-title" className="text-heading-16 text-ink">
                    {words.title}
                </h2>
                <p className="text-copy-14 text-ink-muted">{words.text}</p>
            </div>

            {sent ? (
                <p ref={sentLine} tabIndex={-1} role="status" className="text-copy-14 text-ink outline-none" data-test="newsletter-sent">
                    {words.sent}
                </p>
            ) : (
                <form
                    ref={form}
                    noValidate
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!sending) {
                            checks.submit(() => void send());
                        }
                    }}
                >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <Field className="sm:max-w-sm sm:flex-1">
                            {/* The title already says what the box is for; the label is for a screen reader. */}
                            <FieldLabel htmlFor="newsletter-email" className="sr-only">
                                {words.email}
                            </FieldLabel>
                            <Input
                                {...checked(check, { type: 'email', required: true })}
                                id="newsletter-email"
                                name="email"
                                autoComplete="email"
                                dir="ltr"
                                value={email}
                                onChange={(event) => {
                                    setEmail(event.target.value);
                                    setRefusal(null);
                                    check.onType();
                                }}
                                aria-invalid={check.message ? true : undefined}
                                aria-describedby={describedBy('newsletter-email', undefined, check.message, refusal ? 'newsletter-refusal' : undefined)}
                                data-test="newsletter-email"
                            />
                            <Messages id="newsletter-email" error={check.message} check={check} />
                        </Field>
                        {/* Not a submit button: this box is in the footer of every shop page, and a page's own
                            form keeps the only submit button there. Enter in the box still sends it, the form's
                            one field submitting it implicitly. */}
                        <ActionButton type="button" loading={sending} onClick={() => form.current?.requestSubmit()} data-test="newsletter-subscribe">
                            {words.action}
                        </ActionButton>
                    </div>

                    {refusal ? (
                        <p id="newsletter-refusal" role="alert" className="text-copy-14 text-bad" data-test="newsletter-refusal">
                            {refusal.text}{' '}
                            {refusal.signIn ? (
                                <Link href={`/${shop.code}/${locale}/sign-in`} className="font-medium underline underline-offset-4" data-test="newsletter-sign-in">
                                    {words.sign_in}
                                </Link>
                            ) : null}
                        </p>
                    ) : null}
                </form>
            )}
        </section>
    );
}
