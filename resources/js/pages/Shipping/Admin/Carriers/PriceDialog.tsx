import { useEffect, useMemo, useState, type RefObject } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { minorOf, typedOf } from '@/pages/Pricing/Admin/checks';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { PickPlaceData, PriceGroupData, PricePlaceData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { LIST } from './parts';
import { AmountBox, amountProblem, P, PlaceChip, PRICE_MAX } from './prices';

/*
| Add Price / Edit Price (shipping.md §4.1 part 2; the owner, 2026-10-30): a module of its own, loaded when it
| is first opened. The amount in the store's currency (R15) - on Edit, an amount another price has says it
| joins it (R7) - then the places, one level at a time with a trail (R3): the country's regions, a region's
| cities, a city's districts, each level read when it is opened and filtered as typed (R4). Ticking a region
| or a city prices it whole. A place in another price shows its amount and moves here only when asked (R5); a
| place under one already priced says what covers it. Select all asks the server for every place not yet
| priced (R6 (a)); "Select all, moving the others here" brings every region and every other price's places
| into this one (R6 (c)). Every save sends the version the page read; a later one is refused, and the
| dialog offers Reload (R10).
*/

type Level = { kind: 'country' } | { kind: 'region'; id: string; name: string } | { kind: 'city'; id: string; name: string; region: { id: string; name: string } };

type List = PickPlaceData[] | 'loading' | 'failed';

type Owner = { group: string; amount: MoneyData; place: PricePlaceData };

export function PriceDialog({
    carrierId,
    carrierName,
    storeCode,
    countryName,
    group,
    groups,
    version,
    currency,
    decimals,
    open,
    onOpenChange,
    onOpenGroup,
    returnFocusTo,
}: {
    carrierId: string;
    carrierName: string;
    storeCode: string;
    countryName: string;
    group: PriceGroupData | null;
    groups: PriceGroupData[];
    version: string;
    currency: string;
    decimals: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onOpenGroup: (id: string) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const { errors, locale } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(group === null ? '' : typedOf(group.amount.minor, decimals));
    const [picked, setPicked] = useState(() => new Map((group?.places ?? []).map((place) => [place.id, place])));
    const [moves, setMoves] = useState<Set<string>>(() => new Set());
    const [allUnpriced, setAllUnpriced] = useState(false);
    const [asking, setAsking] = useState<string | null>(null);
    const [movingAll, setMovingAll] = useState(false);
    const [level, setLevel] = useState<Level>({ kind: 'country' });
    const [lists, setLists] = useState<Record<string, List>>({});
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [refused, setRefused] = useState(false);

    // Every place of this carrier's other prices, by id: what "At :amount" and Move here read (R5).
    const owners = useMemo(() => {
        const map = new Map<string, Owner>();

        for (const each of groups) {
            if (each.id !== group?.id) {
                for (const place of each.places) {
                    map.set(place.id, { group: each.id, amount: each.amount, place });
                }
            }
        }

        return map;
    }, [groups, group?.id]);
    const others = groups.filter((each) => each.id !== group?.id);

    const key = level.kind === 'country' ? 'country' : `${level.kind}:${level.id}`;
    const list = lists[key];

    useEffect(() => {
        if (!open || lists[key] !== undefined) {
            return;
        }

        setLists((all) => ({ ...all, [key]: 'loading' }));
        const query = new URLSearchParams({ store: storeCode, ...(level.kind === 'region' ? { region: level.id } : {}), ...(level.kind === 'city' ? { city: level.id } : {}) });
        fetch(`${LIST}/${carrierId}/places?${query.toString()}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<PickPlaceData[]>) : Promise.reject(new Error(String(response.status)))))
            .then((places) => setLists((all) => ({ ...all, [key]: places })))
            .catch(() => setLists((all) => ({ ...all, [key]: 'failed' })));
    }, [open, key, lists]);

    const problem = amountProblem(typed, decimals, PRICE_MAX, t);
    const minor = minorOf(typed, decimals);
    const joins = group !== null && minor !== null && minor !== group.amount.minor ? others.find((each) => each.amount.minor === minor) : undefined;
    const ownAmount = minor === null ? t(`${P}.dialog.places`) : `${typedOf(minor, decimals)} ${currency}`;

    /** A row of the open level as a place in this price: its kind and what it is under come from the level. */
    function asPlace(row: PickPlaceData): PricePlaceData {
        return {
            id: row.id,
            kind: level.kind === 'country' ? 'REGION' : level.kind === 'region' ? 'CITY' : 'DISTRICT',
            name: row.name,
            under: level.kind === 'country' ? null : level.name,
            retired: false,
        };
    }

    function tick(place: PricePlaceData, move = false) {
        setPicked((all) => new Map(all).set(place.id, place));

        if (move) {
            setMoves((all) => new Set(all).add(place.id));
        }

        setAsking(null);
    }

    function untick(id: string) {
        setPicked((all) => {
            const next = new Map(all);
            next.delete(id);

            return next;
        });
        setMoves((all) => {
            const next = new Set(all);
            next.delete(id);

            return next;
        });
    }

    /** What covers a place of the open level, from above: its region, or for a district its city first (rule 5). */
    function coveredBy(): { name: string; amount: string } | null {
        const above = level.kind === 'city' ? [level.id, level.region.id] : level.kind === 'region' ? [level.id] : [];

        for (const id of above) {
            if (picked.has(id)) {
                return { name: picked.get(id)?.name ?? '', amount: ownAmount };
            }

            const owner = owners.get(id);

            if (owner !== undefined && !moves.has(id)) {
                return { name: owner.place.name, amount: owner.amount.text };
            }
        }

        return null;
    }

    /** R6 (c): every region of the country priced whole here, and every other price's places moved here. */
    function moveEverything() {
        const regions = lists.country;
        const next = new Map(picked);
        const moved = new Set(moves);

        if (Array.isArray(regions)) {
            for (const region of regions) {
                next.set(region.id, { id: region.id, kind: 'REGION', name: region.name, under: null, retired: false });

                if (owners.has(region.id)) {
                    moved.add(region.id);
                }
            }
        }

        for (const [id, owner] of owners) {
            next.set(id, owner.place);
            moved.add(id);
        }

        setPicked(next);
        setMoves(moved);
        setMovingAll(false);
    }

    function submit() {
        router.post(
            `${LIST}/${carrierId}/prices`,
            {
                store: storeCode,
                group: group?.id ?? '',
                amount: typed,
                version,
                places: [...picked.keys()],
                moves: [...moves].filter((id) => picked.has(id)),
                all_unpriced: allUnpriced,
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setRefused(false);
                },
                onFinish: () => setBusy(false),
                onError: () => setRefused(true),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    const covered = coveredBy();
    const rows = Array.isArray(list) ? list.filter((row) => filter.trim() === '' || `${row.name} ${row.otherName}`.toLocaleLowerCase().includes(filter.trim().toLocaleLowerCase())) : [];
    const levelPlace: PricePlaceData | null = level.kind === 'country' ? null : { id: level.id, kind: level.kind === 'region' ? 'REGION' : 'CITY', name: level.name, under: level.kind === 'city' ? level.region.name : null, retired: false };
    const nothing = picked.size === 0 && !allUnpriced;

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={group === null ? t(`${P}.add`) : t(`${P}.edit_title`)}
            description={group === null ? t(`${P}.dialog.add_body`) : t(`${P}.dialog.edit_body`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={problem ?? (typed.trim() === '' ? t(`${P}.dialog.amount`) : nothing ? t(`${P}.dialog.none`) : undefined)} onClick={submit} data-test="confirm-price">
                    {t(`${P}.dialog.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {refused && errors.stale ? (
                    <Note variant="warning" action={<Button type="button" variant="outline" size="sm" onClick={() => router.reload()} data-test="reload-prices">{t(`${P}.reload`)}</Button>}>
                        {t(`${P}.stale`)}
                    </Note>
                ) : null}
                {refused && errors.existing ? (
                    <Button type="button" variant="link" className="justify-self-start px-0" onClick={() => onOpenGroup(errors.existing)} data-test="open-existing-price">
                        {t(`${P}.dialog.open_existing`, { amount: groups.find((each) => each.id === errors.existing)?.amount.text ?? '' })}
                    </Button>
                ) : null}

                <AmountBox
                    id="price-amount"
                    label={t(`${P}.dialog.amount`)}
                    currency={currency}
                    value={typed}
                    onChange={setTyped}
                    error={problem ?? errors.amount}
                    helper={joins !== undefined ? <span data-test="price-joins">{t(`${P}.dialog.joins`, { amount: joins.amount.text })}</span> : t(`${P}.dialog.amount_hint`, { currency })}
                />

                <section aria-labelledby="price-places-title" className="grid gap-3">
                    <h3 id="price-places-title" className="text-label-14 text-ink">
                        {t(`${P}.dialog.places`)}
                    </h3>

                    {/* The trail: the country, the region, the city - each a way back (R3). */}
                    <nav aria-label={t(`${P}.dialog.places`)} className="flex flex-wrap items-center gap-1 text-copy-14" data-test="picker-trail">
                        <Button type="button" variant="link" size="sm" className="px-0" onClick={() => setLevel({ kind: 'country' })} aria-current={level.kind === 'country' ? 'page' : undefined}>
                            {countryName}
                        </Button>
                        {level.kind !== 'country' ? (
                            <>
                                <ChevronRight className="size-3.5 rtl:rotate-180" aria-hidden="true" />
                                <Button type="button" variant="link" size="sm" className="px-0" onClick={() => setLevel(level.kind === 'city' ? { kind: 'region', ...level.region } : level)} aria-current={level.kind === 'region' ? 'page' : undefined}>
                                    {level.kind === 'city' ? level.region.name : level.name}
                                </Button>
                            </>
                        ) : null}
                        {level.kind === 'city' ? (
                            <>
                                <ChevronRight className="size-3.5 rtl:rotate-180" aria-hidden="true" />
                                <span aria-current="page">{level.name}</span>
                            </>
                        ) : null}
                    </nav>

                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            value={filter}
                            onChange={(event) => setFilter(event.target.value)}
                            placeholder={t(`${P}.picker.filter`)}
                            aria-label={t(`${P}.picker.filter_label`)}
                            className="max-w-56"
                            data-test="picker-filter"
                        />
                        {level.kind === 'country' ? (
                            <>
                                <Button type="button" variant={allUnpriced ? 'secondary' : 'outline'} size="sm" aria-pressed={allUnpriced} title={t(`${P}.picker.select_all_hint`)} onClick={() => setAllUnpriced((on) => !on)} data-test="select-all-unpriced">
                                    {t(`${P}.picker.select_all`)}
                                </Button>
                                <Button type="button" variant="outline" size="sm" disabled={!Array.isArray(lists.country)} onClick={() => setMovingAll(true)} data-test="select-all-move">
                                    {t(`${P}.picker.move_all`)}
                                </Button>
                            </>
                        ) : levelPlace !== null ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={picked.has(levelPlace.id)}
                                onClick={() => (owners.has(levelPlace.id) ? setAsking(levelPlace.id) : tick(levelPlace))}
                                data-test="select-level"
                            >
                                {t(`${P}.picker.select_level`, { name: levelPlace.name })}
                            </Button>
                        ) : null}
                    </div>
                    {levelPlace !== null && asking === levelPlace.id ? <MoveHere owner={owners.get(levelPlace.id)} onMove={() => tick(levelPlace, true)} /> : null}

                    <div className="max-h-72 overflow-y-auto rounded-md border border-line" data-test="picker-list">
                        {list === undefined || list === 'loading' ? (
                            <p className="flex items-center gap-2 p-3 text-copy-14 text-ink-muted" role="status">
                                <Spinner aria-hidden="true" />
                                {t(`${P}.picker.loading`)}
                            </p>
                        ) : list === 'failed' ? (
                            <div className="flex flex-wrap items-center gap-2 p-3">
                                <span className="text-copy-14 text-bad">{t(`${P}.picker.failed`)}</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setLists((all) => {
                                            const next = { ...all };
                                            delete next[key];

                                            return next;
                                        })
                                    }
                                >
                                    {t(`${P}.picker.try_again`)}
                                </Button>
                            </div>
                        ) : list.length === 0 ? (
                            <p className="p-3 text-copy-14 text-ink-muted">{t(`${P}.picker.empty_level`)}</p>
                        ) : rows.length === 0 ? (
                            <p className="p-3 text-copy-14 text-ink-muted">{t(`${P}.picker.no_match`, { query: filter.trim() })}</p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {rows.map((row) => {
                                    const place = asPlace(row);
                                    const owner = owners.get(row.id);
                                    const isPicked = picked.has(row.id);
                                    const id = `pick-${row.id}`;

                                    return (
                                        <li key={row.id} className="grid gap-1 px-3 py-2" data-test={`pick-row-${row.id}`}>
                                            <div className="flex items-center gap-3">
                                                <Checkbox
                                                    id={id}
                                                    checked={isPicked}
                                                    onCheckedChange={(on) => (on !== true ? untick(row.id) : owner !== undefined ? setAsking(row.id) : tick(place))}
                                                    data-test={`pick-${row.id}`}
                                                />
                                                <label htmlFor={id} className="grid min-w-0 flex-1 gap-0.5">
                                                    <span className="truncate text-copy-14 text-ink">{row.name}</span>
                                                    <span className="truncate text-copy-12 text-ink-muted">
                                                        {[
                                                            row.otherName !== row.name ? row.otherName : null,
                                                            isPicked && moves.has(row.id) && owner !== undefined ? t(`${P}.picker.moving`, { amount: owner.amount.text }) : null,
                                                            !isPicked && owner !== undefined ? t(`${P}.picker.at`, { amount: owner.amount.text }) : null,
                                                            !isPicked && owner === undefined && covered !== null ? t(`${P}.picker.covered`, { place: covered.name, amount: covered.amount }) : null,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                </label>
                                                {level.kind !== 'city' ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={t(`${P}.picker.open`, { name: row.name })}
                                                        onClick={() => {
                                                            setFilter('');
                                                            setLevel(level.kind === 'country' ? { kind: 'region', id: row.id, name: row.name } : { kind: 'city', id: row.id, name: row.name, region: { id: level.id, name: level.name } });
                                                        }}
                                                        data-test={`open-${row.id}`}
                                                    >
                                                        <ChevronRight className="rtl:rotate-180" aria-hidden="true" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                            {asking === row.id ? <MoveHere owner={owner} onMove={() => tick(place, true)} /> : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>

                    <div className="grid gap-2" data-test="picked">
                        <p className="text-copy-13 text-ink-muted" data-test="picked-count">
                            {t(`${P}.dialog.count`, { count: figure(locale, picked.size) })}
                            {allUnpriced ? ` · ${t(`${P}.dialog.all_unpriced`)}` : ''}
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {[...picked.values()].map((place) => (
                                <PlaceChip key={place.id} place={place} onRemove={() => untick(place.id)} />
                            ))}
                        </div>
                        {errors.places ? <FieldError className="text-copy-13">{errors.places}</FieldError> : null}
                    </div>
                </section>
            </div>

            <PanelDialog
                open={movingAll}
                onOpenChange={setMovingAll}
                title={t(`${P}.picker.move_all_title`)}
                description={t(`${P}.picker.move_all_body`, { count: figure(locale, others.length), carrier: carrierName })}
                busy={false}
                confirm={
                    <ActionButton onClick={moveEverything} data-test="confirm-move-all">
                        {t(`${P}.picker.move_all_confirm`)}
                    </ActionButton>
                }
            >
                {null}
            </PanelDialog>
        </PanelDialog>
    );
}

/** "Move here?" under a place that is in another price (R5): moved only when asked. */
function MoveHere({ owner, onMove }: { owner: Owner | undefined; onMove: () => void }) {
    const t = useTranslator();

    return (
        <div className="flex flex-wrap items-center gap-2 ps-7 text-copy-13" data-test="move-here">
            <span className="text-ink-muted">
                {owner !== undefined ? `${t(`${P}.picker.at`, { amount: owner.amount.text })} · ` : ''}
                {t(`${P}.picker.move_here`)}
            </span>
            <Button type="button" variant="outline" size="sm" onClick={onMove} data-test="confirm-move-here">
                {t(`${P}.picker.move_confirm`)}
            </Button>
        </div>
    );
}
