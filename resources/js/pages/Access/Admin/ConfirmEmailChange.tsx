import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { Button } from '@/components/ui/button';
import { FormError } from '@/components/FormError';
import { useTranslator } from '@/lib/t';
import type { EmailChangePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A8 - confirming a new address (frontend.md §3.1).
|
| Opening the link changes nothing; pressing the button does. That is why this page exists at all
| rather than the link doing the work: a mail client that fetches every link it receives must not be
| able to change somebody's address.
*/

type Props = EmailChangePage;

export default function ConfirmEmailChange({ token, newEmail }: Props) {
    const t = useTranslator();
    const form = useForm({});

    return (
        <SignInLayout
            title={t('access::auth.email_change_title')}
            subtitle={t('access::auth.email_change_subtitle')}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/admin/email-change/${token}`);
                }}
                className="grid gap-5"
            >
                <FormError />

                <p
                    className="rounded-md border border-line bg-surface-sunken px-4 py-3 text-sm"
                    dir="ltr"
                >
                    {newEmail}
                </p>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {t('access::auth.confirm')}
                </Button>
            </form>
        </SignInLayout>
    );
}
