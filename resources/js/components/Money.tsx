import { cn } from '@/lib/cn';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';

/*
| An amount as the server wrote it (pricing.md §4.3.1, §9.3 S4): `MoneyData.text` - the store's own
| decimals, Latin digits, then the currency's sign or its letters (frontend.md §1.8) - never formatted
| again here, so every screen shows a price the same way.
|
| Isolated left to right (`<bdi dir="ltr">`): inside an Arabic sentence or cell, the amount and its
| letters keep their order ("120.00 ر.س") instead of being laid out with the text around them; in Geist's
| figures (`tw-figure`), so digits line up in a column.
*/

export function Money({ value, className }: { value: MoneyData; className?: string }) {
    return (
        <bdi dir="ltr" className={cn('tw-figure', className)} data-minor={value.minor}>
            {value.text}
        </bdi>
    );
}
