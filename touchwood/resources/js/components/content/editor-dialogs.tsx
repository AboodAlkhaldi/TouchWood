import { useState } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { ImageBox } from '@/components/content/image-box';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { useTranslator } from '@/lib/t';
import type { ContentImageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| The editor's Image (content.md §1.2): the file, uploaded at once under the item's own permission, and its description,
| required per language. Insert writes `![description](image:N)` on a line of its own. Loaded when Image is pressed.
*/

export function ImageDialog({ kind, store, language, onInsert, onClose }: { kind: string; store: string; language: 'ar' | 'en'; onInsert: (image: ContentImageData, description: string) => void; onClose: () => void }) {
    const t = useTranslator();
    const [image, setImage] = useState<ContentImageData | null>(null);
    const [description, setDescription] = useState('');
    const reason = image === null ? t('content::admin_documents.editor.image_first') : description.trim() === '' ? t('content::admin_documents.editor.description_first') : undefined;

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t('content::admin_documents.editor.image_title')}
            description={t('content::admin_documents.editor.image_body')}
            busy={false}
            confirm={
                <ActionButton type="button" disabledReason={reason} onClick={() => image !== null && onInsert(image, description.trim())} data-test="insert-image">
                    {t('content::admin_documents.editor.insert')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <ImageBox id="editor-image" label={t('content::admin_documents.editor.image')} kind={kind} store={store} image={image} onChange={setImage} />
                <TextField
                    id="editor-image-description"
                    dir={language === 'ar' ? 'rtl' : 'ltr'}
                    lang={language}
                    label={t('content::admin_documents.editor.description')}
                    helper={t('content::admin_documents.editor.description_helper')}
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    data-test="image-description"
                />
            </div>
        </PanelDialog>
    );
}
