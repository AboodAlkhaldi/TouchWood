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
    /** The first day that can be chosen, and the month first shown when none is. Left out: no first day. */
    earliest?: Date;
    /** The last day that can be chosen. Left out: no last day. */
    latest?: Date;
    /** The store's today, marked as today whatever the device's own date. */
    today: Date;
    onSelect: (day: Date | undefined) => void;
    arabic: boolean;
};

export default function DateCalendar({ selected, earliest, latest, today, onSelect, arabic }: Props) {
    return (
        <Calendar
            mode="single"
            selected={selected}
            defaultMonth={selected ?? earliest ?? latest}
            startMonth={earliest}
            endMonth={latest}
            onSelect={onSelect}
            disabled={[...(earliest === undefined ? [] : [{ before: earliest }]), ...(latest === undefined ? [] : [{ after: latest }])]}
            today={today}
            locale={arabic ? dayPickerArabic : undefined}
            dir={arabic ? 'rtl' : 'ltr'}
            numerals="latn"
        />
    );
}
