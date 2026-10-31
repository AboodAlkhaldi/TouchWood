import { Badge } from '@/components/ui/badge';
import { useTranslator } from '@/lib/t';
import type { PackingSizeData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import { B } from './parts';

/** A size as the lists show it: its photo, product name, and its code with its values (B11, B12). */
export function SizeLine({ size, htmlFor }: { size: PackingSizeData; htmlFor?: string }) {
    const t = useTranslator();
    const Text = htmlFor !== undefined ? 'label' : 'div';

    return (
        <>
            {size.photo !== null ? <img src={size.photo} alt="" className="size-10 shrink-0 rounded-md border border-line object-cover" /> : <span className="size-10 shrink-0 rounded-md border border-line bg-surface-sunken" aria-hidden="true" />}
            <Text htmlFor={htmlFor} className="grid min-w-0 flex-1 gap-0.5">
                <span className="truncate text-copy-14 text-ink">{size.productName}</span>
                <span className="flex flex-wrap items-center gap-2 text-copy-12 text-ink-muted">
                    <bdi dir="ltr" className="tw-figure">
                        {size.code}
                    </bdi>
                    {size.values !== '' ? <span>{size.values}</span> : null}
                    {size.archived ? <Badge variant="secondary">{t(`${B}.hand.archived`)}</Badge> : null}
                </span>
            </Text>
        </>
    );
}
