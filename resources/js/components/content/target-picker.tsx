import { useEffect, useRef, useState } from 'react';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useInLanguage } from '@/lib/in-language';
import { latestReads } from '@/lib/latest-read';
import { readJson } from '@/lib/live-read';
import { useTranslator } from '@/lib/t';
import type { Picked } from './campaign-prices';

/*
| A campaign price's picker (content.md §1.7 #4, W4b's Prices tab), loaded when it is asked: what the run's store sells -
| products and sizes found by a name or a code, 20 a page with Show More; its categories and brands switched on, all at
| once and filtered as typed. Read 300 ms after the last key; **an older answer is dropped** - aborted, and never taking a
| newer one's place - so "dra" answering after "drawer" shows nothing of "dra". Each added once. A named export: a
| default one would count as a page.
*/

type Answer = { targets: Picked[]; next: string | null };

export function TargetPicker({ run, kind, title, chosen, onPick, onClose }: { run: string; kind: string; title: string; chosen: string[]; onPick: (picked: Picked) => void; onClose: () => void }) {
    const t = useTranslator();
    const name = useInLanguage();
    const whole = kind === 'CATEGORY' || kind === 'BRAND';
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Picked[] | null>(null);
    const [next, setNext] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);
    // Each read numbered: only the latest one's answer is taken, whatever order the answers arrive in.
    const latest = useRef(latestReads()).current;

    const read = (typed: string, after: string | null, signal?: AbortSignal) => {
        const mine = latest.start();
        setLoading(true);

        return readJson<Answer>('/admin/content/campaigns/targets', { run, kind, q: whole ? null : typed, after }, signal).then((answer) => {
            if (signal?.aborted || !mine()) {
                return;
            }

            setLoading(false);
            setFailed(answer === null);

            if (answer !== null) {
                setFound((was) => (after === null ? answer.targets : [...(was ?? []), ...answer.targets]));
                setNext(answer.next);
            }
        });
    };

    useEffect(() => {
        const typed = query.trim();

        // Categories and brands come whole, once; products and sizes are asked for what is typed.
        if (!whole && typed === '') {
            latest.drop();
            setFound(null);
            setNext(null);
            setLoading(false);

            return;
        }

        if (whole && found !== null) {
            return;
        }

        const controller = new AbortController();
        const wait = setTimeout(() => void read(typed, null, controller.signal), whole ? 0 : 300);

        return () => {
            clearTimeout(wait);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- `read` and `found` are this effect's own state
    }, [query, run, kind, whole]);

    const typed = query.trim().toLocaleLowerCase();
    const shown = whole && found !== null ? found.filter((each) => typed === '' || `${each.nameAr} ${each.nameEn ?? ''}`.toLocaleLowerCase().includes(typed)) : (found ?? []);

    return (
        <PanelDialog open onOpenChange={(open) => (open ? undefined : onClose())} title={title} description={t('content::admin_campaigns.prices.searching', { kind: t(`content::admin_campaigns.prices.kind.${kind}`) })} busy={false} cancelLabel={t('content::admin_campaigns.prices.done')} confirm={null}>
            <div className="grid gap-3">
                <Input type="search" aria-label={t('content::admin_campaigns.prices.search')} placeholder={t('content::admin_campaigns.prices.search')} value={query} onChange={(event) => setQuery(event.target.value)} data-test="target-search" />
                {loading ? <Spinner /> : null}
                {failed ? <p className="text-copy-14 text-bad">{t('content::admin_campaigns.prices.search_failed')}</p> : null}
                {found !== null && shown.length === 0 && query.trim() !== '' ? <p className="text-copy-14 text-ink-muted">{t('content::admin_campaigns.prices.none_match', { query: query.trim() })}</p> : null}
                <ul className="grid max-h-72 gap-1 overflow-y-auto">
                    {shown.map((each) => {
                        const added = chosen.includes(each.id);

                        return (
                            <li key={each.id} className="flex min-w-0 items-center justify-between gap-2">
                                <span className="grid min-w-0">
                                    <span className="truncate text-copy-14 text-ink">{name(each.nameAr, each.nameEn ?? each.nameAr)}</span>
                                    {each.detail === null || each.detail === '' ? null : (
                                        <bdi dir="ltr" className="truncate text-copy-13 text-ink-muted">
                                            {each.detail}
                                        </bdi>
                                    )}
                                </span>
                                <Button type="button" size="sm" variant="outline" disabled={added} onClick={() => onPick(each)} data-test={`pick-${each.id}`}>
                                    {added ? t('content::admin_campaigns.prices.picked') : t('content::admin_campaigns.prices.pick')}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
                {next !== null && !whole ? (
                    <Button type="button" variant="outline" size="sm" disabled={loading} onClick={() => void read(query.trim(), next)} data-test="target-more">
                        {t('content::admin_campaigns.prices.more')}
                    </Button>
                ) : null}
            </div>
        </PanelDialog>
    );
}
