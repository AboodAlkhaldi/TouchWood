import { useForm } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ShopCard } from '@/components/ShopCard';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { VerifyEmailPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F4 - confirming an email address (frontend.md §3.6).
|
| It says three things and offers one: where the link went, how long it is good for, and what an
| unconfirmed address costs - browsing and a basket yes, ordering no. The offer is another link,
| which Access rate-limits; being refused for asking too often arrives as a form error, in Access's
| own words.
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
            <ShopCard title={t('access::auth.verify_title')}>
                <div className="grid gap-3 text-sm text-ink-muted">
                    {/* The address on its own line rather than inside the sentence: an email
                        address reads left to right, and one placed mid-sentence in Arabic has its
                        parts reordered on the screen (the account screen's own bug, 2026-09-24).
                        bdi keeps it whole wherever it sits. */}
                    <p>{t('access::auth.verify_subtitle')}</p>
                    <bdi dir="ltr" className="font-medium text-ink">
                        {email}
                    </bdi>

                    <p>{t('access::auth.verify_link_hours', { count: linkHours })}</p>
                    <p>{t('access::auth.verify_what_next')}</p>
                </div>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(link('storefront.account.verify-email.resend'));
                    }}
                    className="grid gap-4"
                >
                    <FormError />

                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                        data-test="resend-verification"
                        className="w-full"
                    >
                        {t('access::auth.verify_resend')}
                    </Button>
                </form>
            </ShopCard>
        </StorefrontLayout>
    );
}
