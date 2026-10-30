import { useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { PanelDialog } from '@/components/PanelDialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Switch } from '@/components/ui/switch';
import { initials } from '@/lib/initials';
import { useTranslator } from '@/lib/t';
import type { CarrierData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| What the carriers list (C1) and a carrier's page (C3) share (shipping.md §4.1 part 1): the carrier's
| name in the page's language, its logo or its initials (Q15), the on/off switch that asks before
| switching off (Q5), Delete's dialog (Q6), and what a tracking link pattern opens (Q8).
*/

export const C = 'shipping::admin_carriers';

export const LIST = '/admin/shipping/carriers';

/** The carrier's name in the page's language, the other language's when it has none. */
export function useCarrierName(): (carrier: CarrierData) => string {
    const { locale } = usePage<SharedProps>().props;

    return (carrier) => (locale === 'ar' ? carrier.nameAr || carrier.nameEn : carrier.nameEn || carrier.nameAr);
}

/** The other language's name, shown under the page's (frontend.md §1.11). */
export function useOtherName(): (carrier: CarrierData) => string {
    const { locale } = usePage<SharedProps>().props;

    return (carrier) => (locale === 'ar' ? carrier.nameEn : carrier.nameAr);
}

/** Its logo's thumbnail, else its initials in a circle (Q15) - shadcn's Avatar and its fallback. */
export function CarrierLogo({ carrier, name, size = 'size-8' }: { carrier: CarrierData; name: string; size?: string }) {
    return (
        <Avatar className={`${size} rounded-md border border-line`} data-test={`carrier-logo-${carrier.id}`}>
            {carrier.logo !== null ? <AvatarImage src={carrier.logo} alt="" className="object-contain" /> : null}
            {/* The name is said beside it: the initials are not read out first. */}
            <AvatarFallback aria-hidden="true" className="rounded-md text-label-12">
                {initials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

/** Where a tracking number opens with this pattern, or null when the pattern cannot make a link (Q8). */
export function trackingExample(pattern: string): string | null {
    const trimmed = pattern.trim();

    return /^https:\/\//i.test(trimmed) && trimmed.split('{number}').length === 2 ? trimmed.replace('{number}', '12345') : null;
}

/** What is wrong with a pattern as typed, in words - null when it is right or empty (§1.3: https, one {number}). */
export function trackingProblem(pattern: string, t: (key: string) => string): string | null {
    const trimmed = pattern.trim();

    if (trimmed === '') {
        return null;
    }

    if (!/^https:\/\//i.test(trimmed)) {
        return t(`${C}.tracking.https`);
    }

    return trimmed.split('{number}').length === 2 ? null : t(`${C}.tracking.number`);
}

/**
 * The on/off switch (Q5): switching on is saved at once; switching off asks first, saying it leaves every
 * new checkout and that placed orders keep it.
 */
export function CarrierSwitch({ carrier, name, storeCode, test }: { carrier: CarrierData; name: string; storeCode: string; test?: string }) {
    const t = useTranslator();
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const send = (active: boolean) =>
        router.post(
            `${LIST}/${carrier.id}/switch`,
            { store: storeCode, active },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setAsking(false) },
        );

    return (
        <>
            <Switch
                checked={carrier.active}
                disabled={busy}
                onCheckedChange={(on) => (on ? send(true) : setAsking(true))}
                aria-label={t(`${C}.switch.label`, { name })}
                className="data-[state=unchecked]:bg-ink-subtle"
                data-test={test ?? `carrier-switch-${carrier.id}`}
            />
            <PanelDialog
                open={asking}
                onOpenChange={setAsking}
                title={t(`${C}.switch.off_title`, { name })}
                description={t(`${C}.switch.off_body`)}
                busy={busy}
                confirm={
                    <ActionButton loading={busy} onClick={() => send(false)} data-test="confirm-carrier-off">
                        {t(`${C}.switch.off_confirm`)}
                    </ActionButton>
                }
            >
                {null}
            </PanelDialog>
        </>
    );
}

/**
 * Delete, while the carrier is off (Q6): Geist's Destructive Action Modal - the carrier's name typed to
 * confirm, the red band for what cannot be undone - saying its prices go with it and placed orders keep
 * its name and links. A refusal (switched on meanwhile) is said inside, and the dialog stays.
 */
export function DeleteCarrierDialog({
    carrier,
    name,
    storeCode,
    fromPage = false,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    carrier: CarrierData;
    name: string;
    storeCode: string;
    fromPage?: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);
    const [refused, setRefused] = useState(false);

    return (
        <DestructiveActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${C}.delete_title`)}
            confirmLabel={t(`${C}.delete_title`)}
            description={t(`${C}.delete_body`, { name })}
            irreversibleDescription={t(`${C}.delete_irreversible`, { name })}
            verificationPhrase={name}
            verificationLabel={t(`${C}.verification_label`)}
            loading={busy}
            error={refused ? errors.form : undefined}
            returnFocusTo={returnFocusTo}
            onConfirm={() =>
                router.post(
                    `${LIST}/${carrier.id}/delete`,
                    { store: storeCode, from_page: fromPage },
                    {
                        preserveScroll: true,
                        onStart: () => {
                            setBusy(true);
                            setRefused(false);
                        },
                        onFinish: () => setBusy(false),
                        onError: () => setRefused(true),
                        onSuccess: () => onOpenChange(false),
                    },
                )
            }
        />
    );
}
