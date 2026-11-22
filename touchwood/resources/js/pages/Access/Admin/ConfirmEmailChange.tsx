import { useForm } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Description } from '@/components/geist-only/Description';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { EmailChangePage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A8 - confirming a new address (frontend.md §3.1), on shadcn's login-02 form (§1.11).
|
| Opening the link changes nothing; pressing the button does. That is why this page exists at all
| rather than the link doing the work: a mail client that fetches every link it receives must not be
| able to change somebody's address.
|
| The address about to become the work email is shown as a fact (Geist's Description, on its own -
| its examples put no card around it), named, so the person reads what they are confirming before
| they press the one button that confirms it.
*/

type Props = EmailChangePage;

export default function ConfirmEmailChange({ token, newEmail }: Props) {
    const t = useTranslator();
    const form = useForm({});

    return (
        <SignInLayout title={t('access::auth.email_change_title')} subtitle={t('access::auth.email_change_subtitle')}>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/admin/email-change/${token}`);
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    <Description columns={1} items={[{ title: t('access::auth.email'), content: <bdi dir="ltr">{newEmail}</bdi> }]} />

                    <Field>
                        <ActionButton type="submit" loading={form.processing} className="w-full">
                            {t('access::auth.confirm_new_email')}
                        </ActionButton>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
