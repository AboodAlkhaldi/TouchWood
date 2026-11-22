import { type RefObject, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField } from '@/components/Fields';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ReachedProductData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from './parts';

/*
| Deactivating a brand or a category (catalog.md §1.5, §1.6; §4.4 S1, S2): **every product it
| reaches, in any stage, gets its fate** - one choice for all, and a choice per product over it - in
| one step, all or nothing, as the handler is. A brand's products are hidden or moved to another
| brand, never left without one; a category's are hidden, left in it (unlisted but reachable) or moved
| to another active lowest category outside what goes. The dialog says, before its button, that a
| hidden product cannot be ordered (amendment 5(j)) and comes back when the list is switched on again
| (amendment 5(m)).
|
| The products are read when the dialog opens - only someone who may deactivate reads them - through
| the page's own address, kept as it is (Inertia's partial reload), and kept here once read: a refused
| deactivation comes back to the page without them, and the dialog must still show them and say why.
| A category's dialog also names the sub-categories going with it and where each product sits (S2).
*/

export type FateKind = 'brand' | 'category';

type Choice = 'HIDE' | 'LEAVE' | 'MOVE';

type Option = { id: string; name: string };

type FatesForm = {
    every_product: Choice;
    move_to: string;
    products: Record<string, { choice: Choice; move_to: string }>;
};

export function FatesDialog({
    kind,
    target,
    reached,
    moveTargets,
    goingWith = [],
    placeOf,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    kind: FateKind;
    target: Option;
    /** The products reached, as the page has them for this target; null until they are read. */
    reached: ReachedProductData[] | null;
    /** Where products may be moved: active brands, or active lowest categories outside it. */
    moveTargets: Option[];
    /** A category's active sub-categories, deactivated with it. */
    goingWith?: string[];
    /** The name of the category a product sits in, for a category's dialog. */
    placeOf?: (categoryId: string | null) => string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const base = kind === 'brand' ? '/admin/brands' : '/admin/categories';
    const choices: Choice[] = kind === 'brand' ? ['HIDE', 'MOVE'] : ['HIDE', 'LEAVE', 'MOVE'];
    const [loading, setLoading] = useState(false);
    // The products once read for this target, kept through a refused deactivation (see above).
    const [kept, setKept] = useState<ReachedProductData[] | null>(null);
    const [each, setEach] = useState(false);
    const form = useForm<FatesForm>({ every_product: 'HIDE', move_to: '', products: {} });
    // A refusal may name a field the form does not have by that name: a product's own choice.
    const errors = form.errors as Record<string, string | undefined>;
    // "Where to" refused while every product is not being moved: it was a product's own move.
    const ownMoveRefused = errors.move_to !== undefined && form.data.every_product !== 'MOVE';

    useEffect(() => {
        if (reached !== null) {
            setKept(reached);
        }
    }, [reached]);

    // A refusal about a product's own fate opens the table where it is chosen.
    useEffect(() => {
        if (errors.products !== undefined || ownMoveRefused) {
            setEach(true);
        }
    }, [errors.products, ownMoveRefused]);

    // Each opening reads the products this deactivation reaches now, and starts the form afresh.
    useEffect(() => {
        if (!open) {
            return;
        }

        form.setDefaults({ every_product: 'HIDE', move_to: '', products: {} });
        form.reset();
        form.clearErrors();
        setKept(null);
        setEach(false);
        router.get(
            window.location.pathname + window.location.search,
            { reach: target.id },
            {
                only: ['reached', 'reachedFor'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
        // Keyed on the opening and the target only.
    }, [open, target.id]);

    const products = kept ?? [];

    function own(productId: string, choice: Choice | '') {
        const next = { ...form.data.products };

        if (choice === '') {
            delete next[productId];
        } else {
            next[productId] = { choice, move_to: next[productId]?.move_to ?? '' };
        }

        form.setData('products', next);
    }

    function ownTarget(productId: string, moveTo: string) {
        const fate = form.data.products[productId];

        if (fate !== undefined) {
            form.setData('products', { ...form.data.products, [productId]: { ...fate, move_to: moveTo } });
        }
    }

    function submit() {
        form.post(`${base}/${target.id}/deactivate`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    }

    const title = t(`catalog::admin.fates.title.${kind}`, { name: target.name });

    return (
        <PanelDialog
            destructive
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t(`catalog::admin.fates.body.${kind}`)}
            busy={form.processing}
            confirm={
                <ActionButton variant="destructive" loading={form.processing} disabledReason={loading ? t('catalog::admin.fates.loading') : undefined} onClick={submit} data-test="confirm-deactivate">
                    {t(`catalog::admin.fates.confirm.${kind}`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <Note variant="warning" data-test="hidden-note">
                    {t('catalog::admin.fates.hidden_note')}
                </Note>

                {goingWith.length > 0 ? (
                    <p className="text-copy-14 text-ink" data-test="going-with">
                        {t('catalog::admin.fates.going_with', { names: goingWith.join(locale === 'ar' ? '، ' : ', ') })}
                    </p>
                ) : null}

                {loading || kept === null ? (
                    <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                        <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                        {t('catalog::admin.fates.loading')}
                    </p>
                ) : (
                    <p className="text-copy-14 text-ink" data-test="reached-count">
                        {products.length === 0 ? t(`catalog::admin.fates.none.${kind}`) : t('catalog::admin.fates.count', { count: figure(locale, products.length) })}
                    </p>
                )}

                {products.length === 0 ? null : (
                    <>
                        <FieldSet>
                            <FieldLegend className="text-label-14 text-ink">{t('catalog::admin.fates.every')}</FieldLegend>
                            <RadioGroup value={form.data.every_product} onValueChange={(value) => form.setData('every_product', value as Choice)} className="grid gap-2">
                                {choices.map((choice) => (
                                    <div key={choice} className="flex items-start gap-2">
                                        <RadioGroupItem id={`every-${choice}`} value={choice} data-test={`every-${choice}`} />
                                        <Label htmlFor={`every-${choice}`} className="grid gap-0.5 text-copy-14">
                                            <span className="text-label-14 text-ink">{t(`catalog::admin.fates.choice.${choice}`)}</span>
                                            <span className="text-ink-muted">{t(`catalog::admin.fates.choice_body.${kind}.${choice}`)}</span>
                                        </Label>
                                    </div>
                                ))}
                            </RadioGroup>
                        </FieldSet>

                        {form.data.every_product === 'MOVE' ? (
                            <SelectField
                                id="fates-move-to"
                                label={t(`catalog::admin.fates.move_to.${kind}`)}
                                value={form.data.move_to}
                                error={form.errors.move_to}
                                onChange={(event) => form.setData('move_to', event.target.value)}
                                data-test="fates-move-to"
                            >
                                <NativeSelectOption value="">{t('catalog::admin.fates.choose')}</NativeSelectOption>
                                {moveTargets.map((option) => (
                                    <NativeSelectOption key={option.id} value={option.id}>
                                        {option.name}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                        ) : null}

                        {/* A refusal about the choices is said here, never folded away with the table. */}
                        {errors.choice || errors.products || ownMoveRefused ? (
                            <FieldError data-test="fates-refused">{errors.choice ?? errors.products ?? errors.move_to}</FieldError>
                        ) : null}

                        <Collapsible open={each} onOpenChange={setEach}>
                            <CollapsibleTrigger asChild>
                                <Button type="button" variant="ghost" size="sm" className="justify-start px-0" data-test="fates-each">
                                    <ChevronDown aria-hidden="true" />
                                    {t('catalog::admin.fates.each')}
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <div className="material-base max-h-80 overflow-y-auto">
                                    <Table>
                                        <TableCaption className="sr-only">{t('catalog::admin.fates.each')}</TableCaption>
                                        <TableHeader className="bg-surface-sunken">
                                            <TableRow>
                                                <TableHead>{t('catalog::admin.fates.product')}</TableHead>
                                                <TableHead>{t('catalog::admin.fates.stage')}</TableHead>
                                                {placeOf !== undefined ? <TableHead>{t('catalog::admin.fates.category')}</TableHead> : null}
                                                <TableHead>{t('catalog::admin.fates.its_fate')}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {products.map((product) => {
                                                const fate = form.data.products[product.id];

                                                return (
                                                    <TableRow key={product.id} data-test={`fate-${product.id}`}>
                                                        <TableCell>{nameIn(locale, product.nameAr, product.nameEn)}</TableCell>
                                                        <TableCell>
                                                            <Badge className={tone(product.stage === 'READY' ? 'green-subtle' : product.stage === 'ARCHIVED' ? 'amber-subtle' : 'gray-subtle')}>
                                                                {t(`catalog::admin.stage.${product.stage}`)}
                                                            </Badge>
                                                        </TableCell>
                                                        {placeOf !== undefined ? <TableCell>{placeOf(product.categoryId) ?? '—'}</TableCell> : null}
                                                        <TableCell className="grid gap-2">
                                                            <NativeSelect
                                                                size="sm"
                                                                aria-label={t('catalog::admin.fates.its_fate_of', { name: nameIn(locale, product.nameAr, product.nameEn) })}
                                                                value={fate?.choice ?? ''}
                                                                onChange={(event) => own(product.id, event.target.value as Choice | '')}
                                                            >
                                                                <NativeSelectOption value="">{t('catalog::admin.fates.as_every')}</NativeSelectOption>
                                                                {choices.map((choice) => (
                                                                    <NativeSelectOption key={choice} value={choice}>
                                                                        {t(`catalog::admin.fates.choice.${choice}`)}
                                                                    </NativeSelectOption>
                                                                ))}
                                                            </NativeSelect>
                                                            {fate?.choice === 'MOVE' ? (
                                                                <NativeSelect
                                                                    size="sm"
                                                                    aria-label={t(`catalog::admin.fates.move_to.${kind}`)}
                                                                    aria-invalid={(ownMoveRefused && fate.move_to === '') || undefined}
                                                                    value={fate.move_to}
                                                                    onChange={(event) => ownTarget(product.id, event.target.value)}
                                                                >
                                                                    <NativeSelectOption value="">{t('catalog::admin.fates.choose')}</NativeSelectOption>
                                                                    {moveTargets.map((option) => (
                                                                        <NativeSelectOption key={option.id} value={option.id}>
                                                                            {option.name}
                                                                        </NativeSelectOption>
                                                                    ))}
                                                                </NativeSelect>
                                                            ) : null}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </>
                )}
            </div>
        </PanelDialog>
    );
}
