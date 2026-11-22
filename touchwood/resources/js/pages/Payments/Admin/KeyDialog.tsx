import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { PasswordInput } from '@/components/PasswordInput';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { G } from './Gateway';

/*
| Add or Change Key… (payments.md §1.2, §4.2 Q16): a mode's key and notice secret, masked with a show button while
| typing and never shown after saving, and the expiry date chosen in MyFatoorah's portal, which no call reads back.
| Saving tests the key first; a refusal is said here, and nothing is saved.
*/

export function KeyDialog({ mode, storeCode, onClose }: { mode: 'LIVE' | 'TEST'; storeCode: string; onClose: () => void }) {
    const t = useTranslator();
    const [key, setKey] = useState('');
    const [secret, setSecret] = useState('');
    const [expires, setExpires] = useState('');
    const [busy, setBusy] = useState(false);
    const today = new Date().toISOString().slice(0, 10);
    const checks = useChecks(
        [
            { id: 'gateway-key', label: t(`${G}.dialog.key`), value: key, rules: { required: true } },
            { id: 'gateway-secret', label: t(`${G}.dialog.secret`), value: secret, rules: { required: true } },
            { id: 'gateway-expires', label: t(`${G}.dialog.expires`), value: expires, rules: { required: true } },
        ],
        true,
    );

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t(`${G}.dialog.title`, { mode: t(`${G}.mode.${mode}`) })}
            description={t(`${G}.dialog.body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={checks.reason}
                    onClick={() =>
                        checks.submit(() =>
                            // Never kept by Inertia's page state: the form's values are this dialog's alone.
                            router.post('/admin/payments/gateway/keys', { store: storeCode, mode, key: key.trim(), secret: secret.trim(), expires_on: expires }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose }),
                        )
                    }
                    data-test="key-save"
                >
                    {t(`${G}.dialog.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <PasswordInput id="gateway-key" dir="ltr" autoComplete="new-password" autoCapitalize="off" autoCorrect="off" spellCheck={false} data-1p-ignore data-lpignore="true" label={t(`${G}.dialog.key`)} value={key} check={checks.box('gateway-key')} onChange={(event) => setKey(event.target.value)} data-test="key-key" />
                <PasswordInput id="gateway-secret" dir="ltr" autoComplete="new-password" autoCapitalize="off" autoCorrect="off" spellCheck={false} data-1p-ignore data-lpignore="true" label={t(`${G}.dialog.secret`)} value={secret} check={checks.box('gateway-secret')} onChange={(event) => setSecret(event.target.value)} data-test="key-secret" />
                <TextField id="gateway-expires" type="date" dir="ltr" min={today} label={t(`${G}.dialog.expires`)} helper={t(`${G}.dialog.expires_hint`)} value={expires} check={checks.box('gateway-expires')} onChange={(event) => setExpires(event.target.value)} data-test="key-expires" />
            </div>
        </PanelDialog>
    );
}
