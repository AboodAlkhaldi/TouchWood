import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import { plural, useLocale } from '@/components/promotions/parts';
import type { SegmentRowData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/**
 * A segment's rule in words (promotions.md §4.1 M3): "No order in 6 months", "At least 3 orders in 3
 * months", "Never ordered" - each count in the page's plural form; picked by hand has none.
 */
export function useRuleWords(): (segment: SegmentRowData) => string | null {
    const t = useTranslator();
    const locale = useLocale();

    return (segment) => {
        if (segment.rule === null) {
            return null;
        }

        return t(`promotions::admin_segments.rule.${segment.rule}`, {
            months: isolate(plural(t, locale, 'promotions::admin_segments.count.months', segment.ruleMonths ?? 0)),
            orders: isolate(plural(t, locale, 'promotions::admin_segments.count.orders', segment.ruleOrders ?? 0)),
        });
    };
}
