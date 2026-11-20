import { PolicySentence } from '@/components/shop/PolicySentence';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';

/*
| The store's Company Terms above Send, while it has them published (flags-2026-11.md S3.6, Q2): "I have read and agree
| to the Company Terms.", the name a link opening their page in a new tab. An acknowledgment, so a checkbox with its whole
| sentence (Geist's Checkbox rules), checked when Send is pressed: unticked, it says so under it. Its own file, loaded
| only for a store that has the terms, so the form's page keeps within its budget (frontend.md §5).
*/

export function CompanyTermsTick({ href, ticked, refused, onTicked }: { href: string; ticked: boolean; refused: boolean; onTicked: (ticked: boolean) => void }) {
    const t = useTranslator();

    return (
        <>
            <Field orientation="horizontal" data-invalid={refused ? true : undefined}>
                <Checkbox
                    id="company-terms"
                    data-test="company-terms"
                    checked={ticked}
                    onCheckedChange={(checked) => onTicked(checked === true)}
                    aria-invalid={refused ? true : undefined}
                    aria-describedby={refused ? 'company-terms-error' : undefined}
                    className="border-ink-subtle"
                />
                <FieldLabel htmlFor="company-terms" className="block text-copy-14 font-normal text-ink" data-test="company-terms-sentence">
                    <PolicySentence text={t('b2b::company.company_terms')} names={{ terms: { label: t('b2b::company.company_terms_name'), href, test: 'company-terms-link' } }} />
                </FieldLabel>
            </Field>
            {refused ? (
                <FieldError id="company-terms-error" data-test="company-terms-error">
                    {t('b2b::company.company_terms_needed')}
                </FieldError>
            ) : null}
        </>
    );
}
