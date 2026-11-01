import { lazy, Suspense, useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { figure, intlLocale, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { GatewayKeyData, GatewayPage } from '@/types/generated/Modules/Payments/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The Gateway page (payments.md §4.2; the owner, 2026-11-01): one store's gateway, for Super Admins. Its state with
| a dialog saying what each switch does (Q15), a card for each mode's key - the last 6 characters, never more -
| each mode's notice address to copy, Apple Pay on this domain, and the three timers (Q17), saved through Platform's
| settings route.
*/

export const G = 'payments::admin_gateway';
const BASE = '/admin/payments/gateway';

// Loaded on first open: a key's dialog is opened rarely.
const KeyDialog = lazy(() => import('./KeyDialog').then((module) => ({ default: module.KeyDialog })));

type Mode = 'LIVE' | 'TEST';
type Confirm = 'on' | 'off' | 'live' | 'test' | null;

export default function Gateway(page: GatewayPage) {
    const t = useTranslator();
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [keyFor, setKeyFor] = useState<Mode | null>(null);

    return (
        <AdminLayout title={t(`${G}.title`)} subtitle={t(`${G}.subtitle`)}>
            <div className="grid gap-4">
                <StoreFilter stores={page.stores} value={page.storeCode} />
                <FormError />
                {!page.offered ? (
                    <Note variant="secondary" label={t(`${G}.title`)} data-test="not-offered">
                        {t(`${G}.not_offered`)}
                    </Note>
                ) : (
                    <>
                        <StateCard page={page} onConfirm={setConfirm} />
                        <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-2">
                            <KeyCard mode="LIVE" inUse={page.mode === 'LIVE'} value={page.liveKey} onChange={() => setKeyFor('LIVE')} />
                            <KeyCard mode="TEST" inUse={page.mode === 'TEST'} value={page.testKey} onChange={() => setKeyFor('TEST')} />
                        </div>
                        <NoticeCard page={page} />
                        <ApplePayCard page={page} />
                        <TimersCard page={page} />
                    </>
                )}
            </div>
            {confirm === null ? null : <ConfirmDialog kind={confirm} page={page} onClose={() => setConfirm(null)} />}
            <Suspense fallback={null}>{keyFor === null ? null : <KeyDialog mode={keyFor} storeCode={page.storeCode} onClose={() => setKeyFor(null)} />}</Suspense>
        </AdminLayout>
    );
}

function Section({ title, test, children }: { title: string; test: string; children: React.ReactNode }) {
    return (
        <Card className="material-base" data-test={test}>
            <CardHeader>
                <CardTitle className="text-heading-16 text-ink">{title}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">{children}</CardContent>
        </Card>
    );
}

function StateCard({ page, onConfirm }: { page: GatewayPage; onConfirm: (kind: Confirm) => void }) {
    const t = useTranslator();
    const modeName = (mode: Mode) => t(`${G}.mode.${mode}`);
    const hasKey = page.mode === 'LIVE' ? page.liveKey !== null : page.testKey !== null;
    const other: Mode = page.mode === 'LIVE' ? 'TEST' : 'LIVE';
    // On, yet checkout offers only staff: the key in use was refused (§1.2).
    const stopped = page.active && (page.mode === 'LIVE' ? page.liveKey : page.testKey)?.rejected === true;
    const otherHasKey = other === 'LIVE' ? page.liveKey !== null : page.testKey !== null;
    const onReason = !page.setUp ? t(`${G}.state.needs_setup`) : !hasKey && page.mode !== null ? t(`${G}.state.needs_key`, { mode: modeName(page.mode as Mode) }) : undefined;
    const modeReason = !page.setUp ? t(`${G}.state.needs_setup`) : !otherHasKey ? t(`${G}.state.needs_key`, { mode: modeName(other) }) : undefined;

    return (
        <Section title={t(`${G}.state.title`)} test="gateway-state">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="flex items-center gap-2 text-copy-14 text-ink">
                    {t(`${G}.state.online`)}
                    <Badge className={tone(stopped ? 'red-subtle' : page.active ? 'green-subtle' : 'gray-subtle')} data-test="gateway-active">
                        {stopped ? t(`${G}.state.stopped`) : page.active ? t(`${G}.state.on`) : t(`${G}.state.off`)}
                    </Badge>
                </span>
                <ActionButton variant="outline" disabledReason={page.active ? undefined : onReason} onClick={() => onConfirm(page.active ? 'off' : 'on')} data-test="gateway-switch">
                    {page.active ? t(`${G}.state.turn_off`) : t(`${G}.state.turn_on`)}
                </ActionButton>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
                <span className="flex items-center gap-2 text-copy-14 text-ink">
                    {t(`${G}.state.mode`)}
                    {page.mode === null ? null : (
                        <Badge className={tone(page.mode === 'LIVE' ? 'blue-subtle' : 'amber-subtle')} data-test="gateway-mode">
                            {modeName(page.mode as Mode)}
                        </Badge>
                    )}
                </span>
                <ActionButton variant="outline" disabledReason={modeReason} onClick={() => onConfirm(page.mode === 'LIVE' ? 'test' : 'live')} data-test="gateway-mode-switch">
                    {page.mode === 'LIVE' ? t(`${G}.state.to_test`) : t(`${G}.state.to_live`)}
                </ActionButton>
            </div>
        </Section>
    );
}

function KeyCard({ mode, inUse, value, onChange }: { mode: Mode; inUse: boolean; value: GatewayKeyData | null; onChange: () => void }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const name = t(`${G}.mode.${mode}`);
    const test = `key-${mode}`;
    const expiry = value === null ? '' : new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value.expiresOn}T00:00:00Z`));
    const left = value === null ? '' : value.daysLeft < 0 ? t(`${G}.keys.expired`) : value.daysLeft === 0 ? t(`${G}.keys.days_left_today`) : value.daysLeft === 1 ? t(`${G}.keys.days_left_one`) : t(`${G}.keys.days_left_some`, { count: figure('en', value.daysLeft) });

    return (
        <Section title={t(`${G}.keys.title`, { mode: name })} test={test}>
            {value === null ? (
                <p className="text-copy-14 text-ink-muted">{t(`${G}.keys.none`)}</p>
            ) : (
                <dl className="grid gap-1.5 text-copy-14">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">{t(`${G}.keys.last6`)}</dt>
                        <dd>
                            <bdi dir="ltr" className="tw-figure" data-test={`${test}-last6`}>
                                {value.last6}
                            </bdi>
                        </dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">{t(`${G}.keys.set_at`)}</dt>
                        <dd>
                            <Time value={value.setAt} />
                        </dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">{t(`${G}.keys.expires`)}</dt>
                        <dd className="text-end">
                            {expiry} · {left}
                        </dd>
                    </div>
                </dl>
            )}
            {value?.rejected === true ? (
                <Note variant="error" label={t(`${G}.keys.refused`)} data-test={`${test}-refused`}>
                    {inUse ? t(`${G}.keys.refused_body`) : t(`${G}.keys.refused_other`)}
                </Note>
            ) : value !== null && value.daysLeft <= 30 ? (
                <Note variant="warning" label={t(`${G}.keys.renew_label`)} data-test={`${test}-renew`}>
                    {t(`${G}.keys.renew`)}
                </Note>
            ) : null}
            <Button type="button" variant="outline" className="w-fit" onClick={onChange} data-test={`${test}-change`}>
                {value === null ? t(`${G}.keys.add`) : t(`${G}.keys.change`)}
            </Button>
        </Section>
    );
}

function NoticeCard({ page }: { page: GatewayPage }) {
    const t = useTranslator();
    const rows: [Mode, string][] = [
        ['LIVE', page.noticeLive],
        ['TEST', page.noticeTest],
    ];

    return (
        <Section title={t(`${G}.notice.title`)} test="gateway-notice">
            <p className="text-copy-14 text-ink-muted">{t(`${G}.notice.body`)}</p>
            <dl className="grid gap-2 text-copy-14">
                {rows.map(([mode, address]) => (
                    <div key={mode} className="grid gap-0.5">
                        <dt className="text-ink-muted">{t(`${G}.mode.${mode}`)}</dt>
                        <dd className="flex min-w-0 items-center gap-1">
                            <bdi dir="ltr" className="min-w-0 [overflow-wrap:anywhere]" data-test={`notice-${mode}`}>
                                {address}
                            </bdi>
                            <CopyButton text={address} label={t(`${G}.notice.copy`, { mode: t(`${G}.mode.${mode}`) })} />
                        </dd>
                    </div>
                ))}
            </dl>
        </Section>
    );
}

function ApplePayCard({ page }: { page: GatewayPage }) {
    const t = useTranslator();
    const [busy, setBusy] = useState<Mode | null>(null);
    const rows: [Mode, boolean, GatewayKeyData | null][] = [
        ['LIVE', page.applePayLive, page.liveKey],
        ['TEST', page.applePayTest, page.testKey],
    ];

    return (
        <Section title={t(`${G}.apple.title`)} test="gateway-apple">
            <p className="text-copy-14 text-ink-muted">{t(`${G}.apple.body`)}</p>
            <p className="text-copy-14 text-ink">
                {t(`${G}.apple.domain`)}:{' '}
                <bdi dir="ltr" className="[overflow-wrap:anywhere]" data-test="apple-domain">
                    {page.currentDomain}
                </bdi>
            </p>
            <div className="grid gap-2">
                {rows.map(([mode, registered, key]) => (
                    <div key={mode} className="flex flex-wrap items-center justify-between gap-3" data-test={`apple-${mode}`}>
                        <span className="flex items-center gap-2 text-copy-14 text-ink">
                            {t(`${G}.mode.${mode}`)}
                            <Badge className={tone(registered ? 'green-subtle' : 'gray-subtle')}>{registered ? t(`${G}.apple.registered`) : t(`${G}.apple.not_registered`)}</Badge>
                        </span>
                        <ActionButton
                            variant="outline"
                            loading={busy === mode}
                            disabledReason={key === null ? t(`${G}.apple.needs_key`, { mode: t(`${G}.mode.${mode}`) }) : undefined}
                            onClick={() => router.post(`${BASE}/apple-pay`, { store: page.storeCode, mode }, { preserveScroll: true, onStart: () => setBusy(mode), onFinish: () => setBusy(null) })}
                            data-test={`apple-register-${mode}`}
                        >
                            {registered ? t(`${G}.apple.register_again`) : t(`${G}.apple.register`)}
                        </ActionButton>
                    </div>
                ))}
            </div>
        </Section>
    );
}

const TIMER_KEYS = { window: 'payments.timers.window_minutes', wait: 'payments.timers.wait_minutes', hold: 'payments.timers.hold_minutes' } as const;

/**
 * Three settings, each saved alone and each checked against the other two as it is saved (TimersGuard). So what shrinks
 * goes first and what grows last, the hold limit between them: every step on the way is itself allowed.
 */
export function timerOrder(before: Record<keyof typeof TIMER_KEYS, number>, after: Record<keyof typeof TIMER_KEYS, number>): (keyof typeof TIMER_KEYS)[] {
    const others = ['window', 'wait'] as const;

    return [...others.filter((each) => after[each] < before[each]), ...(after.hold === before.hold ? [] : (['hold'] as const)), ...others.filter((each) => after[each] > before[each])];
}

function TimersCard({ page }: { page: GatewayPage }) {
    const t = useTranslator();
    const [window, setWindow] = useState(String(page.windowMinutes));
    const [wait, setWait] = useState(String(page.waitMinutes));
    const [hold, setHold] = useState(String(page.holdMinutes));
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    // Back to what is saved once a save is over: the next one must not write someone else's change back.
    useEffect(() => {
        if (!saving) {
            setWindow(String(page.windowMinutes));
            setWait(String(page.waitMinutes));
            setHold(String(page.holdMinutes));
        }
    }, [saving, page.windowMinutes, page.waitMinutes, page.holdMinutes]);
    const minutes = t(`${G}.timers.minutes`);
    const holdTooShort = Number(hold) <= Number(window) + Number(wait);
    const checks = useChecks(
        [
            { id: 'timer-window', label: t(`${G}.timers.window`), value: window, rules: { required: true, number: { min: 1, max: 30 } } },
            { id: 'timer-wait', label: t(`${G}.timers.wait`), value: wait, rules: { required: true, number: { min: 1, max: 10 } } },
            { id: 'timer-hold', label: t(`${G}.timers.hold`), value: hold, rules: { required: true, number: { min: 10, max: 60 } } },
        ],
        true,
    );
    const save = () => {
        const before = { window: page.windowMinutes, wait: page.waitMinutes, hold: page.holdMinutes };
        const after = { window: Number(window), wait: Number(wait), hold: Number(hold) };
        const steps = timerOrder(before, after);
        const next = (index: number) => {
            const step = steps[index];

            if (step === undefined) {
                setSaving(false);
                setSaved(true);

                return;
            }

            router.post(`/admin/settings/${TIMER_KEYS[step]}`, { store: page.storeCode, value: String(after[step]) }, {
                preserveScroll: true,
                onSuccess: (visit) => {
                    // A refusal comes back as a page too, with errors.
                    if (Object.keys(visit.props.errors ?? {}).length > 0) {
                        setSaving(false);
                    } else {
                        next(index + 1);
                    }
                },
                onError: () => setSaving(false),
                onNetworkError: () => setSaving(false),
                onHttpException: () => setSaving(false),
            });
        };

        setSaved(false);
        setSaving(true);
        next(0);
    };
    const box = (id: string, label: string, value: string, set: (value: string) => void) => (
        <TextField id={id} dir="ltr" inputMode="numeric" inputClassName="tw-figure" label={`${label} (${minutes})`} value={value} check={checks.box(id)} onChange={(event) => set(toLatinDigits(event.target.value))} data-test={id} />
    );

    return (
        <Section title={t(`${G}.timers.title`)} test="gateway-timers">
            <p className="text-copy-14 text-ink-muted">{t(`${G}.timers.body`)}</p>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-4 md:grid-cols-3">
                {box('timer-window', t(`${G}.timers.window`), window, setWindow)}
                {box('timer-wait', t(`${G}.timers.wait`), wait, setWait)}
                {box('timer-hold', t(`${G}.timers.hold`), hold, setHold)}
            </div>
            <ActionButton className="w-fit" loading={saving} disabledReason={checks.reason ?? (holdTooShort ? t(`${G}.timers.hold_rule`) : undefined)} onClick={() => checks.submit(save)} data-test="timers-save">
                {t(`${G}.timers.save`)}
            </ActionButton>
            {saved ? (
                <p role="status" className="text-copy-13 text-ink-muted" data-test="timers-saved">
                    {t(`${G}.timers.saved`)}
                </p>
            ) : null}
        </Section>
    );
}

/** What each switch does, said before it happens (Q15): no word to type. */
function ConfirmDialog({ kind, page, onClose }: { kind: Exclude<Confirm, null>; page: GatewayPage; onClose: () => void }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const title = t(`${G}.confirm.${kind}_title`);
    const send = () =>
        kind === 'on' || kind === 'off'
            ? router.post(`${BASE}/switch`, { store: page.storeCode, on: kind === 'on' }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })
            : router.post(`${BASE}/mode`, { store: page.storeCode, mode: kind === 'live' ? 'LIVE' : 'TEST' }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose });

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={title}
            description={t(`${G}.confirm.${kind}_body`, { mode: page.mode === null ? '' : t(`${G}.mode.${page.mode}`) })}
            busy={busy}
            confirm={
                <ActionButton loading={busy} onClick={send} data-test="gateway-confirm">
                    {title}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
