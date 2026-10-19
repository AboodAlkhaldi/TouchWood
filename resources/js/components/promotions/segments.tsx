import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLocale } from '@/components/promotions/parts';
import type { SegmentRowData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/** A segment's rule in words (promotions.md §4.1 M3): "No order in 6 months", "Never ordered"; picked by hand has none. */
export function useRuleWords(): (segment: SegmentRowData) => string | null {
    const t = useTranslator();
    const locale = useLocale();

    return (segment) => {
        if (segment.rule === null) {
            return null;
        }

        return t(`promotions::admin_segments.rule.${segment.rule}`, {
            months: figure(locale, segment.ruleMonths ?? 0),
            orders: figure(locale, segment.ruleOrders ?? 0),
        });
    };
}
