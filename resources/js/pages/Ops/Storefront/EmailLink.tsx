import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Button } from '@/components/ui/button';
import { EmailLinkLayout } from '@/layouts/EmailLinkLayout';
import type { SharedProps } from '@/types/page';
import type { EmailLinkPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';

/*
| What an emailed link opens (ops.md §4.15 O13, §4.10, §4.11): confirming a newsletter sign-up, a guest leaving the
| newsletter, a customer stopping offers and news. Opening it changes nothing - mail scanners open links too; the one
| button does it, posting to the same address with the query string it was opened with (a leave or unsubscribe link's
| signature is in it), and then gives way to the `done` line.
|
| - Confirm's refusal (`ops.confirmation_link_invalid`, 404) says its words in the button's place.
| - Confirm alone then offers Go to the Shop, its store being on; the other two offer no way on, the store may be off.
| - A 419 (the session behind Confirm's token gone) reads the page again and presses once more with a fresh token.
| - Anything else that goes wrong, another 404 included, says "try again" and keeps the button (owner, 2026-11-05).
*/

type State = { kind: 'ready' } | { kind: 'sending' } | { kind: 'done' } | { kind: 'refused'; text: string } | { kind: 'failed' };

export default function EmailLink({ title, text, action, done, failed, shopAddress, shopLabel }: EmailLinkPageData) {
    const { url, props } = usePage<SharedProps>();
    const [state, setState] = useState<State>({ kind: 'ready' });
    const said = useRef<HTMLParagraphElement>(null);

    useEffect(() => {
        if (state.kind === 'done' || state.kind === 'refused') {
            said.current?.focus();
        }
    }, [state.kind]);

    /**
     * One press. A 419 means the session behind the page's token is gone (Confirm takes one; the other two need none):
     * the page is read again for a fresh token, and the press goes once more with it.
     */
    async function press(token: string, again: boolean) {
        setState({ kind: 'sending' });

        let response: Response;

        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                body: '{}',
            });
        } catch {
            setState({ kind: 'failed' });

            return;
        }

        if (response.ok) {
            setState({ kind: 'done' });

            return;
        }

        if (response.status === 419 && !again) {
            router.reload({
                onSuccess: (page) => void press((page.props as unknown as SharedProps).csrfToken, true),
                onNetworkError: () => {
                    setState({ kind: 'failed' });

                    return false;
                },
            });

            return;
        }

        // Only the link's own refusal takes the button's place; anything else may go through on another press.
        const problem = (await response.json().catch(() => ({}))) as { type?: unknown; detail?: unknown };
        setState(problem.type === 'ops.confirmation_link_invalid' && typeof problem.detail === 'string' ? { kind: 'refused', text: problem.detail } : { kind: 'failed' });
    }

    return (
        <EmailLinkLayout title={title} text={text}>
            {state.kind === 'done' ? (
                <div className="grid gap-4">
                    <p ref={said} tabIndex={-1} role="status" className="text-copy-14 text-ink outline-none" data-test="email-link-done">
                        {done}
                    </p>
                    {shopAddress && shopLabel ? (
                        <Button asChild variant="outline" className="w-full">
                            <a href={shopAddress} data-test="email-link-shop">
                                {shopLabel}
                            </a>
                        </Button>
                    ) : null}
                </div>
            ) : state.kind === 'refused' ? (
                <p ref={said} tabIndex={-1} role="alert" className="text-copy-14 text-bad outline-none" data-test="email-link-refused">
                    {state.text}
                </p>
            ) : (
                <div className="grid gap-3">
                    <ActionButton type="button" className="w-full" loading={state.kind === 'sending'} onClick={() => void press(props.csrfToken, false)} data-test="email-link-action">
                        {action}
                    </ActionButton>
                    {state.kind === 'failed' ? (
                        <p role="alert" className="text-copy-14 text-bad" data-test="email-link-failed">
                            {failed}
                        </p>
                    ) : null}
                </div>
            )}
        </EmailLinkLayout>
    );
}
