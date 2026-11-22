import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { ActionButton } from '@/components/ActionButton';
import { CodeInput } from '@/components/CodeInput';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SignInAppPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The second step for whom the authenticator app is the method (access.md amendment 91), on the same
| shadcn parts as the SMS code screen (SignInCode): the app's code in shadcn's InputOTP, six boxes -
| every authenticator app shows six digits - and "Trust this browser" as there.
|
| "Use a recovery code instead" swaps the boxes for one text field: a recovery code is ten letters and
| digits, typed with or without its dash. The same form sends either, saying which.
*/

const APP_CODE_LENGTH = 6;

type Props = SignInAppPage;

export default function SignInApp({ trustHours }: Props) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery: false, trust_browser: false });

    const incomplete = recovery
        ? form.data.code.trim() === ''
            ? t('access::auth.recovery_code_incomplete')
            : undefined
        : form.data.code.length < APP_CODE_LENGTH
          ? t('access::auth.code_incomplete', { count: APP_CODE_LENGTH })
          : undefined;

    function switchTo(next: boolean) {
        setRecovery(next);
        form.setData({ code: '', recovery: next, trust_browser: form.data.trust_browser });
        form.clearErrors();
    }

    return (
        <SignInLayout title={t(recovery ? 'access::auth.recovery_title' : 'access::auth.app_title')} subtitle={t(recovery ? 'access::auth.recovery_subtitle' : 'access::auth.app_subtitle')}>
            <form
                onKeyDown={(event) => (event.key === 'Enter' && incomplete !== undefined ? form.setError('code', incomplete) : undefined)}
                onSubmit={(event) => {
                    event.preventDefault();

                    if (incomplete !== undefined) {
                        form.setError('code', incomplete);

                        return;
                    }

                    form.post('/admin/sign-in/app');
                }}
            >
                <FieldGroup className="gap-5">
                    <FormError />

                    {recovery ? (
                        <TextField
                            id="recovery_code"
                            name="code"
                            label={t('access::auth.recovery_code_label')}
                            error={form.errors.code}
                            autoComplete="off"
                            autoCapitalize="none"
                            spellCheck={false}
                            dir="ltr"
                            inputClassName="tw-figure"
                            autoFocus
                            value={form.data.code}
                            onChange={(event) => {
                                form.setData('code', event.target.value);
                                form.clearErrors('code');
                            }}
                            data-test="recovery-code"
                        />
                    ) : (
                        <CodeInput
                            id="code"
                            label={t('access::auth.app_code_label')}
                            length={APP_CODE_LENGTH}
                            value={form.data.code}
                            onChange={(code) => {
                                form.setData('code', code);
                                form.clearErrors('code');
                            }}
                            error={form.errors.code}
                            autoFocus
                        />
                    )}

                    <Field orientation="horizontal">
                        <Checkbox
                            id="trust_browser"
                            checked={form.data.trust_browser}
                            onCheckedChange={(checked) => form.setData('trust_browser', checked === true)}
                            className="border-ink-subtle"
                        />
                        <FieldLabel htmlFor="trust_browser" className="text-label-14 font-normal text-ink">
                            {t('access::auth.trust_browser', { hours: figure(locale, trustHours) })}
                        </FieldLabel>
                    </Field>

                    <Field>
                        <ActionButton type="submit" loading={form.processing} disabledReason={incomplete} className="w-full">
                            {t('access::auth.confirm')}
                        </ActionButton>

                        <Button type="button" variant="ghost" onClick={() => switchTo(!recovery)} data-test="switch-code-kind">
                            {t(recovery ? 'access::auth.use_app_code' : 'access::auth.use_recovery_code')}
                        </Button>
                    </Field>
                </FieldGroup>
            </form>
        </SignInLayout>
    );
}
