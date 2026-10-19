import { ar as dayPickerArabic } from 'react-day-picker/locale/ar';
import { Calendar } from '@/components/ui/calendar';

/*
| The one-day calendar of DateTimeField, on its own so it loads only when the picker opens - shadcn's
| calendar and its day-picker would otherwise be most of a page's JavaScript (frontend.md §5), as
| DateRangeCalendar is for the range. Nothing before the first day that can be chosen; an Arabic page in
| Arabic with Latin digits (§1.8), said to day-picker outright.
*/

type Props = {
    selected: Date | undefined;
    /** The first day that can be chosen, and the month first shown when none is. */
    earliest: Date;
    /** The store's today, marked as today whatever the device's own date. */
    today: Date;
    onSelect: (day: Date | undefined) => void;
    arabic: boolean;
};

export default function DateCalendar({ selected, earliest, today, onSelect, arabic }: Props) {
    return (
        <Calendar
            mode="single"
            selected={selected}
            defaultMonth={selected ?? earliest}
            startMonth={earliest}
            onSelect={onSelect}
            disabled={{ before: earliest }}
            today={today}
            locale={arabic ? dayPickerArabic : undefined}
            dir={arabic ? 'rtl' : 'ltr'}
            numerals="latn"
        />
    );
}
