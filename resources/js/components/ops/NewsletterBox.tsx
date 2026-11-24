import { useEffect, useRef, useState } from 'react';
import { Mail } from 'lucide-react';
import { Link, router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { checked, describedBy, Messages } from '@/components/Fields';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { sentenceOf, subjectOf } from '@/lib/checks';
import { figure, intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
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
| - 419: the session behind the page's token is gone; the page is read again and the send goes once more;
| - anything else, no answer included: "try again", the email kept (owner, 2026-11-05).
*/

type Refusal = { text: string; signIn: boolean };

export default function NewsletterBox({ words }: { words: NewsletterWords }) {
    const t = useTranslator();
    const link = useLink();
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

    function fail() {
        setRefusal({ text: words.failed, signIn: false });
        setSending(false);
    }

    /**
     * One send. A 419 means the session behind the page's token is gone (it expired while the page stood open): the
     * page is read again for a fresh token, keeping what was typed, and the send goes once more with it.
     */
    async function send(token: string, again: boolean) {
        setSending(true);
        setRefusal(null);
        setBoxRefusal(undefined);

        let status: number;
        let answer: { type?: unknown; detail?: unknown; retryAfter?: unknown };

        try {
            const response = await fetch(address, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ email }),
            });
            status = response.status;
            answer = (await response.json().catch(() => ({}))) as typeof answer;
        } catch {
            fail();

            return;
        }

        if (status === 419 && !again) {
            router.reload({
                onSuccess: (page) => void send((page.props as unknown as SharedProps).csrfToken, true),
                onNetworkError: () => {
                    fail();

                    return false;
                },
            });

            return;
        }

        if (status === 202) {
            setSent(true);
        } else if (status === 409 && answer.type === 'ops.subscribe_in_your_account' && typeof answer.detail === 'string') {
            setRefusal({ text: answer.detail, signIn: true });
        } else if (status === 422) {
            setBoxRefusal(sentenceOf({ rule: 'email', values: {} }, subjectOf(words.email, locale), t, locale));
        } else if (status === 429 && typeof answer.retryAfter === 'number') {
            const minutes = Math.max(1, Math.ceil(answer.retryAfter / 60));
            const sentence = words.too_many[new Intl.PluralRules(intlLocale(locale)).select(minutes)] ?? words.too_many.other ?? '';
            setRefusal({ text: sentence.replace(':count', figure(locale, minutes)), signIn: false });
        } else if (status === 503 && answer.type === 'ops.newsletter_unavailable' && typeof answer.detail === 'string') {
            setRefusal({ text: answer.detail, signIn: false });
        } else {
            fail();

            return;
        }

        setSending(false);
    }

    return (
        <section aria-labelledby="newsletter-title" className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8" data-test="newsletter-box">
            {/* Part of the dark footer (owner's shape D, 2026-11-25): the copper mail mark, the footer's own inks. */}
            <div className="flex items-start gap-3">
                <Mail aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-logo-copper-light" />
                <div className="grid gap-1">
                    <h2 id="newsletter-title" className="text-heading-16 text-sidebar-ink">
                        {words.title}
                    </h2>
                    <p className="text-copy-14 text-sidebar-ink-muted">{words.text}</p>
                </div>
            </div>

            {sent ? (
                <p ref={sentLine} tabIndex={-1} role="status" className="text-copy-14 text-sidebar-ink outline-none sm:max-w-md" data-test="newsletter-sent">
                    {words.sent}
                </p>
            ) : (
                <form
                    ref={form}
                    noValidate
                    // The footer is dark whatever the page's mode, so the box, its words and its errors take the dark tokens.
                    data-mode="dark"
                    className="grid w-full gap-2 text-ink sm:max-w-md"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (!sending) {
                            checks.submit(() => void send(csrfToken, false));
                        }
                    }}
                >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                        <Field className="sm:flex-1">
                            {/* The title already says what the box is for; the label is for a screen reader. */}
                            <FieldLabel htmlFor="newsletter-email" className="sr-only">
                                {words.email}
                            </FieldLabel>
                            <Input
                                {...checked(check, { type: 'email', required: true })}
                                id="newsletter-email"
                                name="email"
                                autoComplete="email"
                                placeholder="name@example.com"
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
                        <ActionButton type="button" loading={sending} onClick={() => form.current?.requestSubmit()} className="bg-logo-copper-light text-logo-navy hover:bg-logo-copper-light/90" data-test="newsletter-subscribe">
                            {words.action}
                        </ActionButton>
                    </div>

                    {refusal ? (
                        <p id="newsletter-refusal" role="alert" className="text-copy-14 text-bad" data-test="newsletter-refusal">
                            {refusal.text}{' '}
                            {refusal.signIn ? (
                                <Link href={link('storefront.sign-in')} className="font-medium underline underline-offset-4" data-test="newsletter-sign-in">
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
