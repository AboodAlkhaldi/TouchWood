import { useForm, usePage } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F8 - the details tab (frontend.md §3.6).
|
| Their name and the language we write to them in, and that is the whole of what a customer may
| change about themselves. Three things are shown and cannot be changed, each saying so where it is
| rather than refusing afterwards: the email address (access.md §1.1), the kind of account, and the
| store they registered in.
|
| What is still missing before they can order is said here too, because this is the page they come
| to when something will not let them through.
*/

type Props = {
    account: CustomerAccountPage;
};

export function ProfileTab({ account }: Props) {
    const t = useTranslator();
    const link = useLink();
    const { shop } = usePage<SharedProps>().props;

    const form = useForm({
        first_name: account.firstName,
        last_name: account.lastName,
        locale: account.locale,
    });

    return (
        <div className="grid gap-6">
            {account.mayOrder ? null : <BeforeOrdering account={account} />}

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(link('storefront.account.profile'), { preserveScroll: true });
                }}
                className="grid gap-5"
            >
                <FormError />

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field
                        id="first_name"
                        label={t('access::account.first_name')}
                        error={form.errors.first_name}
                    >
                        <Input
                            id="first_name"
                            name="first_name"
                            autoComplete="given-name"
                            required
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="last_name"
                        label={t('access::account.last_name')}
                        error={form.errors.last_name}
                    >
                        <Input
                            id="last_name"
                            name="last_name"
                            autoComplete="family-name"
                            required
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                        />
                    </Field>
                </div>

                <Field
                    id="locale"
                    label={t('access::account.communication_language')}
                    hint={t('access::account.shop_communication_language_hint')}
                    error={form.errors.locale}
                >
                    {/* The languages the shop itself can be read in: they come with every page of
                        it, and which they are is Platform's to say, not this screen's. */}
                    <select
                        id="locale"
                        name="locale"
                        value={form.data.locale}
                        onChange={(event) => form.setData('locale', event.target.value)}
                        className="h-9 w-full rounded-md border border-line bg-surface px-3 text-sm text-ink"
                    >
                        {(shop?.languages ?? [account.locale]).map((language) => (
                            <option key={language} value={language}>
                                {t(`access::account.language.${language}`)}
                            </option>
                        ))}
                    </select>
                </Field>

                <Button
                    type="submit"
                    disabled={form.processing}
                    data-test="save-profile"
                    className="w-fit"
                >
                    {t('access::account.save')}
                </Button>
            </form>

            <div className="grid gap-4 border-t border-line pt-6">
                <Locked
                    label={t('access::account.shop_email')}
                    note={t('access::account.shop_email_locked')}
                    value={account.email}
                    ltr
                />

                <Locked
                    label={t('access::account.account_kind')}
                    note={t('access::account.account_kind_locked')}
                    value={t(
                        account.accountType === 'COMPANY'
                            ? 'access::auth.account_type_company'
                            : 'access::auth.account_type_individual',
                    )}
                />

                <Locked
                    label={t('access::account.home_store')}
                    note={t('access::account.home_store_hint')}
                    value={account.homeStore}
                />
            </div>
        </div>
    );
}

/**
 * Something about the account that is shown and cannot be changed, with the reason beside it.
 *
 * Said here rather than left for a refusal later: a field somebody can type into and then be told
 * about is worse than one that never invited them.
 */
function Locked({
    label,
    note,
    value,
    ltr = false,
}: {
    label: string;
    note: string;
    value: string;
    /** An email address reads left to right, whatever language the page is in. */
    ltr?: boolean;
}) {
    return (
        <div className="grid gap-0.5">
            <p className="text-sm font-medium text-ink">{label}</p>
            {ltr ? (
                <bdi dir="ltr" className="text-sm text-ink-muted">
                    {value}
                </bdi>
            ) : (
                <p className="text-sm text-ink-muted">{value}</p>
            )}
            <p className="text-xs text-ink-muted">{note}</p>
        </div>
    );
}

/**
 * What is still in the way of an order (access.md §1.2): the email, the phone, or both.
 *
 * A basket that refuses at the last step, with no way to see why beforehand, is the thing this
 * exists to prevent.
 */
function BeforeOrdering({ account }: { account: CustomerAccountPage }) {
    const t = useTranslator();

    return (
        <div
            data-test="before-ordering"
            className="grid gap-1 rounded-lg border border-warn/30 bg-warn-soft p-4"
        >
            <p className="text-sm font-medium text-warn">{t('access::account.before_ordering')}</p>

            <ul className="grid gap-0.5 text-sm text-ink-muted">
                {account.emailVerified ? null : <li>{t('access::account.missing_email')}</li>}
                {account.phoneVerified ? null : <li>{t('access::account.missing_phone')}</li>}
            </ul>
        </div>
    );
}
