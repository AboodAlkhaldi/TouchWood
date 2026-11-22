import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { SignInLayout } from '@/layouts/SignInLayout';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Button } from '@/components/ui/button';
import { Field, FieldGroup } from '@/components/ui/field';
import { useTranslator } from '@/lib/t';
import type { RecoveryCodesPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| The ten recovery codes, shown once (access.md amendment 91, decision 4): after the app is set up and
| after "New Recovery Codes". The server keeps only their hashes, so this page is the only time they
| can be read - opened again, it goes on to where the person was going.
|
| Copied with Geist's Copy Button, or downloaded as a plain text file made here in the browser: the
| codes go nowhere else. Left to right and in a fixed-width face on an Arabic page too.
*/

type Props = RecoveryCodesPage;

export default function RecoveryCodes({ codes, continueTo }: Props) {
    const t = useTranslator();
    const text = `${t('access::account.codes_file_heading')}\n\n${codes.join('\n')}\n`;

    function download() {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
        link.download = 'recovery-codes.txt';
        link.click();
        URL.revokeObjectURL(link.href);
    }

    return (
        <SignInLayout title={t('access::account.codes_title')} subtitle={t('access::account.codes_body')}>
            <FieldGroup className="gap-5">
                <ol dir="ltr" className="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md bg-surface-sunken px-4 py-3 font-mono text-label-14 text-ink" data-test="recovery-codes">
                    {codes.map((code) => (
                        <li key={code} className="select-all">
                            {code}
                        </li>
                    ))}
                </ol>

                <div className="flex items-center justify-center gap-2">
                    <CopyButton text={codes.join('\n')} label={t('access::account.copy_codes')} />
                    <Button type="button" variant="outline" size="sm" onClick={download} data-test="download-codes">
                        <Download aria-hidden="true" />
                        {t('access::account.download_codes')}
                    </Button>
                </div>

                <Field>
                    <Button asChild className="w-full" data-test="codes-continue">
                        <Link href={continueTo}>{t('access::account.continue')}</Link>
                    </Button>
                </Field>
            </FieldGroup>
        </SignInLayout>
    );
}
