import { type RefObject, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { CountryCombobox } from '@/components/CountryCombobox';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import type { PlaceCountryRow, PlacesPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { NarrowBar, Names, NoPlaces, NoneShown, P, RetiredBadge, ShipsFrom, plural, useLocale, useNarrowed } from './parts';

/*
| E8 - the countries a customer may choose in an address (platform.md §4.3; the owner, 2026-10-29).
|
| Each country with what a Super Admin needs before changing it (Q9): the store it ships from, an off
| one marked so, and its regions and cities in use - or "No place list yet", where customers type their
| places and no store may ship (CountryWithoutPlaces). A country a store ships to is not retired here:
| Retire stays in sight, out of reach, naming that store (Q8). A retired country comes back with
| Restore, never through Add Country, which offers only the countries not on the list (Q10).
|
| Reserved permissions: only a Super Admin reaches this page, and every handler asks again.
*/

type Dialog = { action: 'add' } | { action: 'retire'; country: PlaceCountryRow } | null;

export default function Index({ countries, addable }: PlacesPage) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const addButton = useRef<HTMLButtonElement>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const narrow = useNarrowed(countries);

    return (
        <AdminLayout
            title={t(`${P}.title`)}
            subtitle={t(`${P}.subtitle`)}
            action={
                <Button
                    ref={addButton}
                    type="button"
                    onClick={() => {
                        opener.current = addButton.current;
                        setDialog({ action: 'add' });
                    }}
                    data-test="add-country"
                >
                    {t(`${P}.add_country`)}
                </Button>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <NarrowBar id="countries" {...narrow} />
                {narrow.shown.length === 0 ? (
                    <NoneShown text={narrow.text} test="countries-none" />
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t(`${P}.title`)}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t(`${P}.column.country`)}</TableHead>
                                    <TableHead className="hidden sm:table-cell">{t(`${P}.column.ships_from`)}</TableHead>
                                    <TableHead className="hidden md:table-cell">{t(`${P}.column.places`)}</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">{t(`${P}.column.actions`)}</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {narrow.shown.map((country) => (
                                    <CountryRow
                                        key={country.code}
                                        country={country}
                                        retire={(trigger) => {
                                            opener.current = trigger;
                                            setDialog({ action: 'retire', country });
                                        }}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>

            {dialog?.action === 'add' ? <AddCountryDialog addable={addable} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'retire' ? (
                <RetireCountryDialog
                    country={dialog.country}
                    open
                    onOpenChange={close}
                    returnFocusTo={opener}
                    // Its row leaves the list while Show retired is off: focus goes to Add Country.
                    onRetired={() => (narrow.retired ? undefined : (opener.current = addButton.current))}
                />
            ) : null}
        </AdminLayout>
    );
}

/** Ships from, its places and its marks; on a phone, under the name (the other columns hide). */
function CountryRow({ country, retire }: { country: PlaceCountryRow; retire: (trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const href = `/admin/places/${country.code.toLowerCase()}`;
    const places = country.hasPlaces ? `${plural(t, locale, `${P}.regions`, country.regions)} · ${plural(t, locale, `${P}.cities`, country.cities)}` : null;
    const retireReason = country.store === null ? null : t(`${P}.reason.shipped`, { store: country.store.name });

    return (
        <TableRow className={country.retired ? 'opacity-70' : undefined} data-test={`country-${country.code}`}>
            <TableCell>
                <Link href={href} className="block min-w-0 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2" data-test="open-country">
                    <Names name={country.name} other={`${country.otherName} · ${country.code}`}>
                        {country.retired ? <RetiredBadge /> : null}
                    </Names>
                </Link>
                <span className="mt-1 grid gap-1 text-copy-13 text-ink-muted sm:hidden">
                    <span>
                        <ShipsFrom store={country.store} />
                    </span>
                    {places ?? (
                        <span>
                            <NoPlaces />
                        </span>
                    )}
                </span>
            </TableCell>
            <TableCell className="hidden sm:table-cell" data-test="ships-from">
                <ShipsFrom store={country.store} />
            </TableCell>
            <TableCell className="hidden md:table-cell" data-test="places">
                {places === null ? <NoPlaces /> : <span className="tw-figure">{places}</span>}
            </TableCell>
            <TableCell className="text-end">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <MoreButton ref={more} name={country.name} busy={busy} data-test={`country-actions-${country.code}`} />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        <Guarded label={t(`${P}.open`)} onSelect={() => router.visit(href)} test="open" />
                        <DropdownMenuSeparator />
                        {country.retired ? (
                            <Guarded
                                label={t(`${P}.restore_country`)}
                                onSelect={() => router.post(`/admin/places/${country.code.toLowerCase()}/restore`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                                test="restore-country"
                            />
                        ) : (
                            <>
                                <Guarded label={t(`${P}.retire_country`)} reason={retireReason} destructive onSelect={() => retire(more.current)} test="retire-country" />
                                {/* Out of reach while a store ships there: the way to that store beside it (Q8). */}
                                {country.store === null ? null : (
                                    <Guarded label={t(`${P}.open_store`, { store: country.store.name })} onSelect={() => router.visit(`/admin/stores#store-${country.store?.code ?? ''}`)} test="open-store" />
                                )}
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}

function AddCountryDialog({ addable, open, onOpenChange, returnFocusTo }: { addable: PlacesPage['addable']; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const form = useForm({ country: '' });

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${P}.add_country`)}
            description={t(`${P}.add_country_body`)}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={form.data.country === '' ? t(`${P}.pick_country`) : undefined}
                    onClick={() => form.post('/admin/places', { preserveScroll: true, onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-add-country"
                >
                    {t(`${P}.add_country`)}
                </ActionButton>
            }
        >
            <CountryCombobox
                id="add-country"
                label={t(`${P}.country`)}
                countries={addable}
                value={form.data.country}
                onChange={(country) => form.setData('country', country)}
                words={{
                    search: t(`${P}.country_search`),
                    none: (query) => t(`${P}.country_none`, { query }),
                }}
            />
        </PanelDialog>
    );
}

function RetireCountryDialog({
    country,
    open,
    onOpenChange,
    returnFocusTo,
    onRetired,
}: {
    country: PlaceCountryRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: RefObject<HTMLElement | null>;
    onRetired: () => void;
}) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${P}.retire_country`)}
            description={t(`${P}.retire_country_body`, { country: country.name })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/places/${country.code.toLowerCase()}/retire`, {}, {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => {
                                onRetired();
                                onOpenChange(false);
                            },
                        })
                    }
                    data-test="confirm-retire-country"
                >
                    {t(`${P}.retire_country`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
