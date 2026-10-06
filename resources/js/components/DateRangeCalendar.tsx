import type { DateRange } from 'react-day-picker';
import { ar as dayPickerArabic } from 'react-day-picker/locale/ar';
import { Calendar } from '@/components/ui/calendar';

/*
| The range calendar of DateRangeField, on its own so it loads only when the picker opens: shadcn's
| calendar and its day-picker are most of a page's JavaScript otherwise (frontend.md §5, the page
| budget; found when the audit log reached it, 2026-10-04). Two months, the second the latest, and
| nothing after today; an Arabic page in Arabic, with Latin digits as every page (§1.8, the owner
| 2026-10-06) - said to day-picker outright, so the rule does not rest on its locale's default.
*/

type Props = {
    selected: DateRange | undefined;
    defaultMonth: Date;
    /** The last month shown, and the last day that can be chosen. */
    today: Date;
    onSelect: (range: DateRange | undefined) => void;
    arabic: boolean;
};

export default function DateRangeCalendar({ selected, defaultMonth, today, onSelect, arabic }: Props) {
    return (
        <Calendar
            mode="range"
            selected={selected}
            defaultMonth={defaultMonth}
            endMonth={today}
            onSelect={onSelect}
            disabled={{ after: today }}
            // The UTC today, as the presets count it, not the browser's.
            today={today}
            numberOfMonths={2}
            locale={arabic ? dayPickerArabic : undefined}
            dir={arabic ? 'rtl' : 'ltr'}
            numerals="latn"
        />
    );
}
