import { useState } from 'react';
import { router } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type {
    AccountPage,
    StaffSessionRow,
    TrustedBrowserRow,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B5 - where I am signed in, and which browsers skip my code (owner, 2026-09-26).
|
| **Two lists, because they are two things**, and confusing them is how somebody thinks they have
| secured an account they have not:
|
|   - a **session** is a browser signed in now, which ending logs out;
|   - a **trusted browser** is one allowed in with the password alone, skipping the SMS code, until
|     the trust expires. Untrusting signs nobody out; ending a session untrusts nothing.
|
| "Sign out everywhere" does both, which is why it is the one offered for the case this screen was
| asked for: an account lent to somebody for a job, and wanted back.
|
| Nothing here reaches another person's account. The owner's own limit on the feature.
*/

type Props = {
    account: AccountPage;
};

export function SessionsTab({ account }: Props) {
    const t = useTranslator();

    return (
        <div className="grid gap-6">
            <FormError />

            <section className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
                <div className="grid gap-1">
                    <h2 className="text-sm font-semibold text-ink">{t('access::account.sessions')}</h2>
                    <p className="text-xs text-ink-muted">{t('access::account.sessions_hint')}</p>
                </div>

                <ul className="grid gap-3">
                    {account.sessions.map((session) => (
                        <li key={session.id}>
                            <Session session={session} />
                        </li>
                    ))}
                </ul>
            </section>

            <section className="grid gap-3 rounded-lg border border-bad/30 bg-bad-soft p-6">
                <p className="text-sm font-medium text-bad">
                    {t('access::account.sign_out_everywhere')}
                </p>
                <p className="text-xs text-ink-muted">
                    {t('access::account.sign_out_everywhere_body')}
                </p>

                <Confirm
                    label={t('access::account.sign_out_everywhere_confirm')}
                    test="sign-out-everywhere"
                    url="/admin/account/sessions/all"
                />
            </section>

            <section className="grid gap-4 rounded-lg border border-line bg-surface p-6 shadow-card">
                <div className="grid gap-1">
                    <h2 className="text-sm font-semibold text-ink">
                        {t('access::account.trusted_browsers')}
                    </h2>
                    <p className="text-xs text-ink-muted">
                        {t('access::account.trusted_browsers_hint')}
                    </p>
                </div>

                {account.trustedBrowsers.length === 0 ? (
                    <p className="text-sm text-ink-muted">
                        {t('access::account.no_trusted_browsers')}
                    </p>
                ) : (
                    <>
                        <ul className="grid gap-2">
                            {account.trustedBrowsers.map((browser) => (
                                <li key={browser.id}>
                                    <Trusted browser={browser} />
                                </li>
                            ))}
                        </ul>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            data-test="forget-all-browsers"
                            onClick={() =>
                                router.post(
                                    '/admin/account/trusted-browsers',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('access::account.forget_all_browsers')}
                        </Button>
                    </>
                )}
            </section>
        </div>
    );
}

function Session({ session }: { session: StaffSessionRow }) {
    const t = useTranslator();

    return (
        <div
            data-test={`session-${session.id}`}
            className={[
                'flex flex-wrap items-start justify-between gap-3 rounded-md border p-4',
                session.isCurrent ? 'border-brand bg-brand-soft/30' : 'border-line',
            ].join(' ')}
        >
            <div className="grid gap-0.5">
                {/* The browser's own string, named rather than guessed at: a guess that says
                    "Windows" to somebody holding a phone is worse than the string itself. */}
                <p className="text-sm font-medium text-ink">
                    {session.userAgent ?? t('access::account.unknown_device')}
                    {session.isCurrent ? (
                        <span className="ms-2 rounded-md bg-brand-soft px-2 py-0.5 text-xs text-brand">
                            {t('access::account.this_browser')}
                        </span>
                    ) : null}
                </p>

                <p className="text-xs text-ink-muted">
                    {t('access::account.session_ip')}:{' '}
                    <bdi dir="ltr" className="tw-figure">
                        {session.ipAddress ?? '—'}
                    </bdi>
                </p>

                {/* A date inside an Arabic sentence is reordered without an isolate. */}
                <p className="tw-figure text-xs text-ink-muted">
                    {t('access::account.session_seen')}: {isolate(session.lastActivity.slice(0, 16).replace('T', ' '))}
                </p>

                {session.isCurrent ? (
                    <p className="text-xs text-warn">
                        {t('access::account.end_this_session_warning')}
                    </p>
                ) : null}
            </div>

            <Confirm
                label={
                    session.isCurrent
                        ? t('access::account.end_this_session')
                        : t('access::account.end_session')
                }
                test={`end-session-${session.id}`}
                url={`/admin/account/sessions/${session.id}`}
                quiet={!session.isCurrent}
            />
        </div>
    );
}

function Trusted({ browser }: { browser: TrustedBrowserRow }) {
    const t = useTranslator();

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-3">
            <p className="tw-figure text-sm text-ink-muted">
                {t('access::account.trusted_until', { date: isolate(browser.expiresAt.slice(0, 10)) })}
            </p>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                data-test={`forget-browser-${browser.id}`}
                onClick={() =>
                    router.post(
                        `/admin/account/trusted-browsers/${browser.id}`,
                        {},
                        { preserveScroll: true },
                    )
                }
            >
                {t('access::account.forget_browser')}
            </Button>
        </div>
    );
}

/**
 * A button that asks once before doing something that signs somebody out.
 *
 * Asked in the page rather than in the browser's own dialog: a window.confirm cannot be driven by
 * a test, which is how the panel's media delete went a whole step untested.
 *
 * `quiet` skips the question for ending a session that is not the one you are reading on - the
 * worst it costs is one sign-in on a machine you are not holding.
 */
function Confirm({
    label,
    test,
    url,
    quiet = false,
}: {
    label: string;
    test: string;
    url: string;
    quiet?: boolean;
}) {
    const t = useTranslator();
    const [asked, setAsked] = useState(false);

    function go() {
        router.post(url, {}, { preserveScroll: true });
    }

    if (quiet) {
        return (
            <Button type="button" variant="ghost" size="sm" data-test={test} onClick={go}>
                {label}
            </Button>
        );
    }

    return asked ? (
        <span className="flex gap-2">
            <Button type="button" size="sm" data-test={`confirm-${test}`} onClick={go}>
                {label}
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setAsked(false)}>
                {t('access::account.cancel')}
            </Button>
        </span>
    ) : (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="w-fit"
            data-test={test}
            onClick={() => setAsked(true)}
        >
            {label}
        </Button>
    );
}
