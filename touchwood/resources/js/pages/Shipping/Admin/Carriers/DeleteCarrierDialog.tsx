import { useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { useTranslator } from '@/lib/t';
import type { CarrierData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { C, LIST } from './parts';

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
