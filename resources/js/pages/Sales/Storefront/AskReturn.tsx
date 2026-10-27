import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { O, plural, useLocale } from '@/components/sales/shop';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FieldDescription } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelectOption } from '@/components/ui/native-select';
import { AccountLayout } from '@/layouts/AccountLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { AskReturnPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| S9 - ask for a return (sales.md §4.1), its own page (R1-31): each line with the pieces that may still be
| returned, how many of them; a reason from the list or "Other" with words; up to three photos, optional
| (R2-23). Sent, it opens the return.
*/

const REASONS = ['DAMAGED', 'WRONG_ITEM', 'NOT_AS_DESCRIBED', 'CHANGED_MIND', 'OTHER'];

export default function AskReturn(page: AskReturnPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const [pieces, setPieces] = useState<Record<string, number>>({});
    const [reason, setReason] = useState('');
    const [other, setOther] = useState('');
    const [photos, setPhotos] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const chosen = Object.entries(pieces).filter(([, count]) => count > 0);
    const missing = chosen.length === 0 ? t(`${O}.ask.none_chosen`) : reason === '' ? t(`${O}.ask.choose`) : reason === 'OTHER' && other.trim() === '' ? t(`${O}.ask.other`) : undefined;

    return (
        <AccountLayout title={t(`${O}.ask.title`)} subtitle={t(`${O}.ask.subtitle`, { number: page.number })} page="sales.orders">
            <div className="grid min-w-0 content-start gap-4">
                <FormError />
                <Card className="material-base gap-3 border-0 py-4">
                    <CardContent className="grid gap-4 px-4">
                        <h2 className="text-heading-16 text-ink">{t(`${O}.ask.lines`)}</h2>
                        <ul className="grid gap-3" data-test="return-lines">
                            {page.lines.map((line) => {
                                const count = pieces[line.id] ?? 0;
                                const set = (next: number) => setPieces((now) => ({ ...now, [line.id]: Math.max(0, Math.min(line.returnable, next)) }));

                                return (
                                    <li key={line.id} className="flex flex-wrap items-center justify-between gap-3" data-test={`return-line-${line.code}`}>
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="text-label-14 text-ink">{line.name}</span>
                                            {line.values === '' ? null : <span className="text-copy-13 text-ink-muted">{line.values}</span>}
                                            <span className="text-copy-13 text-ink-muted">{plural(t, locale, `${O}.ask.may`, line.returnable)}</span>
                                        </span>
                                        <span className="flex items-center gap-1" role="group" aria-label={t(`${O}.ask.pieces`, { name: line.name })}>
                                            <Button type="button" variant="outline" size="icon" disabled={count === 0} onClick={() => set(count - 1)} aria-label={t(`${O}.ask.less`)} data-test="fewer">
                                                <Minus aria-hidden="true" />
                                            </Button>
                                            <output className="tw-figure min-w-10 text-center text-label-14 text-ink" aria-live="polite" data-test="count">
                                                {figure(locale, count)}
                                            </output>
                                            <Button type="button" variant="outline" size="icon" disabled={count >= line.returnable} onClick={() => set(count + 1)} aria-label={t(`${O}.ask.more`)} data-test="more">
                                                <Plus aria-hidden="true" />
                                            </Button>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>

                        <SelectField id="return-reason" label={t(`${O}.ask.reason`)} value={reason} onChange={(event) => setReason(event.target.value)} data-test="return-reason">
                            <NativeSelectOption value="">{t(`${O}.ask.choose`)}</NativeSelectOption>
                            {REASONS.map((each) => (
                                <NativeSelectOption key={each} value={each}>
                                    {t(`${O}.ask.reasons.${each}`)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                        {reason === 'OTHER' ? <TextField id="return-other" label={t(`${O}.ask.other`)} value={other} maxLength={500} onChange={(event) => setOther(event.target.value)} data-test="return-other" /> : null}

                        <div className="grid gap-2">
                            <Label htmlFor="return-photos">{t(`${O}.ask.photos`)}</Label>
                            <Input
                                id="return-photos"
                                type="file"
                                multiple
                                accept="image/jpeg,image/png"
                                aria-describedby="return-photos-helper"
                                onChange={(event) => setPhotos(Array.from(event.target.files ?? []).slice(0, 3))}
                                data-test="return-photos"
                            />
                            <FieldDescription id="return-photos-helper">{t(`${O}.ask.photos_helper`)}</FieldDescription>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <ActionButton
                                loading={busy}
                                disabledReason={missing}
                                onClick={() =>
                                    router.post(
                                        link('storefront.sales.order.request-return', { number: page.number }),
                                        // As form data only with photos to send (Inertia's own choice); the words only with Other.
                                        { reason, other: reason === 'OTHER' ? other : '', lines: chosen.map(([orderLineId, quantity]) => ({ orderLineId, quantity })), ...(photos.length > 0 ? { photos } : {}) } as never,
                                        { onStart: () => setBusy(true), onFinish: () => setBusy(false) },
                                    )
                                }
                                data-test="send-return"
                            >
                                {t(`${O}.ask.send`)}
                            </ActionButton>
                            <Link href={link('storefront.sales.order', { number: page.number })} className="text-copy-14 text-ink-muted underline underline-offset-4">
                                {t(`${O}.return.to_order`)}
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AccountLayout>
    );
}
