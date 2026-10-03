import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DialogError, FormError } from '@/components/FormError';
import { Time, useMoments } from '@/components/Time';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { AccountPage, StaffSessionRow, TrustedBrowserRow } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| B5 - where I am signed in, and which browsers skip my code (owner, 2026-09-26), on shadcn's Card,
| Item and AlertDialog with Geist's rules (frontend.md §1.11).
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
| Each list is a Card holding one Item group - no card inside a card - and each row is Geist's
| Entity: what it is, then one line of detail, then its one action. **Every action here is asked
| first** (§1.11, "Applied in the rebuild": ending another browser's session, Forget Browser and
| Forget All Browsers too, besides the two that sign the reader out): shadcn's AlertDialog, which
| starts on Cancel so Enter never does it by accident, and whose button repeats its title. Its
| trigger only opens it, so it is never red, and ends in "…".
*/

type Props = {
    account: AccountPage;
};

export function SessionsTab({ account }: Props) {
    const t = useTranslator();

    return (
        <div className="grid gap-6">
            <FormError />

            <Card className="material-base gap-3 border-0 py-5">
                <CardHeader className="px-6">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{t('access::account.sessions')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.sessions_hint')}</CardDescription>
                </CardHeader>
                <CardContent className="px-0">
                    <ItemGroup>
                        {account.sessions.map((session, index) => (
                            <div key={session.id} role="listitem">
                                {index === 0 ? null : <ItemSeparator />}
                                <Session session={session} />
                            </div>
                        ))}
                    </ItemGroup>
                </CardContent>
            </Card>

            <Card className="material-base gap-0 border-0 py-0">
                <CardHeader className="px-6 pt-5 pb-4">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{t('access::account.sign_out_everywhere')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.sign_out_everywhere_body')}</CardDescription>
                </CardHeader>
                <CardFooter className="justify-end border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                    <Confirm
                        title={t('access::account.sign_out_everywhere')}
                        description={t('access::account.sign_out_everywhere_body')}
                        test="sign-out-everywhere"
                        url="/admin/account/sessions/all"
                    />
                </CardFooter>
            </Card>

            <Card className="material-base gap-3 border-0 py-5">
                <CardHeader className="px-6">
                    <CardTitle className="text-heading-20 text-ink">
                        <h2>{t('access::account.trusted_browsers')}</h2>
                    </CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('access::account.trusted_browsers_hint')}</CardDescription>
                </CardHeader>
                <CardContent className="px-0">
                    {account.trustedBrowsers.length === 0 ? (
                        // No second surface inside the card (the batch B audit).
                        <Empty className="p-6">
                            <EmptyHeader>
                                <EmptyTitle>{t('access::account.no_trusted_browsers_title')}</EmptyTitle>
                                <EmptyDescription>{t('access::account.no_trusted_browsers')}</EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <ItemGroup>
                            {account.trustedBrowsers.map((browser, index) => (
                                <div key={browser.id} role="listitem">
                                    {index === 0 ? null : <ItemSeparator />}
                                    <Trusted browser={browser} />
                                </div>
                            ))}
                        </ItemGroup>
                    )}
                </CardContent>
                {account.trustedBrowsers.length === 0 ? null : (
                    <CardFooter className="justify-end border-t border-line bg-surface-sunken px-6 py-4 [.border-t]:pt-4">
                        <Confirm
                            title={t('access::account.forget_all_browsers')}
                            description={t('access::account.forget_all_browsers_body')}
                            test="forget-all-browsers"
                            url="/admin/account/trusted-browsers"
                        />
                    </CardFooter>
                )}
            </Card>
        </div>
    );
}

function Session({ session }: { session: StaffSessionRow }) {
    const t = useTranslator();

    return (
        <Item size="sm" className="rounded-none px-6" data-test={`session-${session.id}`}>
            <ItemContent className="min-w-0">
                <ItemTitle className="flex-wrap text-label-14 text-ink">
                    {/* The browser's own string, named rather than guessed at: a guess that says
                        "Windows" to somebody holding a phone is worse than the string itself. */}
                    <span className="break-words">{session.userAgent ?? t('access::account.unknown_device')}</span>
                    {session.isCurrent ? <Badge className={tone('blue-subtle')}>{t('access::account.this_browser')}</Badge> : null}
                </ItemTitle>
                <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted">
                    {t('access::account.session_seen')}: <Time value={session.lastActivity} inSentence />
                    {' · '}
                    {t('access::account.session_ip')}:{' '}
                    <bdi dir="ltr" className="tw-figure">
                        {session.ipAddress ?? '—'}
                    </bdi>
                </ItemDescription>
            </ItemContent>
            <ItemActions>
                <Confirm
                    title={session.isCurrent ? t('access::account.end_this_session') : t('access::account.end_session')}
                    description={session.isCurrent ? t('access::account.end_this_session_warning') : t('access::account.end_session_body')}
                    test={`end-session-${session.id}`}
                    url={`/admin/account/sessions/${session.id}`}
                    small
                />
            </ItemActions>
        </Item>
    );
}

function Trusted({ browser }: { browser: TrustedBrowserRow }) {
    const t = useTranslator();
    const moments = useMoments();

    return (
        <Item size="sm" className="rounded-none px-6" data-test={`trusted-${browser.id}`}>
            <ItemContent>
                <ItemTitle className="tw-figure text-label-14 text-ink">{t('access::account.trusted_until', { date: isolate(moments.date(browser.expiresAt)) })}</ItemTitle>
            </ItemContent>
            <ItemActions>
                <Confirm
                    title={t('access::account.forget_browser')}
                    description={t('access::account.forget_browser_body')}
                    test={`forget-browser-${browser.id}`}
                    url={`/admin/account/trusted-browsers/${browser.id}`}
                    small
                />
            </ItemActions>
        </Item>
    );
}

/**
 * One action, asked first: the trigger opens the question; the question's Cancel is where focus
 * starts; its button, named as the question is, posts, says it is busy, and closes once the answer
 * is back - a refusal keeps it open and is said inside it.
 *
 * Asked in the page rather than in the browser's own dialog: a window.confirm cannot be driven by a
 * test, which is how the panel's media delete went a whole step untested. The trigger keeps the old
 * button's data-test, and the confirm its `confirm-…`.
 */
function Confirm({ title, description, test, url, small = false }: { title: string; description: string; test: string; url: string; small?: boolean }) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [posting, setPosting] = useState(false);

    function act() {
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setPosting(true),
                onFinish: () => setPosting(false),
                onSuccess: () => setOpen(false),
            },
        );
    }

    return (
        <AlertDialog open={open} onOpenChange={(next) => (posting ? undefined : setOpen(next))}>
            <AlertDialogTrigger asChild>
                <Button type="button" variant="outline" size={small ? 'sm' : 'default'} data-test={test}>
                    {`${title}…`}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent className="material-modal gap-0 overflow-hidden border-0 p-0 data-[size=default]:sm:max-w-md">
                <div className="grid gap-4 p-6">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-heading-20 text-ink">{title}</AlertDialogTitle>
                        <AlertDialogDescription className="text-copy-14 text-ink-muted">{description}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <DialogError open={open} />
                </div>
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel disabled={posting} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </AlertDialogCancel>
                    <ActionButton variant="destructive" loading={posting} onClick={act} data-test={`confirm-${test}`}>
                        {title}
                    </ActionButton>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
