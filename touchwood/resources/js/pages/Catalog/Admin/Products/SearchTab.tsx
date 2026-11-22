import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { nameIn, useLocale } from '../parts';
import { ProductShell } from './shell';

/*
| A product's Search and Filters (catalog.md §4.4 S9): **search words** as removable badges with an
| input and Add Word - 30 kept, 50 characters each, a word typed twice kept once, quietly (amendment
| 3(f)) - each change saved at once; and its **filters** - for each filter attribute, its values as
| checkboxes, several allowed (amendment 3(a)) - saved with Save Filters.
*/

const WORDS_MAX = 30;

export function SearchTab({ page }: { page: ProductPage }) {
    const { product, mayUpdate } = page;
    const t = useTranslator();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const words = page.searchWords ?? [];
    const [word, setWord] = useState('');
    const [busy, setBusy] = useState(false);
    const reason = mayUpdate ? undefined : t('catalog::admin_products.read_only');
    const addReason = reason ?? (words.length >= WORDS_MAX ? t('catalog::admin_products.search.words_max', { max: WORDS_MAX }) : undefined);
    const filterAttributes = (page.attributes ?? []).filter(
        (attribute) => attribute.kind === 'FILTERABLE' && (attribute.active || attribute.values.some((value) => (page.filterValueIds ?? []).includes(value.id))),
    );
    const filters = useForm<{ value_ids: string[] }>({ value_ids: page.filterValueIds ?? [] });
    // The word as typed (frontend.md §1.7): one of up to 50 characters (SearchWords::WORD_MAX). How
    // many a product holds is said by Add Word's own reason, before anything is typed.
    const checks = useChecks([
        { id: 'search-word', label: t('catalog::admin_products.search.word'), value: word, rules: { required: true, length: { max: 50 } }, off: addReason !== undefined },
    ]);

    useEffect(() => {
        filters.setDefaults({ value_ids: page.filterValueIds ?? [] });
        filters.reset();
    }, [page.filterValueIds]);

    function saveWords(next: string[]) {
        router.post(`/admin/products/${product.id}/search-words`, { words: next }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setWord('') });
    }

    const toggle = (id: string, on: boolean) => filters.setData('value_ids', on ? [...filters.data.value_ids, id] : filters.data.value_ids.filter((each) => each !== id));

    return (
        <div className="grid gap-6">
            <Card className="material-base border-0">
                <CardHeader>
                    <CardTitle className="text-heading-16 text-ink">{t('catalog::admin_products.search.words_title')}</CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_products.search.words_body')}</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4">
                    {words.length === 0 ? (
                        <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.search.no_words')}</p>
                    ) : (
                        <ul className="flex flex-wrap gap-2" data-test="search-words">
                            {words.map((each, index) => (
                                <li key={`${each}-${index}`}>
                                    <Badge variant="secondary" className="gap-1 ps-2 pe-1">
                                        <bdi>{each}</bdi>
                                        {reason === undefined ? (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                className="size-5"
                                                disabled={busy}
                                                aria-label={t('catalog::admin_products.search.remove_word', { word: each })}
                                                onClick={() => saveWords(words.filter((_, at) => at !== index))}
                                                data-test={`remove-word-${index}`}
                                            >
                                                <X aria-hidden="true" />
                                            </Button>
                                        ) : null}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();

                            checks.submit(() => saveWords([...words, word.trim()]));
                        }}
                    >
                        <TextField id="search-word" className="max-w-xs" disabled={addReason !== undefined} label={t('catalog::admin_products.search.word')} value={word} check={checks.box('search-word')} onChange={(event) => setWord(event.target.value)} data-test="search-word" />
                        <ActionButton type="submit" variant="outline" loading={busy} disabledReason={addReason ?? checks.reason} data-test="add-word">
                            {t('catalog::admin_products.search.add_word')}
                        </ActionButton>
                    </form>
                    {errors.search_words ? <FieldError>{errors.search_words}</FieldError> : null}
                </CardContent>
            </Card>

            <Card className="material-base border-0">
                <CardHeader>
                    <CardTitle className="text-heading-16 text-ink">{t('catalog::admin_products.search.filters_title')}</CardTitle>
                    <CardDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_products.search.filters_body')}</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-5">
                    {filterAttributes.length === 0 ? (
                        <p className="text-copy-14 text-ink-muted">{t('catalog::admin_products.search.no_filters')}</p>
                    ) : (
                        filterAttributes.map((attribute) => (
                            <FieldSet key={attribute.id}>
                                <FieldLegend className="text-label-14 text-ink">{nameIn(locale, attribute.nameAr, attribute.nameEn)}</FieldLegend>
                                <div className="flex flex-wrap gap-x-5 gap-y-2">
                                    {attribute.values
                                        .filter((value) => value.active || filters.data.value_ids.includes(value.id))
                                        .map((value) => (
                                            <div key={value.id} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`filter-${value.id}`}
                                                    disabled={reason !== undefined}
                                                    checked={filters.data.value_ids.includes(value.id)}
                                                    onCheckedChange={(on) => toggle(value.id, on === true)}
                                                    data-test={`filter-${value.id}`}
                                                />
                                                <Label htmlFor={`filter-${value.id}`} className="text-copy-14">
                                                    {nameIn(locale, value.nameAr, value.nameEn)}
                                                </Label>
                                            </div>
                                        ))}
                                </div>
                            </FieldSet>
                        ))
                    )}
                    {errors.filter_values ? <FieldError>{errors.filter_values}</FieldError> : null}
                    {filterAttributes.length > 0 ? (
                        <div className="flex justify-end">
                            <ActionButton
                                loading={filters.processing}
                                disabledReason={reason ?? (!filters.isDirty ? t('catalog::admin_products.details.no_changes') : undefined)}
                                onClick={() => filters.post(`/admin/products/${product.id}/filters`, { preserveScroll: true })}
                                data-test="save-filters"
                            >
                                {t('catalog::admin_products.search.save_filters')}
                            </ActionButton>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}

/** The Search and Filters tab's page: this product above its tabs, this one open (ProductShell). */
export default function SearchTabPage(page: ProductPage) {
    return (
        <ProductShell page={page}>
            <SearchTab page={page} />
        </ProductShell>
    );
}
