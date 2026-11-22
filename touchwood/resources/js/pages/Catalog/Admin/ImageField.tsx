import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslator } from '@/lib/t';

/*
| A module of its own, not in parts.tsx: only the brands' and the categories' dialogs take a photo, so
| no other page loads its file input and checkbox (frontend.md §5's page budget).
*/

/**
 * A photo chosen for upload (P5): the one it has, a file to replace it, or Remove. Platform checks
 * the file's type and size; a refusal comes back under the field.
 */
export function ImageField({
    id,
    label,
    held,
    current,
    onFile,
    remove,
    onRemove,
    error,
}: {
    id: string;
    label: string;
    /** Whether it has a photo now - which may not have its thumbnail yet, its sizes still being made. */
    held: boolean;
    /** The photo it has now, as a thumbnail's address, once its sizes are ready. */
    current: string | null;
    onFile: (file: File | null) => void;
    remove: boolean;
    onRemove: (remove: boolean) => void;
    error?: string;
}) {
    const t = useTranslator();

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex items-center gap-3">
                {current !== null && !remove ? <img src={current} alt="" className="size-12 rounded-md border border-line object-contain" /> : null}
                <Input
                    id={id}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    onChange={(event) => onFile(event.target.files?.[0] ?? null)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={`${id}-helper${error ? ` ${id}-error` : ''}`}
                    data-test={id}
                />
            </div>
            <FieldDescription id={`${id}-helper`}>{t('catalog::admin.image.helper')}</FieldDescription>
            {held ? (
                <div className="flex items-center gap-2">
                    <Checkbox id={`${id}-remove`} checked={remove} onCheckedChange={(on) => onRemove(on === true)} data-test={`${id}-remove`} />
                    <Label htmlFor={`${id}-remove`} className="text-copy-14">
                        {t('catalog::admin.image.remove')}
                    </Label>
                </div>
            ) : null}
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
