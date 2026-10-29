import { type RefObject, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ShipToChoice, ShipToRow, StoreRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| A store's ship-to countries on its card (platform.md §4.3, E1 addition; the owner, 2026-10-29, Q2,
| Q3, Q14, Q15). Everyone who may open the Stores page reads them; Add and Remove are a Super Admin's
| (`choices` is null for anyone else). The store's own country comes first, marked Own, and is never
| removed. Add Country lists every address country in use: one another store ships to, or one with no
| place list, stays in sight, out of reach, with its reason. Removing says what follows for the
| customers there; Shipping refuses it while the store has a carrier there, in its own words.
*/

const S = 'platform::admin_stores.ships_to';

export function ShipsTo({ store, choices }: { store: StoreRow; choices: ShipToChoice[] | null }) {
    const t = useTranslator();
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<ShipToRow | null>(null);
    const addButton = useRef<HTMLButtonElement>(null);
    const opener = useRef<HTMLElement | null>(null);
    const may = choices !== null;

    return (
        <div className="grid gap-3 border-t border-line px-5 py-4" data-test={`ships-to-${store.code}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-label-14 text-ink">{t(`${S}.title`)}</h3>
                {may ? (
                    <Button ref={addButton} type="button" variant="outline" size="sm" onClick={() => setAdding(true)} data-test={`add-ship-to-${store.code}`}>
                        {t(`${S}.add`)}
                    </Button>
                ) : null}
            </div>
            <ul className="flex flex-wrap gap-2">
                {store.shipsTo.map((country) => (
                    <li key={country.code} className="flex items-center gap-1 rounded-md border border-line px-2 py-1 text-copy-14" data-test={`ship-to-${country.code}`}>
                        <bdi>{country.name}</bdi>
                        {country.own ? (
                            <Badge className={tone('gray-subtle')} title={t(`${S}.own_hint`)}>
                                {t(`${S}.own`)}
                            </Badge>
                        ) : may ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-6 px-1.5 text-copy-13"
                                onClick={(event) => {
                                    opener.current = event.currentTarget;
                                    setRemoving(country);
                                }}
                                aria-label={`${t(`${S}.remove`)}: ${country.name}`}
                                data-test={`remove-ship-to-${country.code}`}
                            >
                                {t(`${S}.remove`)}
                            </Button>
                        ) : null}
                    </li>
                ))}
            </ul>

            {may && adding ? <AddDialog store={store} choices={choices} onOpenChange={setAdding} returnFocusTo={addButton} /> : null}
            {removing !== null ? (
                <RemoveDialog
                    store={store}
                    country={removing}
                    onOpenChange={(open) => (open ? undefined : setRemoving(null))}
                    returnFocusTo={opener}
                    // Its item leaves the list: focus goes to Add Country.
                    onRemoved={() => (opener.current = addButton.current)}
                />
            ) : null}
        </div>
    );
}

function AddDialog({ store, choices, onOpenChange, returnFocusTo }: { store: StoreRow; choices: ShipToChoice[]; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [picked, setPicked] = useState('');
    const [busy, setBusy] = useState(false);
    // Those this store already ships to are on its card, not offered again.
    const offered = choices.filter((choice) => choice.store?.code !== store.code);
    const reasonOf = (choice: ShipToChoice): string | null =>
        choice.store !== null ? t(`${S}.shipped_elsewhere`, { store: choice.store.name }) : choice.hasPlaces ? null : t(`${S}.no_places`);
    const open = [...offered.filter((choice) => reasonOf(choice) === null), ...offered.filter((choice) => reasonOf(choice) !== null)];

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${S}.add_title`, { store: store.name })}
            description={t(`${S}.add_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={picked === '' ? t(`${S}.pick_first`) : undefined}
                    onClick={() =>
                        router.post(`/admin/stores/${store.code}/countries`, { country: picked }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-add-ship-to"
                >
                    {t(`${S}.add`)}
                </ActionButton>
            }
        >
            {open.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t(`${S}.none_left`)}</p>
            ) : (
                <Command className="rounded-md border border-line">
                    <CommandInput placeholder={t(`${S}.search`)} />
                    <CommandList className="max-h-64">
                        <CommandEmpty>{t(`${S}.no_match`)}</CommandEmpty>
                        {open.map((choice) => {
                            const reason = reasonOf(choice);

                            return (
                                <CommandItem
                                    key={choice.code}
                                    value={choice.code}
                                    keywords={[choice.name, choice.code]}
                                    disabled={reason !== null}
                                    data-checked={picked === choice.code || undefined}
                                    onSelect={() => setPicked(choice.code)}
                                    className="data-[checked=true]:bg-surface-sunken"
                                    data-test={`choice-${choice.code}`}
                                >
                                    <span className="grid gap-0.5">
                                        <span className={reason !== null ? 'opacity-60' : undefined}>
                                            <bdi>{choice.name}</bdi>
                                        </span>
                                        {reason !== null ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
                                    </span>
                                    {picked === choice.code ? <Check className="ms-auto size-4" aria-hidden /> : null}
                                </CommandItem>
                            );
                        })}
                    </CommandList>
                </Command>
            )}
            {/* A country with no place list: the way to its places, beside its reason (Q14). */}
            {offered
                .filter((choice) => choice.store === null && !choice.hasPlaces)
                .map((choice) => (
                    <Link key={choice.code} href={`/admin/places/${choice.code.toLowerCase()}`} className="text-copy-13 underline underline-offset-4" data-test={`places-of-${choice.code}`}>
                        {t(`${S}.places_link`, { country: choice.name })}
                    </Link>
                ))}
        </PanelDialog>
    );
}

function RemoveDialog({
    store,
    country,
    onOpenChange,
    returnFocusTo,
    onRemoved,
}: {
    store: StoreRow;
    country: ShipToRow;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: RefObject<HTMLElement | null>;
    onRemoved: () => void;
}) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    // Refused - the store still has carriers there (CountryHasCarriers): the refusal shows in the dialog,
    // and a link goes to where they are switched off and deleted (shipping.md §4.1 part 1, Q14).
    const [refused, setRefused] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${S}.remove_title`, { country: country.name })}
            description={t(`${S}.remove_body`, { country: country.name, store: store.name })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/stores/${store.code}/countries/${country.code.toLowerCase()}/remove`, {}, {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onError: () => setRefused(true),
                            onSuccess: () => {
                                onRemoved();
                                onOpenChange(false);
                            },
                        })
                    }
                    data-test="confirm-remove-ship-to"
                >
                    {t(`${S}.remove`)}
                </ActionButton>
            }
        >
            {refused ? (
                <Link href={`/admin/shipping/carriers?store=${encodeURIComponent(store.code)}`} className="text-label-14 text-ink underline underline-offset-4" data-test="open-carriers">
                    {t(`${S}.open_carriers`, { country: country.name })}
                </Link>
            ) : null}
        </PanelDialog>
    );
}
