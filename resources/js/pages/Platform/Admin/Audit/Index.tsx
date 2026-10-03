import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { DateRangeField } from '@/components/DateRangeField';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { SearchCombobox } from '@/components/SearchCombobox';
import { Time } from '@/components/Time';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { NativeSelectOption } from '@/components/ui/native-select';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { AuditLogPage, AuditRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E6 - the audit log (frontend.md §3.5), on shadcn's parts with Geist's rules (§1.11).
|
| Who changed what and when, for the stores this person may read. An entry that belongs to no store
| - a currency, a global setting, a role - needs every store to read: those changes are the system's
| rather than a shop's.
|
| **A personal field shows only as "changed"**, and not because this screen hides it: the value was
| never recorded. A module writes a name, an email, a phone or an address with personal(), which
| keeps only that it changed, because the log is kept forever and anonymizing an account must never
| have to rewrite history (platform.md §1.5).
|
| The filters are a Card with its two buttons in the footer (Geist's Fieldset): the days as one
| range with Geist's presets, the actions as a list to type into (more of them with every module,
| named as the entries name them), the source as a short Select. Filtered to nothing, the page says
| so and offers to clear them (Geist's Empty State, its no-results form).
|
| Paged by keyset rather than by page number: the log only grows, and an offset would walk rows it
| has already shown every time somebody asks for more - so the list ends in Show More, which adds
| the next page under the entries already shown (owner, 2026-10-04).
*/

type Props = AuditLogPage;

type Filters = { from: string; until: string; actor: string; action: string; source: string };

const NONE: Filters = { from: '', until: '', actor: '', action: '', source: '' };

export default function Index({ entries, actions, actionLabels, sources, filters, nextOccurredAt, nextId }: Props) {
    const t = useTranslator();
    const applied: Filters = {
        from: filters.from ?? '',
        until: filters.until ?? '',
        actor: filters.actor ?? '',
        action: filters.action ?? '',
        source: filters.source ?? '',
    };
    const [form, setForm] = useState<Filters>(applied);
    const list = useLoadMore(entries, (entry) => String(entry.id));
    const filtered = Object.values(applied).some((value) => value !== '');

    function apply(next: Filters) {
        router.get('/admin/audit', asked(next), { preserveState: true });
    }

    function clear() {
        setForm(NONE);
        apply(NONE);
    }

    return (
        <AdminLayout title={t('platform::admin_audit.title')} subtitle={t('platform::admin_audit.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                <Card className="material-base gap-0 border-0 py-0">
                    <form
                        aria-labelledby="filters-title"
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply(form);
                        }}
                    >
                        <CardHeader className="px-5 pt-4 pb-3">
                            <CardTitle>
                                <h2 id="filters-title" className="text-heading-14 text-ink">
                                    {t('platform::admin_audit.filters')}
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 px-5 pb-4 sm:grid-cols-2 lg:grid-cols-4">
                            <DateRangeField
                                id="dates"
                                label={t('platform::admin_audit.dates')}
                                from={form.from}
                                until={form.until}
                                onChange={(from, until) => setForm({ ...form, from, until })}
                                words={{
                                    any: t('platform::admin_audit.any_date'),
                                    presets: {
                                        today: t('platform::admin_audit.preset_today'),
                                        week: t('platform::admin_audit.preset_week'),
                                        month: t('platform::admin_audit.preset_month'),
                                        monthToDate: t('platform::admin_audit.preset_month_to_date'),
                                    },
                                }}
                            />

                            <TextField id="actor" label={t('platform::admin_audit.actor')} dir="ltr" value={form.actor} onChange={(event) => setForm({ ...form, actor: event.target.value })} />

                            {/* The actions really in the log, so a module that starts recording
                                something new appears here without anybody adding it; named as its
                                entries name it. */}
                            <SearchCombobox
                                id="action"
                                label={t('platform::admin_audit.action')}
                                options={[{ value: '', label: t('platform::admin_audit.any') }, ...actions.map((action) => ({ value: action, label: actionLabels[action] ?? action }))]}
                                value={form.action}
                                onChange={(action) => setForm({ ...form, action })}
                                words={{
                                    search: t('platform::admin_audit.action_search'),
                                    none: (query) => t('platform::admin_audit.action_none', { query }),
                                }}
                            />

                            <SelectField id="source" label={t('platform::admin_audit.source')} value={form.source} onChange={(event) => setForm({ ...form, source: event.target.value })}>
                                <NativeSelectOption value="">{t('platform::admin_audit.any')}</NativeSelectOption>
                                {sources.map((source) => (
                                    <NativeSelectOption key={source} value={source}>
                                        {sourceLabel(source, t)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                        </CardContent>
                        <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-5 py-3 [.border-t]:pt-3">
                            <Button type="button" variant="outline" onClick={clear} data-test="clear">
                                {t('platform::admin_audit.clear')}
                            </Button>
                            <Button type="submit" data-test="apply">
                                {t('platform::admin_audit.apply')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" aria-live="polite">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{filtered ? t('platform::admin_audit.none_match_title') : t('platform::admin_audit.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{filtered ? t('platform::admin_audit.none_match') : t('platform::admin_audit.none')}</EmptyDescription>
                        </EmptyHeader>
                        {filtered ? (
                            <EmptyContent>
                                <Button type="button" variant="outline" onClick={clear}>
                                    {t('platform::admin_audit.clear')}
                                </Button>
                            </EmptyContent>
                        ) : null}
                    </Empty>
                ) : (
                    <ItemGroup className="material-base overflow-hidden" aria-label={t('platform::admin_audit.title')}>
                        {list.rows.map((entry, index) => (
                            <div key={entry.id} role="listitem">
                                {index === 0 ? null : <ItemSeparator className="my-0" />}
                                <Entry entry={entry} />
                            </div>
                        ))}
                    </ItemGroup>
                )}

                {nextOccurredAt !== null && nextId !== null ? (
                    <LoadMoreButton
                        loading={list.loading}
                        // The filters the list was drawn with, never what is typed in the boxes and
                        // not yet applied.
                        onClick={() => list.more('/admin/audit', { ...asked(applied), after_at: nextOccurredAt, after_id: nextId }, ['entries', 'nextOccurredAt', 'nextId'])}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}

/**
 * The filters that were actually filled in. An empty box is left out of the address entirely, so
 * clearing them lands somebody on a bare /admin/audit rather than one carrying five empty answers.
 */
function asked(filters: Filters): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}

/** A source in words; written out so the words check reads each key. */
function sourceLabel(source: string, t: (key: string) => string): string {
    switch (source) {
        case 'WEB':
            return t('platform::admin_audit.source_web');
        case 'INTEGRATION':
            return t('platform::admin_audit.source_integration');
        case 'CONSOLE':
            return t('platform::admin_audit.source_console');
        case 'JOB':
            return t('platform::admin_audit.source_job');
        case 'IMPORT':
            return t('platform::admin_audit.source_import');
        default:
            // A source a newer module records, before it has words: as it is recorded, never blank.
            return source;
    }
}

function Entry({ entry }: { entry: AuditRow }) {
    const t = useTranslator();

    return (
        <Item size="sm" className="items-start rounded-none px-5 py-3" data-test={`entry-${entry.id}`}>
            <ItemContent className="min-w-0 gap-1.5">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <ItemTitle className="text-label-14 text-ink">{entry.actionLabel}</ItemTitle>
                    <span className="text-copy-13 text-ink-muted">
                        <Time value={entry.occurredAt} />
                    </span>
                </div>

                <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted">
                    {t(`platform::admin_audit.actor_${entry.actorType.toLowerCase()}`)}
                    {/* Named by Access (amendment 54): a Super Admin reads "System administrator", with
                        no id, to anyone but another Super Admin; the id stays for everyone else. */}
                    {entry.actorName === null ? null : <bdi> {entry.actorName}</bdi>}
                    {entry.actorId === null ? null : <bdi className="tw-figure"> {entry.actorId}</bdi>}
                    {entry.requestedById === null && entry.requestedByName === null ? null : (
                        <> · {t('platform::admin_audit.requested_by', { who: entry.requestedByName ?? entry.requestedById ?? '' })}</>
                    )}
                    {' · '}
                    {sourceLabel(entry.source, t)}
                    {' · '}
                    {entry.storeName ?? t('platform::admin_audit.no_store')}
                    {entry.ipAddress === null ? null : (
                        <>
                            {' · '}
                            <bdi dir="ltr" className="tw-figure">
                                {entry.ipAddress}
                            </bdi>
                        </>
                    )}
                </ItemDescription>

                {/* A private file's entry, for somebody who may not see private files: what was
                    done, when and by whom, but not which file nor what changed (amendment 8(c)). */}
                <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted" data-test={entry.withheld ? `withheld-${entry.id}` : undefined}>
                    {t('platform::admin_audit.subject')}:{' '}
                    {entry.withheld ? (
                        t('platform::admin_audit.private_file')
                    ) : (
                        <>
                            <bdi className="tw-figure">{entry.subjectType}</bdi> {entry.subjectName === null ? null : <bdi>{entry.subjectName} </bdi>}
                            <bdi className="tw-figure">{entry.subjectId}</bdi>
                        </>
                    )}
                </ItemDescription>

                {entry.changes.length === 0 ? null : (
                    // What changed, as Geist's Description: the attribute, then its value - "from →
                    // to", or only "changed" for a personal field, whose values were never recorded.
                    <div className="border-t border-line pt-2">
                        <Description
                            columns={2}
                            items={entry.changes.map((change) => ({
                                title: <bdi>{change.attribute}</bdi>,
                                content: change.personal ? t('platform::admin_audit.personal') : t('platform::admin_audit.from_to', { from: change.from ?? '—', to: change.to ?? '—' }),
                            }))}
                        />
                    </div>
                )}
            </ItemContent>
        </Item>
    );
}
