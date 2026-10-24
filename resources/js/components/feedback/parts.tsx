import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { TextareaField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Choice } from '@/components/promotions/fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { ProductCardData, ReasonData, ShopAuthorData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| The parts Feedback's Support screens share (feedback.md §4.1 F1–F3): a product's cell, the stars, an
| author's public name, and the dialog that takes an optional reason or an answer.
*/

const W = 'feedback::admin_support';

/** A product as a cell: its thumbnail and name, a link to its shop page, and what stops it being sold. */
export function ProductCell({ product }: { product: ProductCardData }) {
    const t = useTranslator();

    return (
        <div className="flex min-w-48 items-center gap-3">
            {product.photo === null ? (
                <span className="size-10 shrink-0 rounded-[var(--tw-radius)] bg-surface-sunken" aria-hidden="true" />
            ) : (
                <img src={product.photo} alt="" className="size-10 shrink-0 rounded-[var(--tw-radius)] object-cover" />
            )}
            <div className="grid min-w-0 gap-1">
                {product.href === null ? (
                    <span className="truncate text-label-14 text-ink">{product.name}</span>
                ) : (
                    <a href={product.href} target="_blank" rel="noreferrer" className="truncate text-label-14 text-ink underline-offset-2 hover:underline">
                        {product.name}
                    </a>
                )}
                {product.removed ? (
                    <Badge className={tone('gray-subtle')}>{t(`${W}.item.removed`)}</Badge>
                ) : product.archived ? (
                    <Badge className={tone('amber-subtle')}>{t(`${W}.item.archived`)}</Badge>
                ) : !product.sold ? (
                    <Badge className={tone('gray-subtle')}>{t(`${W}.item.not_sold`)}</Badge>
                ) : null}
            </div>
        </div>
    );
}

/** Five stars, the first `rating` filled; read as "4 out of 5 stars". */
export function Stars({ rating }: { rating: number }) {
    const t = useTranslator();

    return (
        <span className="inline-flex items-center gap-0.5" role="img" aria-label={t(`${W}.item.stars`, { rating: String(rating) })}>
            {[1, 2, 3, 4, 5].map((n) => (
                <Star key={n} aria-hidden="true" className={`size-4 ${n <= rating ? 'fill-amber-400 text-amber-400' : 'text-ink-subtle'}`} />
            ))}
        </span>
    );
}

/** An author's public name (§1.1): "Sara A.", or "Deleted customer". */
export function Author({ author }: { author: ShopAuthorData }) {
    const t = useTranslator();

    return author.deleted ? <span className="text-ink-muted">{t(`${W}.item.deleted_customer`)}</span> : <>{t(`${W}.item.author`, { first: author.firstName, initial: author.lastInitial })}</>;
}

/** The language box (§4.2 B3): preset to the language worked out from the text; staff may switch it. */
export function LanguageBox({ id, value, onChange }: { id: string; value: string; onChange: (locale: string) => void }) {
    const t = useTranslator();

    return (
        <Choice
            name={id}
            legend={t(`${W}.language.label`)}
            value={value}
            onChange={onChange}
            reason={t(`${W}.language.hint`)}
            options={[
                { value: 'ar', label: t(`${W}.language.ar`) },
                { value: 'en', label: t(`${W}.language.en`) },
            ]}
        />
    );
}

/**
 * The dialog behind Reject…, Unpublish…, Answer… and Correct Answer…, one item's or many's: one box - an
 * optional reason (1–500), with the ready reasons to pick from in the item's language (§4.2 B4), or the
 * answer (1–2,000) with its language box (B3) - posted to `action` with `extra`.
 */
export function DecisionDialog({
    open,
    onOpenChange,
    title,
    description,
    action,
    field,
    initial = '',
    confirm,
    destructive = false,
    reasons = [],
    language,
    extra = {},
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    action: string;
    field: 'reason' | 'answer';
    initial?: string;
    confirm: string;
    destructive?: boolean;
    reasons?: ReasonData[];
    /** The item's language: the ready reasons' language, and - answering - the language box's preset. */
    language?: string;
    extra?: Record<string, unknown>;
}) {
    const t = useTranslator();
    const [text, setText] = useState(initial);
    const [locale, setLocale] = useState(language ?? 'ar');
    const [busy, setBusy] = useState(false);
    const [refusal, setRefusal] = useState<string | undefined>(undefined);
    const answer = field === 'answer';
    const checks = useChecks([{ id: field, label: t(`${W}.dialog.${field}`), value: text, rules: { required: answer, length: { max: answer ? 2000 : 500 } } }], open);
    const readyText = (reason: ReasonData) => (language === 'en' ? reason.textEn : reason.textAr);

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setText(initial);
                    setRefusal(undefined);
                }
                onOpenChange(next);
            }}
            destructive={destructive}
            title={title}
            description={description}
            busy={busy}
            confirm={
                <ActionButton
                    type="button"
                    variant={destructive ? 'destructive' : 'default'}
                    loading={busy}
                    disabledReason={checks.reason}
                    data-test="confirm-decision"
                    onClick={() =>
                        checks.submit(() =>
                            router.post(action, { ...extra, [field]: text, ...(answer ? { locale } : {}) }, {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => onOpenChange(false),
                                onError: (errors) => setRefusal(errors[field] ?? errors.form),
                            }),
                        )
                    }
                >
                    {confirm}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {answer ? <LanguageBox id="answer-language" value={locale} onChange={setLocale} /> : null}
                {!answer && reasons.length > 0 ? (
                    <div className="grid gap-2" data-test="ready-reasons">
                        <p className="text-label-13 text-ink">{t(`${W}.ready.label`)}</p>
                        <div className="flex flex-wrap gap-2">
                            {reasons.map((reason) => (
                                <Button
                                    key={reason.id}
                                    type="button"
                                    size="sm"
                                    variant={text === readyText(reason) ? 'default' : 'outline'}
                                    aria-pressed={text === readyText(reason)}
                                    aria-label={t(`${W}.ready.use`, { reason: readyText(reason) })}
                                    onClick={() => setText(readyText(reason))}
                                    data-test={`ready-${reason.id}`}
                                >
                                    <span dir="auto">{readyText(reason)}</span>
                                </Button>
                            ))}
                        </div>
                    </div>
                ) : null}
                <TextareaField
                    id={field}
                    label={t(`${W}.dialog.${field}`)}
                    helper={answer ? undefined : t(`${W}.dialog.reason_hint`)}
                    dir="auto"
                    rows={answer ? 6 : 3}
                    required={answer}
                    check={checks.box(field, refusal)}
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                />
            </div>
        </PanelDialog>
    );
}
