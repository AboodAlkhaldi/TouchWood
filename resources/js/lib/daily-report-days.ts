import { wallClock } from '@/lib/moments';

/*
| The last past day a daily report can be made for (ops.md §1.11, §4.15 O9): before today where the report is read and,
| for every store's, in each store it covers, because a store west of the base store may still be on the day asked.
| The same rule the server keeps (`DailyReportMaker`), so the calendar never offers a day it refuses.
*/

function dayBefore(day: string): string {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(Date.UTC(year as number, (month as number) - 1, (date as number) - 1)).toISOString().slice(0, 10);
}

/**
 * @param store  the store's id; `''` for every store's
 * @param zones  each store's time zone by its id, and `''` the base store's
 */
export function lastPastDay(store: string, zones: Record<string, string>, now: number = Date.now()): string {
    const covered = store === '' ? Object.values(zones) : [zones[store] ?? 'UTC'];
    // Someone with no store on offer has no zones: the page shows no form, and asks this all the same.
    const earliest = (covered.length === 0 ? ['UTC'] : covered).map((zone) => wallClock(zone, now).date).sort()[0] as string;

    return dayBefore(earliest);
}
