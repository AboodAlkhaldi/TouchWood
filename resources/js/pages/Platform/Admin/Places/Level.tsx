import { type RefObject, useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { PlaceLevelPage, PlaceRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';
import { NarrowBar, Names, P, RetiredBadge, plural, useLocale, useNarrowed } from './parts';

/*
| A level of E8 (platform.md §4.3; the owner, 2026-10-29, Q4): a country's regions, a region's cities,
| a city's districts - one page each, under the panel's trail (shadcn's Breadcrumb in AdminLayout), the
| row a link to the level under it.
|
| A place is added, renamed, retired and restored here, never moved or deleted (Q13; shipping.md §9.3
| #4). Retiring takes everything under it out of the pickers with it, their own marks untouched, so the
| dialog counts what goes (Q7); nothing is added under something retired (PlaceRetired), so on such a
| page Add stays in sight, out of reach, with the reason. Names are checked as they are typed with the
| server's rules: both languages, 1 to 100 characters, digits made Latin.
*/

type Kind = 'REGION' | 'CITY' | 'DISTRICT';

type Dialog = { action: 'add' } | { action: 'rename' | 'retire'; place: PlaceRow } | null;

export default function Level({ country, parents, kind, places, hiddenBy }: PlaceLevelPage) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const addButton = useRef<HTMLButtonElement>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const narrow = useNarrowed(places);
    const code = country.code.toLowerCase();
    const base = `/admin/places/${code}`;
    const level = kind as Kind;
    // The page is named for what holds this level: the country, the region or the city.
    const region = parents[0];
    const above = parents.at(-1);
    const holder = above?.name ?? country.name;
    const parentId = above?.id ?? null;
    const trail = [
        { label: t(`${P}.title`), href: '/admin/places' },
        ...(above === undefined ? [] : [{ label: country.name, href: base }]),
        ...(region !== undefined && region !== above ? [{ label: region.name, href: `${base}/${region.id}` }] : []),
    ];
    const hrefOf = (place: PlaceRow): string | null => (level === 'REGION' ? `${base}/${place.id}` : level === 'CITY' && region !== undefined ? `${base}/${region.id}/${place.id}` : null);
    const addReason = hiddenBy === null ? undefined : hiddenBy === 'COUNTRY' ? t(`${P}.reason.retired`) : t(`${P}.reason.parent_retired`);
    const add = () => {
        opener.current = addButton.current;
        setDialog({ action: 'add' });
    };
    const addLabel = t(`${P}.add.${level}`);

    return (
        <AdminLayout
            title={holder}
            subtitle={t(`${P}.list.${level}`)}
            breadcrumbs={trail}
            action={
                <ActionButton ref={addButton} type="button" onClick={add} disabledReason={addReason} data-test="add-place">
                    {addLabel}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                {hiddenBy !== null ? (
                    <Note variant="warning" data-test="under-retired">
                        {t(`${P}.under_retired`, { kind: t(`${P}.kind_lower.${hiddenBy}`) })}
                    </Note>
                ) : null}
                {places.length === 0 ? (
                    <Empty className="material-base" data-test="places-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(`${P}.empty.${level}.title`)}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${P}.empty.${level}.body`)}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <ActionButton type="button" onClick={add} disabledReason={addReason}>
                                {addLabel}
                            </ActionButton>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <NarrowBar id="places" {...narrow} />
                        {narrow.shown.length === 0 ? (
                            <p className="text-copy-14 text-ink-muted" data-test="places-none">
                                {t(`${P}.none_found`, { text: narrow.text.trim() })}
                            </p>
                        ) : (
                            <div className="material-base overflow-x-auto">
                                <Table>
                                    <TableCaption className="sr-only">{t(`${P}.list.${level}`)}</TableCaption>
                                    <TableHeader className="bg-surface-sunken">
                                        <TableRow>
                                            <TableHead>{t(`${P}.column.name`)}</TableHead>
                                            {level === 'DISTRICT' ? null : <TableHead className="hidden sm:table-cell">{t(`${P}.column.under`)}</TableHead>}
                                            <TableHead className="w-12">
                                                <span className="sr-only">{t(`${P}.column.actions`)}</span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {narrow.shown.map((place) => (
                                            <PlaceLine
                                                key={place.id}
                                                place={place}
                                                href={hrefOf(place)}
                                                base={base}
                                                open={(action, trigger) => {
                                                    opener.current = trigger;
                                                    setDialog({ action, place });
                                                }}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </>
                )}
            </div>

            {dialog?.action === 'add' ? <NameDialog base={base} kind={level} parentId={parentId} holder={holder} place={null} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'rename' ? <NameDialog base={base} kind={level} parentId={parentId} holder={holder} place={dialog.place} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'retire' ? <RetireDialog base={base} place={dialog.place} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Under({ place }: { place: PlaceRow }) {
    const t = useTranslator();
    const locale = useLocale();

    return <span className="tw-figure">{place.kind === 'REGION' ? plural(t, locale, `${P}.cities`, place.cities) : plural(t, locale, `${P}.districts`, place.districts)}</span>;
}

function PlaceLine({ place, href, base, open }: { place: PlaceRow; href: string | null; base: string; open: (action: 'rename' | 'retire', trigger: HTMLElement | null) => void }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const names = (
        <Names name={place.name} other={place.otherName}>
            {place.retired ? <RetiredBadge /> : null}
        </Names>
    );

    return (
        <TableRow className={place.retired ? 'opacity-70' : undefined} data-test={`place-${place.id}`}>
            <TableCell>
                {href === null ? (
                    names
                ) : (
                    <Link href={href} className="block min-w-0 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2" data-test="open-place">
                        {names}
                    </Link>
                )}
                {place.kind === 'DISTRICT' ? null : (
                    <span className="mt-1 block text-copy-13 text-ink-muted sm:hidden">
                        <Under place={place} />
                    </span>
                )}
            </TableCell>
            {place.kind === 'DISTRICT' ? null : (
                <TableCell className="hidden sm:table-cell" data-test="under">
                    <Under place={place} />
                </TableCell>
            )}
            <TableCell className="text-end">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <MoreButton ref={more} name={place.name} busy={busy} data-test={`place-actions-${place.id}`} />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        <Guarded label={t(`${P}.rename`)} onSelect={() => open('rename', more.current)} test="rename-place" />
                        <DropdownMenuSeparator />
                        {place.retired ? (
                            <Guarded
                                label={t(`${P}.restore`)}
                                onSelect={() => router.post(`${base}/places/${place.id}/restore`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                                test="restore-place"
                            />
                        ) : (
                            <Guarded label={t(`${P}.retire`)} destructive onSelect={() => open('retire', more.current)} test="retire-place" />
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}

type NameForm = { kind: Kind; parent: string; name_ar: string; name_en: string };

/** Add Region, Add City, Add District - or Rename, filled with its names. */
function NameDialog({
    base,
    kind,
    parentId,
    holder,
    place,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    base: string;
    kind: Kind;
    parentId: string | null;
    holder: string;
    place: PlaceRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<NameForm>({ kind, parent: parentId ?? '', name_ar: place?.nameAr ?? '', name_en: place?.nameEn ?? '' });

    useEffect(() => {
        form.clearErrors();
    }, [open]);

    // The server's rules (stage 7 step 1): both names, at most 100 characters each.
    const name = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'place-name-ar', label: t(`${P}.name_ar`), value: form.data.name_ar, rules: name },
        { id: 'place-name-en', label: t(`${P}.name_en`), value: form.data.name_en, rules: name },
    ]);
    const title = place === null ? t(`${P}.add.${kind}`) : t(`${P}.rename_title`, { name: place.name });

    function submit() {
        checks.submit(() => form.post(place === null ? `${base}/places` : `${base}/places/${place.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={place === null ? t(`${P}.add_body.${kind}`, { parent: holder }) : t(`${P}.rename_body`)}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-place">
                    {place === null ? title : t(`${P}.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField id="place-name-ar" dir="rtl" lang="ar" label={t(`${P}.name_ar`)} value={form.data.name_ar} check={checks.box('place-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', toLatinDigits(event.target.value))} data-test="place-name-ar" />
                <TextField id="place-name-en" dir="ltr" lang="en" label={t(`${P}.name_en`)} value={form.data.name_en} check={checks.box('place-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', toLatinDigits(event.target.value))} data-test="place-name-en" />
            </div>
        </PanelDialog>
    );
}

function RetireDialog({ base, place, open, onOpenChange, returnFocusTo }: { base: string; place: PlaceRow; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${P}.retire_title`, { name: place.name })}
            description={t(`${P}.retire_body.${place.kind}`, { name: place.name, cities: plural(t, locale, `${P}.cities`, place.cities), districts: plural(t, locale, `${P}.districts`, place.districts) })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`${base}/places/${place.id}/retire`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-retire-place"
                >
                    {t(`${P}.retire`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
