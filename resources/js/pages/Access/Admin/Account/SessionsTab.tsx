import { useState } from 'react';
import { router } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import {
    Badge,
    Button,
    EmptyState,
    Entity,
    Fieldset,
    Modal,
    ModalCancel,
    type ButtonSize,
    type ButtonType,
} from '@/components/geist';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type {
    AccountPage,
    StaffSessionRow,
    TrustedBrowserRow,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import { Time, useMoments } from '@/components/geist/Time';

/*
| B5 - where I am signed in, and which browsers skip my code (owner, 2026-09-26), in Geist (1.10).
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
|
| Each list is a Geist Fieldset; "Sign out everywhere" is its own, its button in the footer. Whatever
| signs the person reading this out is asked in a destructive Geist Modal first - Cancel focused, so
| Enter never signs anybody out by accident.
*/

type Props = {
    account: AccountPage;
};

const ROWS = 'material-base divide-y divide-line';

export function SessionsTab({ account }: Props) {
    const t = useTranslator();
    const forgetAll = usePost('/admin/account/trusted-browsers');

    return (
        <div className="grid gap-6">
            <FormError />

            <Fieldset title={t('access::account.sessions')} subtitle={t('access::account.sessions_hint')}>
                <ul className={ROWS}>
                    {account.sessions.map((session) => (
                        <li key={session.id}>
                            <Session session={session} />
                        </li>
                    ))}
                </ul>
            </Fieldset>

            <Fieldset
                title={t('access::account.sign_out_everywhere')}
                subtitle={t('access::account.sign_out_everywhere_body')}
                footerAction={
                    <Confirm
                        label={t('access::account.sign_out_everywhere_confirm')}
                        title={t('access::account.sign_out_everywhere')}
                        description={t('access::account.sign_out_everywhere_body')}
                        test="sign-out-everywhere"
                        url="/admin/account/sessions/all"
                        look="error"
                        size="medium"
                    />
                }
            >
                {null}
            </Fieldset>

            <Fieldset
                title={t('access::account.trusted_browsers')}
                subtitle={t('access::account.trusted_browsers_hint')}
                footerAction={
                    account.trustedBrowsers.length === 0 ? undefined : (
                        <Button
                            type="secondary"
                            size="small"
                            data-test="forget-all-browsers"
                            loading={forgetAll.posting}
                            onClick={forgetAll.post}
                        >
                            {t('access::account.forget_all_browsers')}
                        </Button>
                    )
                }
            >
                {account.trustedBrowsers.length === 0 ? (
                    <EmptyState
                        title={t('access::account.no_trusted_browsers_title')}
                        description={t('access::account.no_trusted_browsers')}
                    />
                ) : (
                    <ul className={ROWS}>
                        {account.trustedBrowsers.map((browser) => (
                            <li key={browser.id}>
                                <Trusted browser={browser} />
                            </li>
                        ))}
                    </ul>
                )}
            </Fieldset>
        </div>
    );
}

function Session({ session }: { session: StaffSessionRow }) {
    const t = useTranslator();

    return (
        <div
            data-test={`session-${session.id}`}
            className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
        >
            <div className="grid min-w-0 gap-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    {/* The browser's own string, named rather than guessed at: a guess that says
                        "Windows" to somebody holding a phone is worse than the string itself. */}
                    <p className="break-words text-label-14 font-medium text-ink">
                        {session.userAgent ?? t('access::account.unknown_device')}
                    </p>

                    {session.isCurrent ? (
                        <Badge variant="blue-subtle" size="small">
                            {t('access::account.this_browser')}
                        </Badge>
                    ) : null}
                </div>

                <p className="text-copy-13 text-ink-muted">
                    {t('access::account.session_ip')}:{' '}
                    <bdi dir="ltr" className="tw-figure">
                        {session.ipAddress ?? '—'}
                    </bdi>
                </p>

                {/* A date inside an Arabic sentence is reordered without an isolate. */}
                <p className="tw-figure text-copy-13 text-ink-muted">
                    {t('access::account.session_seen')}: <Time value={session.lastActivity} />
                </p>

                {session.isCurrent ? (
                    <p className="text-copy-13 text-warn">
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
                title={t('access::account.end_this_session')}
                description={t('access::account.end_this_session_warning')}
                test={`end-session-${session.id}`}
                url={`/admin/account/sessions/${session.id}`}
                quiet={!session.isCurrent}
            />
        </div>
    );
}

function Trusted({ browser }: { browser: TrustedBrowserRow }) {
    const t = useTranslator();
    const moments = useMoments();
    const forget = usePost(`/admin/account/trusted-browsers/${browser.id}`);

    return (
        <Entity
            title={
                <span className="tw-figure">
                    {t('access::account.trusted_until', { date: isolate(moments.date(browser.expiresAt)) })}
                </span>
            }
            actions={
                <Button
                    type="tertiary"
                    size="small"
                    data-test={`forget-browser-${browser.id}`}
                    loading={forget.posting}
                    onClick={forget.post}
                >
                    {t('access::account.forget_browser')}
                </Button>
            }
        />
    );
}

/**
 * A post with no fields, and whether it is still on its way - so the button that sent it shows
 * Geist's `loading` and ignores a second press meanwhile.
 */
function usePost(url: string): { posting: boolean; post: () => void } {
    const [posting, setPosting] = useState(false);

    return {
        posting,
        post: () =>
            router.post(
                url,
                {},
                {
                    preserveScroll: true,
                    onStart: () => setPosting(true),
                    onFinish: () => setPosting(false),
                },
            ),
    };
}

/**
 * A button that asks once before doing something that signs somebody out.
 *
 * Asked in the page rather than in the browser's own dialog: a window.confirm cannot be driven by
 * a test, which is how the panel's media delete went a whole step untested. The question is Geist's
 * destructive Modal: its title the statement, its body the consequence, Cancel first and focused,
 * then the button that does it - carrying the old inline confirm's data-test, `confirm-…`.
 *
 * `quiet` skips the question for ending a session that is not the one you are reading on - the
 * worst it costs is one sign-in on a machine you are not holding.
 */
function Confirm({
    label,
    title,
    description,
    test,
    url,
    quiet = false,
    look = 'secondary',
    size = 'small',
}: {
    label: string;
    title: string;
    description: string;
    test: string;
    url: string;
    quiet?: boolean;
    look?: ButtonType;
    size?: ButtonSize;
}) {
    const [asked, setAsked] = useState(false);
    const action = usePost(url);

    if (quiet) {
        return (
            <Button type="tertiary" size="small" data-test={test} loading={action.posting} onClick={action.post}>
                {label}
            </Button>
        );
    }

    return (
        <>
            <Button type={look} size={size} data-test={test} onClick={() => setAsked(true)}>
                {label}
            </Button>

            <Modal
                open={asked}
                onOpenChange={setAsked}
                destructive
                title={title}
                description={description}
                actions={
                    <>
                        <ModalCancel onClick={() => setAsked(false)} />
                        <Button
                            type="error"
                            data-test={`confirm-${test}`}
                            loading={action.posting}
                            onClick={action.post}
                        >
                            {label}
                        </Button>
                    </>
                }
            >
                {/* A refusal said inside the question the person is looking at, as well as at the
                    top of the tab behind it. */}
                <FormError />
            </Modal>
        </>
    );
}
