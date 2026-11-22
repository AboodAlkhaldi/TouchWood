import { router } from '@inertiajs/react';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import { P } from './prices';

/** The prices changed under the dialog (R10): what was ticked was against a list that is gone, so Reload closes it. */
export function StaleNote({ onReload }: { onReload: () => void }) {
    const t = useTranslator();

    return (
        <Note
            variant="warning"
            action={
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        onReload();
                        router.reload();
                    }}
                    data-test="reload-prices"
                >
                    {t(`${P}.reload`)}
                </Button>
            }
            data-test="prices-stale"
        >
            {t(`${P}.stale`)}
        </Note>
    );
}
