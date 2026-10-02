import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button, EmptyState, Input, LoadMoreButton, Select } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { AuditLogPage, AuditRow } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E6 - the audit log (frontend.md §3.5), in Geist's parts (1.10).
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
| Paged by keyset rather than by page number: the log only grows, and an offset would walk rows it
| has already shown every time somebody asks for more - so the list ends in Geist's Load More.
*/

type Props = AuditLogPage;

type Filters = { from: string; until: string; actor: string; action: string; source: string };

export default function Index({ entries, actions, sources, filters, nextOccurredAt, nextId }: Props) {
    const t = useTranslator();

    const [form, setForm] = useState<Filters>({
        from: filters.from ?? '',
        until: filters.until ?? '',
        actor: filters.actor ?? '',
        action: filters.action ?? '',
        source: filters.source ?? '',
    });

    function apply(next: Filters) {
        router.get('/admin/audit', asked(next), { preserveState: true });
    }

    return (
        <AdminLayout title={t('platform::admin_audit.title')} subtitle={t('platform::admin_audit.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                    className="material-base grid gap-3 p-4"
                >
                    <h2 className="text-heading-14 text-ink">{t('platform::admin_audit.filters')}</h2>

                    <div className="flex flex-wrap items-end gap-3">
                        <Input
                            id="from"
                            label={t('platform::admin_audit.from')}
                            type="date"
                            dir="ltr"
                            value={form.from}
                            onChange={(event) => setForm({ ...form, from: event.target.value })}
                        />

                        <Input
                            id="until"
                            label={t('platform::admin_audit.until')}
                            type="date"
                            dir="ltr"
                            value={form.until}
                            onChange={(event) => setForm({ ...form, until: event.target.value })}
                        />

                        <Input
                            id="actor"
                            label={t('platform::admin_audit.actor')}
                            dir="ltr"
                            className="w-56"
                            value={form.actor}
                            onChange={(event) => setForm({ ...form, actor: event.target.value })}
                        />

                        <Select
                            id="action"
                            label={t('platform::admin_audit.action')}
                            value={form.action}
                            onChange={(event) => setForm({ ...form, action: event.target.value })}
                        >
                            <option value="">{t('platform::admin_audit.any')}</option>
                            {/* The actions really in the log, so a module that starts recording
                                something new appears here without anybody adding it. */}
                            {actions.map((action) => (
                                <option key={action} value={action}>
                                    {action}
                                </option>
                            ))}
                        </Select>

                        <Select
                            id="source"
                            label={t('platform::admin_audit.source')}
                            value={form.source}
                            onChange={(event) => setForm({ ...form, source: event.target.value })}
                        >
                            <option value="">{t('platform::admin_audit.any')}</option>
                            {sources.map((source) => (
                                <option key={source} value={source}>
                                    {t(`platform::admin_audit.source_${source.toLowerCase()}`)}
                                </option>
                            ))}
                        </Select>

                        <div className="flex items-center gap-2">
                            <Button typeName="submit" data-test="apply">
                                {t('platform::admin_audit.apply')}
                            </Button>

                            <Button
                                type="tertiary"
                                onClick={() => {
                                    const cleared: Filters = { from: '', until: '', actor: '', action: '', source: '' };
                                    setForm(cleared);
                                    apply(cleared);
                                }}
                            >
                                {t('platform::admin_audit.clear')}
                            </Button>
                        </div>
                    </div>
                </form>

                {entries.length === 0 ? (
                    <EmptyState title={t('platform::admin_audit.none_title')} description={t('platform::admin_audit.none')} />
                ) : (
                    <ul className="grid gap-2">
                        {entries.map((entry) => (
                            <Entry key={entry.id} entry={entry} />
                        ))}
                    </ul>
                )}

                {nextOccurredAt !== null && nextId !== null ? (
                    <LoadMoreButton
                        data-test="more"
                        onClick={() =>
                            router.get('/admin/audit', {
                                ...asked(form),
                                after_at: nextOccurredAt,
                                after_id: nextId,
                            })
                        }
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

function Entry({ entry }: { entry: AuditRow }) {
    const t = useTranslator();

    return (
        <li className="material-base grid gap-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-label-14 text-ink">{entry.actionLabel}</span>
                <span className="tw-figure text-copy-13 text-ink-muted" dir="ltr">
                    {entry.occurredAt.slice(0, 19).replace('T', ' ')}
                </span>
            </div>

            <p className="text-copy-13 text-ink-muted">
                {t(`platform::admin_audit.actor_${entry.actorType.toLowerCase()}`)}
                {entry.actorId === null ? null : <span className="tw-figure"> {entry.actorId}</span>}

                {entry.requestedById === null ? null : (
                    <> · {t('platform::admin_audit.requested_by', { who: entry.requestedById })}</>
                )}

                {' · '}
                {t(`platform::admin_audit.source_${entry.source.toLowerCase()}`)}
                {' · '}
                {entry.storeName ?? t('platform::admin_audit.no_store')}

                {entry.ipAddress === null ? null : (
                    <>
                        {' · '}
                        <span className="tw-figure" dir="ltr">
                            {entry.ipAddress}
                        </span>
                    </>
                )}
            </p>

            {/* A private file's entry, for somebody who may not see private files: what was done,
                when and by whom, but not which file nor what changed (amendment 8(c)). */}
            <p className="text-copy-13 text-ink-muted" data-test={entry.withheld ? `withheld-${entry.id}` : undefined}>
                {t('platform::admin_audit.subject')}:{' '}
                {entry.withheld ? (
                    t('platform::admin_audit.private_file')
                ) : (
                    <>
                        <span className="tw-figure">{entry.subjectType}</span>{' '}
                        <span className="tw-figure">{entry.subjectId}</span>
                    </>
                )}
            </p>

            {entry.changes.length === 0 ? null : (
                <ul className="grid gap-0.5 border-t border-line pt-2 text-copy-13">
                    {entry.changes.map((change) => (
                        <li key={change.attribute} className="text-ink-muted">
                            <span className="text-ink">{change.attribute}</span>:{' '}
                            {/* A personal field was recorded as having changed and never with its
                                values, so that is all there is to show. */}
                            {change.personal
                                ? t('platform::admin_audit.personal')
                                : t('platform::admin_audit.from_to', {
                                      from: change.from ?? '—',
                                      to: change.to ?? '—',
                                  })}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}
