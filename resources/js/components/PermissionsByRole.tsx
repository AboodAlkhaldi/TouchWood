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
import { SearchField } from '@/components/SearchField';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';

/*
| "Permissions by role" (frontend.md §3.4, D1): every action down the side, the roles across the
| top (owner, 2026-09-24).
|
| **A plain table** (owner, §1.11 #5, 2026-10-02): the business area is its own column, named once
| at the head of its rows (a row-group header, so a screen reader still hears the area with every
| action under it), and nothing sticks - replacing the sticky area bands of 2026-09-24. The mark in
| each cell is kept as it was (§1.11 #6): a tick in a green square for yes, a minus for no.
|
| Built on TanStack Table, as the owner asked (2026-09-24), for the two things a person comparing
| roles needs once there are more than a handful: the actions can be narrowed by area or name, and
| roles can be hidden. shadcn's parts: its Table, its search field (`input-group`), and its
| `data-table` "Columns" menu - a DropdownMenu of DropdownMenuCheckboxItems, kept open while
| ticking, as Geist's Multi Select stays open. When the filter leaves nothing, the table gives way
| to an Empty State that quotes what was typed and offers to clear it.
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
            reach: Object.fromEntries(roles.map((role) => [role.id, (permissionsByRole[role.id] ?? []).includes(permission.name)])),
        }));
    }, [groups, permissions, permissionsByRole, roles]);

    const columns = useMemo<ColumnDef<Features, AreaRow>[]>(
        () => [
            {
                id: 'action',
                // The area's name is searched too, so typing "media" finds every media action
                // rather than only the ones with "media" in their own name.
                accessorFn: (row: AreaRow) => `${row.label} ${row.groupLabel}`,
                header: t('access::roles.action'),
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
                        // In the same green the system says "active" in, rather than a bare tick
                        // floating in a cell (owner, 2026-09-24): a wall of marks reads as a pattern
                        // when each one has a shape, and as noise when they do not.
                        row.original.reach[role.id] === true ? (
                            <span className="grid size-6 place-items-center rounded-sm bg-good-soft text-good">
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
        state: { columnVisibility: hidden, columnFilters: filter === '' ? [] : [{ id: 'action', value: filter }] },
        onColumnVisibilityChange: setHidden,
    });

    const hideable = table.getAllColumns().filter((column) => column.getCanHide());
    const shown = hideable.filter((column) => column.getIsVisible()).length;
    const rows = table.getRowModel().rows;

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <SearchField
                    id="filter-areas"
                    className="w-64"
                    label={t('access::roles.filter_areas')}
                    placeholder={t('access::roles.filter_areas')}
                    value={filter}
                    onValueChange={setFilter}
                />

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" data-test="columns">
                            <Columns3 aria-hidden="true" />
                            <span className="tw-figure">{t('access::roles.columns', { shown, total: hideable.length })}</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="max-h-80 overflow-y-auto">
                        <DropdownMenuLabel>{t('access::roles.columns_hint')}</DropdownMenuLabel>
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

            {rows.length === 0 ? (
                <Empty className="material-base" aria-live="polite">
                    <EmptyHeader>
                        <EmptyTitle>{t('access::roles.no_areas_title')}</EmptyTitle>
                        <EmptyDescription>{t('access::roles.no_areas', { query: filter })}</EmptyDescription>
                    </EmptyHeader>
                    <EmptyContent>
                        <Button variant="outline" onClick={() => setFilter('')}>
                            {t('access::roles.clear_filter')}
                        </Button>
                    </EmptyContent>
                </Empty>
            ) : (
                <div className="material-base overflow-hidden">
                    <Table>
                        <TableHeader className="bg-surface-sunken">
                            {table.getHeaderGroups().map((headerGroup) => (
                                <TableRow key={headerGroup.id}>
                                    <TableHead className="text-label-13 text-ink-muted">{t('access::roles.area')}</TableHead>
                                    {headerGroup.headers.map((header) => (
                                        <TableHead key={header.id} className="text-label-13 text-ink-muted">
                                            {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            ))}
                        </TableHeader>

                        <TableBody>
                            {rows.map((row, index) => {
                                // The area is named once, at the head of its rows, worked out from the
                                // rows that survived the filter - so it never stands over nothing.
                                const first = rows[index - 1]?.original.group !== row.original.group;
                                let span = 0;

                                if (first) {
                                    for (let next = index; next < rows.length && rows[next]?.original.group === row.original.group; next++) {
                                        span++;
                                    }
                                }

                                return (
                                    <Fragment key={row.id}>
                                        <TableRow className={first && index > 0 ? 'border-t-2 border-line' : undefined}>
                                            {first ? (
                                                <TableHead scope="rowgroup" rowSpan={span} className="align-top py-2 text-label-13 font-medium text-ink">
                                                    {row.original.groupLabel}
                                                </TableHead>
                                            ) : null}
                                            {row.getVisibleCells().map((cell) => (
                                                <TableCell key={cell.id} className={cell.column.id === 'action' ? 'whitespace-nowrap' : undefined}>
                                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    </Fragment>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}
