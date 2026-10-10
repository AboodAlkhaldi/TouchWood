import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { useLoadMore } from '@/lib/use-load-more';
import type { NoResultSearchData, SearchWordsPage, WordPairData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { useAllStoresReason, useLocale } from '../parts';

/*
| The search words screen (catalog.md §1.11, §4.4 S7): the shared word pairs - one entry that fixes a
| search for the whole shop, added and deleted, never edited (amendment 2(d)) - and the searches that
| found nothing, the list they are made from (handoff §9.5: "the highest-value report in the
| system"). Reading those needs `catalog.search_word.manage` with All stores, as adding a pair does;
| the log keeps no person (§1.11). A row's Add Word Pair opens the form with its words in it (P11).
*/

type Dialog = { action: 'add'; words: string } | { action: 'delete'; pair: WordPairData } | null;

// One empty list for every render: a new one each time would reset the list on every render, for ever.
const NO_SEARCHES: NoResultSearchData[] = [];

/** A search's row: its words, its store and its language (two stores may share a name). */
const searchKey = (search: NoResultSearchData) => `${search.query}|${search.storeCode}|${search.locale}`;

export default function Index({ pairs, mayChange, searches, page, more, storeCode, stores }: SearchWordsPage) {
    const t = useTranslator();
    const reason = useAllStoresReason(mayChange);
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const list = useLoadMore<NoResultSearchData>(searches ?? NO_SEARCHES, searchKey);

    return (
        <AdminLayout
            title={t('catalog::admin_search_words.title')}
            subtitle={t('catalog::admin_search_words.subtitle')}
            action={
                <ActionButton type="button" onClick={(event) => ((opener.current = event.currentTarget), setDialog({ action: 'add', words: '' }))} disabledReason={reason} data-test="add-pair">
                    {t('catalog::admin_search_words.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-8">
                <FormError />

                <section className="grid gap-3" aria-labelledby="pairs-title">
                    <h2 id="pairs-title" className="text-heading-16 text-ink">
                        {t('catalog::admin_search_words.pairs.title')}
                    </h2>
                    {pairs.length === 0 ? (
                        <Empty className="material-base" data-test="pairs-empty">
                            <EmptyHeader>
                                <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_search_words.pairs.empty_title')}</EmptyTitle>
                                <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_search_words.pairs.empty_body')}</EmptyDescription>
                            </EmptyHeader>
                            <EmptyContent>
                                <ActionButton type="button" onClick={() => setDialog({ action: 'add', words: '' })} disabledReason={reason}>
                                    {t('catalog::admin_search_words.add')}
                                </ActionButton>
                            </EmptyContent>
                        </Empty>
                    ) : (
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('catalog::admin_search_words.pairs.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('catalog::admin_search_words.pairs.column')}</TableHead>
                                        <TableHead className="w-32">
                                            <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pairs.map((pair) => (
                                        <TableRow key={pair.id} data-test={`pair-${pair.id}`}>
                                            <TableCell>
                                                <span className="flex items-center gap-2">
                                                    <bdi>{pair.wordA}</bdi>
                                                    <ArrowLeftRight aria-label={t('catalog::admin_search_words.pairs.means')} className="size-4 text-ink-muted" />
                                                    <bdi>{pair.wordB}</bdi>
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <ActionButton
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabledReason={reason}
                                                    onClick={(event) => ((opener.current = event.currentTarget), setDialog({ action: 'delete', pair }))}
                                                    data-test="delete-pair"
                                                >
                                                    {t('catalog::admin_search_words.delete')}
                                                </ActionButton>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>

                {searches === null ? null : (
                    <section className="grid gap-3" aria-labelledby="searches-title">
                        <div className="grid gap-1">
                            <h2 id="searches-title" className="text-heading-16 text-ink">
                                {t('catalog::admin_search_words.searches.title')}
                            </h2>
                            <p className="text-copy-13 text-ink-muted">{t('catalog::admin_search_words.searches.body')}</p>
                        </div>
                        <StoreFilter stores={stores} value={storeCode} all className="max-w-xs" />
                        {list.rows.length === 0 ? (
                            <Empty className="material-base" data-test="searches-empty">
                                <EmptyHeader>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('catalog::admin_search_words.searches.empty_title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::admin_search_words.searches.empty_body')}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="material-base overflow-x-auto">
                                <Table>
                                    <TableCaption className="sr-only">{t('catalog::admin_search_words.searches.title')}</TableCaption>
                                    <TableHeader className="bg-surface-sunken">
                                        <TableRow>
                                            <TableHead>{t('catalog::admin_search_words.searches.column.words')}</TableHead>
                                            <TableHead>{t('catalog::admin_search_words.searches.column.store')}</TableHead>
                                            <TableHead>{t('catalog::admin_search_words.searches.column.language')}</TableHead>
                                            <TableHead className="text-end">{t('catalog::admin_search_words.searches.column.times')}</TableHead>
                                            <TableHead>{t('catalog::admin_search_words.searches.column.last')}</TableHead>
                                            <TableHead className="w-40">
                                                <span className="sr-only">{t('catalog::admin.column.actions')}</span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {list.rows.map((search) => (
                                            <TableRow key={searchKey(search)} data-test="search-row">
                                                <TableCell>
                                                    <bdi>{search.query}</bdi>
                                                </TableCell>
                                                <TableCell>{search.storeName}</TableCell>
                                                <TableCell>{search.locale === 'ar' ? 'العربية' : 'English'}</TableCell>
                                                <TableCell className="tw-figure text-end">{figure(locale, search.times)}</TableCell>
                                                <TableCell>
                                                    <Time value={search.lastSearchedAt} />
                                                </TableCell>
                                                <TableCell className="text-end">
                                                    {mayChange ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={(event) => ((opener.current = event.currentTarget), setDialog({ action: 'add', words: search.query }))}
                                                            data-test="pair-from-search"
                                                        >
                                                            {t('catalog::admin_search_words.add_from')}
                                                        </Button>
                                                    ) : null}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                        {more ? (
                            <LoadMoreButton
                                loading={list.loading}
                                onClick={() => list.more(window.location.pathname, { page: page + 1, ...(storeCode !== null ? { store: storeCode } : {}) }, ['searches', 'page', 'more'])}
                            />
                        ) : null}
                    </section>
                )}
            </div>

            {dialog?.action === 'add' ? <PairDialog words={dialog.words} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog?.action === 'delete' ? <DeletePairDialog pair={dialog.pair} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function PairDialog({ words, open, onOpenChange, returnFocusTo }: { words: string; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const form = useForm({ word_a: words, word_b: '' });
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.setDefaults({ word_a: words, word_b: '' });
            form.reset();
            form.clearErrors();
        }
    }, [open, words]);

    // Each box as typed (frontend.md §1.7), with the domain's rules: each word one line of up to 50
    // characters (WordPair::WORD_MAX), both required.
    const word = { required: true, length: { max: 50 } };
    const checks = useChecks([
        { id: 'pair-word-a', label: t('catalog::admin_search_words.field.word_a'), value: form.data.word_a, rules: word },
        { id: 'pair-word-b', label: t('catalog::admin_search_words.field.word_b'), subject: t('catalog::admin_search_words.field.word_b_subject'), value: form.data.word_b, rules: word },
    ]);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_search_words.add')}
            description={t('catalog::admin_search_words.add_body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => form.post('/admin/search-words', { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-pair"
                >
                    {t('catalog::admin_search_words.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="pair-word-a" label={t('catalog::admin_search_words.field.word_a')} value={form.data.word_a} check={checks.box('pair-word-a', form.errors.word_a ?? errors.word_pair)} onChange={(event) => form.setData('word_a', event.target.value)} data-test="pair-word-a" />
                <TextField id="pair-word-b" label={t('catalog::admin_search_words.field.word_b')} helper={t('catalog::admin_search_words.field.word_helper')} value={form.data.word_b} check={checks.box('pair-word-b', form.errors.word_b)} onChange={(event) => form.setData('word_b', event.target.value)} data-test="pair-word-b" />
            </div>
        </PanelDialog>
    );
}

function DeletePairDialog({ pair, open, onOpenChange, returnFocusTo }: { pair: WordPairData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_search_words.delete_title')}
            description={t('catalog::admin_search_words.delete_body', { one: pair.wordA, other: pair.wordB })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/search-words/${pair.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-pair"
                >
                    {t('catalog::admin_search_words.delete_title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
