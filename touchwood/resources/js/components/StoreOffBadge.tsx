import { Badge } from '@/components/ui/badge';
import { useTranslator } from '@/lib/t';

/**
 * The Off mark beside a store that is switched off (platform.md §1.6; access.md amendment 58): its
 * staff, customers and addresses are kept and shown, marked, so it can reopen as it was.
 */
export function StoreOffBadge({ className }: { className?: string }) {
    const t = useTranslator();

    return (
        <Badge variant="outline" className={className} data-test="store-off">
            {t('admin.store.off')}
        </Badge>
    );
}
