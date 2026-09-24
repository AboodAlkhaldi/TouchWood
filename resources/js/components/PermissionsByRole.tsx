import { Fragment, useMemo, useState } from 'react';
import {
    columnFilteringFeature,
    columnVisibilityFeature,
    createFilteredRowModel,
    filterFn_includesString,
    flexRender,
    tableFeatures,
    useTable,
    type ColumnDef,
} from '@tanstack/react-table';
import { Check, Columns3, Minus } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';

/*
| "Permissions by role" (frontend.md §3.4, D1): every action down the side, under the heading of
| its business area, and the roles across the top (owner, 2026-09-24).
|
| Built on TanStack Table, as the owner asked (2026-09-24), for the two things a plain table could
| not do once there are more than a handful of roles:
|
| - **It scrolls in both directions with the labels kept.** The area column is stuck to the start
|   edge and the header row to the top, so the tenth role across is still readable as a row about
|   "Store settings and tax" rather than as an anonymous column of dots.
| - **Roles can be filtered and hidden.** A person comparing two roles hides the other eight rather
|   than scrolling past them, and the area filter narrows the rows to the ones they care about.
|
| Only the features used are registered: anything not listed here is left out of the bundle, which
| is what TanStack's v9 feature list is for.
*/

const features = tableFeatures({
    columnFilteringFeature,
    columnVisibilityFeature,
    filteredRowModel: createFilteredRowModel(),
    filterFns: { includesString: filterFn_includesString },
});

type Features = typeof features;

export type ComparisonRole = {
    id: string;
    name: string;
};

export type ComparisonGroup = {
    key: string;
    label: string;
};

export type ComparisonPermission = {
    name: string;
    label: string;
    group: string;
};

/** One row: an action, and whether each role holds it. */
type AreaRow = {
    key: string;
    label: string;
    group: string;
    groupLabel: string;
    reach: Record<string, boolean>;
};

type Props = {
    roles: ComparisonRole[];
    groups: ComparisonGroup[];
    permissions: ComparisonPermission[];
    permissionsByRole: Record<string, string[]>;
};

export function PermissionsByRole({ roles, groups, permissions, permissionsByRole }: Props) {
    const t = useTranslator();
    const [hidden, setHidden] = useState<Record<string, boolean>>({});
    const [filter, setFilter] = useState('');

    const data = useMemo<AreaRow[]>(() => {
        const areaLabel = new Map(groups.map((group) => [group.key, group.label]));

        return permissions.map((permission) => ({
            key: permission.name,
            label: permission.label,
            group: permission.group,
            groupLabel: areaLabel.get(permission.group) ?? permission.group,
            reach: Object.fromEntries(
                roles.map((role) => [role.id, (permissionsByRole[role.id] ?? []).includes(permission.name)]),
            ),
        }));
    }, [groups, permissions, permissionsByRole, roles]);

    const columns = useMemo<ColumnDef<Features, AreaRow>[]>(
        () => [
            {
                id: 'area',
                // The area's name is searched too, so typing "media" finds every media action
                // rather than only the ones with "media" in their own name.
                accessorFn: (row: AreaRow) => `${row.label} ${row.groupLabel}`,
                header: t('access::roles.comparison'),
                filterFn: 'includesString',
                enableHiding: false,
                cell: ({ row }) => <span className="text-ink">{row.original.label}</span>,
            },
            ...roles.map(
                (role): ColumnDef<Features, AreaRow> => ({
                    id: role.id,
                    header: role.name,
                    enableColumnFilter: false,
                    cell: ({ row }) =>
                        // In the same green box the system says "active" in, rather than a bare
                        // tick floating in a cell (owner, 2026-09-24): a wall of marks reads as a
                        // pattern when each one has a shape, and as noise when they do not.
                        row.original.reach[role.id] === true ? (
                            <span className="grid size-6 place-items-center rounded-md bg-good-soft text-good">
                                <Check className="size-4" aria-label={t('access::roles.reaches')} />
                            </span>
                        ) : (
                            <span className="grid size-6 place-items-center text-ink-subtle">
                                <Minus className="size-4" aria-label={t('access::roles.does_not_reach')} />
                            </span>
                        ),
                }),
            ),
        ],
        [roles, t],
    );

    // The two generics are given rather than inferred: the column list is typed against this
    // table's own feature set, and without them TypeScript widens the table to "any features" and
    // the two no longer line up.
    const table = useTable<Features, AreaRow>({
        features,
        data,
        columns,
        state: { columnVisibility: hidden, columnFilters: filter === '' ? [] : [{ id: 'area', value: filter }] },
        onColumnVisibilityChange: setHidden,
    });

    const hideable = table.getAllColumns().filter((column) => column.getCanHide());
    const shown = hideable.filter((column) => column.getIsVisible()).length;

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-3">
                <Input
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder={t('access::roles.filter_areas')}
                    aria-label={t('access::roles.filter_areas')}
                    className="w-64"
                />

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" data-test="columns">
                            <Columns3 />
                            {t('access::roles.columns', { shown, total: hideable.length })}
                        </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="start" className="max-h-80 overflow-y-auto">
                        <DropdownMenuLabel>{t('access::roles.columns_hint')}</DropdownMenuLabel>
                        <DropdownMenuSeparator />

                        {hideable.map((column) => (
                            <DropdownMenuCheckboxItem
                                key={column.id}
                                checked={column.getIsVisible()}
                                // Kept open: hiding six of ten roles one at a time is the whole
                                // point, and a menu that shuts after each is six trips.
                                onSelect={(event) => event.preventDefault()}
                                onCheckedChange={(on) => column.toggleVisibility(on === true)}
                            >
                                {roles.find((role) => role.id === column.id)?.name ?? column.id}
                            </DropdownMenuCheckboxItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {/* Both directions, with the labels kept: the header row sticks to the top and the area
                column to the start edge - which is the left in English and the right in Arabic,
                because it is written as a logical offset. */}
            <div className="max-h-[32rem] overflow-auto rounded-lg border border-line bg-surface">
                <Table className="min-w-max">
                    <TableHeader className="sticky top-0 z-20 bg-surface">
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableHead
                                        key={header.id}
                                        className={
                                            header.column.id === 'area'
                                                ? 'sticky start-0 z-30 bg-surface text-xs font-semibold text-ink-muted uppercase'
                                                : 'text-xs font-semibold whitespace-nowrap text-ink-muted'
                                        }
                                    >
                                        {header.isPlaceholder
                                            ? null
                                            : flexRender(header.column.columnDef.header, header.getContext())}
                                    </TableHead>
                                ))}
                            </TableRow>
                        ))}
                    </TableHeader>

                    <TableBody>
                        {table.getRowModel().rows.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={columns.length} className="text-sm text-ink-muted">
                                    {t('access::roles.no_areas')}
                                </TableCell>
                            </TableRow>
                        ) : (
                            table.getRowModel().rows.map((row, index) => {
                                // A heading each time the area changes, worked out from the rows
                                // that survived the filter - so a heading never stands over
                                // nothing (owner, 2026-09-24).
                                const previous = table.getRowModel().rows[index - 1]?.original.group;

                                return (
                                    <Fragment key={row.id}>
                                        {/* A band, not a slightly greyer row: it was there before
                                            and could not be seen (owner, 2026-09-24). It carries
                                            the area's name at the start edge and stays legible
                                            while the table is scrolled sideways. */}
                                        {previous === row.original.group ? null : (
                                            <TableRow className="border-y border-brand-soft bg-brand-soft/60 hover:bg-brand-soft/60">
                                                <TableCell
                                                    colSpan={row.getVisibleCells().length}
                                                    className="py-2 text-xs font-semibold tracking-wide text-brand uppercase"
                                                >
                                                    {/* The name sticks, not the cell: a cell that
                                                        spans the whole table never leaves the
                                                        screen, so sticking it does nothing and the
                                                        name inside it scrolls away regardless
                                                        (owner, 2026-09-24). Held at the cell's own
                                                        padding, so it does not jump on the first
                                                        pixel of scrolling. */}
                                                    <span className="sticky start-2 inline-block">
                                                        {row.original.groupLabel}
                                                    </span>
                                                </TableCell>
                                            </TableRow>
                                        )}

                                        <TableRow className="transition-colors hover:bg-surface-sunken">
                                            {row.getVisibleCells().map((cell) => (
                                                <TableCell
                                                    key={cell.id}
                                                    className={
                                                        cell.column.id === 'area'
                                                            ? 'sticky start-0 z-10 bg-surface whitespace-nowrap'
                                                            : ''
                                                    }
                                                >
                                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    </Fragment>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
