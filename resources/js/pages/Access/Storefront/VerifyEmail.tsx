import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { ShopCard } from '@/components/ShopCard';
import { Field, FieldGroup } from '@/components/ui/field';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { VerifyEmailPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F4 - confirming an email address (frontend.md §3.6), in the shop's card (shadcn's Card, §1.11)
| with Geist's type.
|
| It says three things and offers one: where the link went, how long it is good for, and what an
| unconfirmed address costs - browsing and a basket yes, ordering no. The offer is another link,
| which Access rate-limits; being refused for asking too often arrives as a form error, in Access's
| own words. It is the page's only button, so Geist's default, the main button (owner, 2026-10-04,
| from a picture).
|
| The address is shown because somebody who mistyped it at registration learns that here, rather
| than by waiting for a link that was never going anywhere.
*/

type Props = VerifyEmailPage;

export default function VerifyEmail({ email, linkHours }: Props) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({});

    return (
        <StorefrontLayout title={t('access::auth.verify_title')}>
            <ShopCard title={t('access::auth.verify_title')} subtitle={t('access::auth.verify_subtitle')}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.verify-email.resend'));
                    }}
                >
                    <FieldGroup className="gap-5">
                        {/* The address on its own line rather than inside the sentence: an email
                            address reads left to right, and one placed mid-sentence in Arabic has
                            its parts reordered on the screen (the account screen's own bug,
                            2026-09-24). bdi keeps it whole wherever it sits, and the line around
                            it keeps the page's direction, so on an Arabic page it starts on the
                            right like the sentences under it. */}
                        <div className="grid gap-3 text-copy-14 text-ink-muted">
                            <p className="text-label-14 font-medium text-ink">
                                <bdi dir="ltr">{email}</bdi>
                            </p>
                            <p>{t('access::auth.verify_link_hours', { count: linkHours })}</p>
                            <p>{t('access::auth.verify_what_next')}</p>
                        </div>

                        <FormError />

                        <Field>
                            <ActionButton
                                type="submit"
                                loading={form.processing}
                                data-test="resend-verification"
                                className="w-full"
                            >
                                {t('access::auth.verify_resend')}
                            </ActionButton>
                        </Field>
                    </FieldGroup>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
