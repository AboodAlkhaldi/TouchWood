import type { ReactNode } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { Button, Description, Input, Note, Select } from '@/components/geist';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type { CustomerAccountPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| F8 - the details tab (frontend.md §3.6), on Geist's fields, Description and Note (1.10).
|
| Their name and the language we write to them in, and that is the whole of what a customer may
| change about themselves. Three things are shown and cannot be changed, each saying so where it is
| rather than refusing afterwards: the email address (access.md §1.1), the kind of account, and the
| store they registered in. They are facts, not fields, so Geist's Description lists them.
|
| What is still missing before they can order is said here too, because this is the page they come
| to when something will not let them through.
|
| The tab sits inside the account layout's own card, so its sections are headings and a rule
| rather than Geist Fieldsets: a card inside a card says the page is built wrong.
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
        locale: account.communicationLocale,
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
                    <Input
                        id="first_name"
                        name="first_name"
                        label={t('access::account.first_name')}
                        error={form.errors.first_name}
                        autoComplete="given-name"
                        required
                        value={form.data.first_name}
                        onChange={(event) => form.setData('first_name', event.target.value)}
                    />

                    <Input
                        id="last_name"
                        name="last_name"
                        label={t('access::account.last_name')}
                        error={form.errors.last_name}
                        autoComplete="family-name"
                        required
                        value={form.data.last_name}
                        onChange={(event) => form.setData('last_name', event.target.value)}
                    />
                </div>

                {/* The languages the shop itself can be read in: they come with every page of it,
                    and which they are is Platform's to say, not this screen's. */}
                <Select
                    id="locale"
                    name="locale"
                    label={t('access::account.communication_language')}
                    helper={t('access::account.shop_communication_language_hint')}
                    error={form.errors.locale}
                    value={form.data.locale}
                    onChange={(event) => form.setData('locale', event.target.value)}
                >
                    {(shop?.languages ?? [account.communicationLocale]).map((language) => (
                        <option key={language} value={language}>
                            {t(`access::account.language.${language}`)}
                        </option>
                    ))}
                </Select>

                <Button typeName="submit" loading={form.processing} data-test="save-profile" className="w-fit">
                    {t('access::account.save')}
                </Button>
            </form>

            <div className="border-t border-line pt-6">
                <Description
                    columns={1}
                    items={[
                        {
                            title: t('access::account.shop_email'),
                            // An email address reads left to right, whatever language the page is in.
                            content: (
                                <Locked note={t('access::account.shop_email_locked')}>
                                    <bdi dir="ltr">{account.email}</bdi>
                                </Locked>
                            ),
                        },
                        {
                            title: t('access::account.account_kind'),
                            content: (
                                <Locked note={t('access::account.account_kind_locked')}>
                                    {t(
                                        account.accountType === 'COMPANY'
                                            ? 'access::auth.account_type_company'
                                            : 'access::auth.account_type_individual',
                                    )}
                                </Locked>
                            ),
                        },
                        {
                            title: t('access::account.home_store'),
                            content: <Locked note={t('access::account.home_store_hint')}>{account.homeStore}</Locked>,
                        },
                    ]}
                />
            </div>
        </div>
    );
}

/**
 * The value of something that is shown and cannot be changed, with the reason under it.
 *
 * Said here rather than left for a refusal later: a field somebody can type into and then be told
 * about is worse than one that never invited them. The reason stays on the page rather than in a
 * tooltip, because the person who needs it is the one who did not think to hover.
 */
function Locked({ note, children }: { note: string; children: ReactNode }) {
    return (
        <span className="grid gap-0.5">
            <span>{children}</span>
            <span className="text-copy-13 text-ink-muted">{note}</span>
        </span>
    );
}

/**
 * What is still in the way of an order (access.md §1.2): the email, the phone, or both - as Geist's
 * warning Note, a consequence to see to, sitting where the person comes to look.
 *
 * A basket that refuses at the last step, with no way to see why beforehand, is the thing this
 * exists to prevent.
 */
function BeforeOrdering({ account }: { account: CustomerAccountPage }) {
    const t = useTranslator();

    return (
        <Note variant="warning" label={t('access::account.before_ordering')} data-test="before-ordering">
            <ul className="mt-1 grid gap-0.5">
                {account.emailVerified ? null : <li>{t('access::account.missing_email')}</li>}
                {account.phoneVerified ? null : <li>{t('access::account.missing_phone')}</li>}
            </ul>
        </Note>
    );
}
