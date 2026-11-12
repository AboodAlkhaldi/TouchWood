import { PhoneCards } from '@/components/PhoneCards';
import { Time } from '@/components/Time';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import type { HistoryRowData } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';
import { SignedPoints, Unknown } from './parts';

/*
| The staff history (L2), the panel's alone: apart from parts.tsx, which the shop's pages share, so they never load the
| phone cards' code (JavaScriptBudgetTest).
*/

/** The staff history (L2): date, what, order, points, by whom, why; on a phone each line a card (round 3 #15). */
export function StaffHistory({ rows }: { rows: HistoryRowData[] }) {
    const t = useTranslator();

    return (
        <>
            <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                <Table>
                    <TableCaption className="sr-only">{t('loyalty::admin_points.history')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('loyalty::admin_points.columns.date')}</TableHead>
                            <TableHead>{t('loyalty::admin_points.columns.what')}</TableHead>
                            <TableHead>{t('loyalty::admin_points.columns.order')}</TableHead>
                            <TableHead className="text-end">{t('loyalty::admin_points.columns.points')}</TableHead>
                            <TableHead>{t('loyalty::admin_points.columns.by')}</TableHead>
                            <TableHead>{t('loyalty::admin_points.columns.reason')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id} data-test={`history-${row.id}`}>
                                <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                                    <Time value={row.occurredAt} />
                                </TableCell>
                                <TableCell>{t(`loyalty::admin_points.kind.${row.kind}`)}</TableCell>
                                <TableCell>
                                    {row.orderNumber === null ? (
                                        <Unknown />
                                    ) : (
                                        <bdi dir="ltr" className="tw-figure">
                                            {row.orderNumber}
                                        </bdi>
                                    )}
                                </TableCell>
                                <TableCell className="text-end">
                                    <SignedPoints value={row.points} />
                                </TableCell>
                                <TableCell>{row.staffName === null ? <Unknown /> : <bdi>{row.staffName}</bdi>}</TableCell>
                                <TableCell className="max-w-80 text-copy-13 whitespace-normal text-ink-muted">
                                    {row.reason === null ? <Unknown /> : <bdi className="break-words">{row.reason}</bdi>}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <PhoneCards
                test="history"
                cards={rows.map((row) => ({
                    id: String(row.id),
                    title: t(`loyalty::admin_points.kind.${row.kind}`),
                    lines: [
                        <span key="d">
                            <Time value={row.occurredAt} focusable={false} />
                            {row.orderNumber === null ? null : (
                                <>
                                    {' · '}
                                    <bdi dir="ltr" className="tw-figure">
                                        {row.orderNumber}
                                    </bdi>
                                </>
                            )}
                        </span>,
                        <span key="p">
                            {`${t('loyalty::admin_points.columns.points')}: `}
                            <SignedPoints value={row.points} />
                            {row.staffName === null ? null : (
                                <>
                                    {' · '}
                                    <bdi>{row.staffName}</bdi>
                                </>
                            )}
                        </span>,
                        ...(row.reason === null ? [] : [<bdi key="r">{row.reason}</bdi>]),
                    ],
                }))}
            />
        </>
    );
}
