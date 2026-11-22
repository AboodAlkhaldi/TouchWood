import { lazy, Suspense, useRef, useState } from 'react';
import { Bold, ImagePlus, Link2, List, Table } from 'lucide-react';
import { readContentMarks } from '@/components/content/marks';
import { TextareaField } from '@/components/Fields';
import { ContentBlocks } from '@/components/shop/ContentBlocks';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { BoxCheck } from '@/lib/use-checks';
import type { ContentImageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| The text editor of pages, posts and projects (content.md §1.2): shadcn's `Textarea` with the formatted text beside it
| as it is typed - under it on a phone, as Write and Preview tabs - Catalog's own pattern (catalog.md §9.7 P1). A row of
| shadcn `Button`s writes the marks around what is selected (Q10 (a)): Bold, Link, Image, Table, List. Image asks for
| the file and its description in a dialog loaded when pressed (editor-dialogs.tsx), never on first paint. The preview
| is the shop's own drawing of the blocks (ContentBlocks), read as the server reads them (marks.ts).
*/

const ImageDialog = lazy(() => import('./editor-dialogs').then((module) => ({ default: module.ImageDialog })));

type Props = {
    /** The box's name as posted: `body_ar`, `body_en`. */
    id: string;
    label: string;
    helper?: string;
    language: 'ar' | 'en';
    value: string;
    onChange: (value: string) => void;
    /** The body's images by ref, as their boxes show them. */
    images: Record<number, ContentImageData>;
    onImage: (ref: number, image: ContentImageData) => void;
    /** The upload kind and the store, for the Image dialog's upload box. */
    uploadKind: string;
    store: string;
    href: (link: string) => string;
    check?: BoxCheck;
};

/** The next free `image:N` of a body: after every ref it names and every file it holds. */
function nextRef(value: string, images: Record<number, ContentImageData>): number {
    const named = [...value.matchAll(/\(image:([0-9]{1,6})\)/g)].map((found) => Number(found[1]));

    return Math.max(0, ...named, ...Object.keys(images).map(Number)) + 1;
}

export function RichTextEditor({ id, label, helper, language, value, onChange, images, onImage, uploadKind, store, href, check }: Props) {
    const t = useTranslator();
    const box = useRef<HTMLTextAreaElement>(null);
    const [asking, setAsking] = useState(false);
    const dir = language === 'ar' ? 'rtl' : 'ltr';

    /** Writes the marks around the selection, or around `filler` where nothing is selected, and selects what to type next. */
    const wrap = (before: string, after: string, filler: string, select: 'words' | 'after' = 'words') => {
        const area = box.current;
        const [start, end] = area === null ? [value.length, value.length] : [area.selectionStart, area.selectionEnd];
        const words = value.slice(start, end) || filler;
        onChange(`${value.slice(0, start)}${before}${words}${after}${value.slice(end)}`);
        requestAnimationFrame(() => {
            area?.focus();
            const from = select === 'words' ? start + before.length : start + before.length + words.length + 2;
            const to = select === 'words' ? from + words.length : start + before.length + words.length + after.length - 1;
            area?.setSelectionRange(from, to);
        });
    };

    /** Puts a block on lines of its own at the cursor. */
    const block = (text: string) => {
        const at = box.current?.selectionEnd ?? value.length;
        const head = value.slice(0, at).replace(/\s+$/, '');
        const tail = value.slice(at).replace(/^\s+/, '');
        onChange(`${head}${head === '' ? '' : '\n\n'}${text}${tail === '' ? '' : `\n\n${tail}`}`);
    };

    /** Each selected line made a list item, or one item where nothing is selected. */
    const list = () => {
        const area = box.current;
        const start = value.lastIndexOf('\n', Math.max(0, (area?.selectionStart ?? value.length) - 1)) + 1;
        const end = area?.selectionEnd ?? value.length;
        const lines = value.slice(start, end) || t('content::admin_documents.editor.item');
        onChange(`${value.slice(0, start)}${lines.split('\n').map((line) => (line.startsWith('- ') ? line : `- ${line}`)).join('\n')}${value.slice(end)}`);
    };

    const tools = [
        { key: 'bold', icon: Bold, label: t('content::admin_documents.editor.bold'), act: () => wrap('**', '**', t('content::admin_documents.editor.bold_words')) },
        { key: 'link', icon: Link2, label: t('content::admin_documents.editor.link'), act: () => wrap('[', '](/)', t('content::admin_documents.editor.link_words'), 'after') },
        { key: 'image', icon: ImagePlus, label: t('content::admin_documents.editor.image'), act: () => setAsking(true) },
        { key: 'table', icon: Table, label: t('content::admin_documents.editor.table'), act: () => block(t('content::admin_documents.editor.table_marks')) },
        { key: 'list', icon: List, label: t('content::admin_documents.editor.list'), act: list },
    ];
    const preview = (
        <ContentBlocks
            blocks={readContentMarks(value)}
            image={(ref) => (images[ref] === undefined ? null : { src: images[ref].thumbnail, width: images[ref].width, height: images[ref].height })}
            href={href}
            dir={dir}
            lang={language}
        />
    );

    return (
        <div className="grid min-w-0 gap-2" data-test={`editor-${id}`}>
            <div className="flex flex-wrap gap-1" role="toolbar" aria-label={t('content::admin_documents.editor.marks', { field: label })}>
                {tools.map((tool) => (
                    <Button key={tool.key} type="button" variant="outline" size="sm" onClick={tool.act} data-test={`${id}-${tool.key}`}>
                        <tool.icon aria-hidden="true" />
                        {tool.label}
                    </Button>
                ))}
            </div>
            <Tabs defaultValue="write" className="min-w-0">
                <TabsList variant="line" className="lg:hidden">
                    <TabsTrigger value="write" data-test={`${id}-write`}>
                        {t('content::admin_documents.editor.write')}
                    </TabsTrigger>
                    <TabsTrigger value="preview" data-test={`${id}-preview-tab`}>
                        {t('content::admin_documents.editor.preview')}
                    </TabsTrigger>
                </TabsList>
                {/* Both drawn from the computer's width up, side by side; on a phone one at a time. */}
                <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                    <TabsContent value="write" forceMount className="min-w-0 data-[state=inactive]:hidden lg:data-[state=inactive]:block">
                        <TextareaField ref={box} id={id} dir={dir} lang={language} label={label} helper={helper} rows={14} check={check} value={value} onChange={(event) => onChange(toLatinDigits(event.target.value))} data-test={id} />
                    </TabsContent>
                    <TabsContent value="preview" forceMount className="material-small min-w-0 p-4 data-[state=inactive]:hidden lg:data-[state=inactive]:block" data-test={`${id}-preview`}>
                        {value.trim() === '' ? <p className="text-copy-14 text-ink-muted">{t('content::admin_documents.editor.preview_empty')}</p> : preview}
                    </TabsContent>
                </div>
            </Tabs>
            {asking ? (
                <Suspense fallback={null}>
                    <ImageDialog
                        kind={uploadKind}
                        store={store}
                        language={language}
                        onClose={() => setAsking(false)}
                        onInsert={(image, description) => {
                            const ref = nextRef(value, images);
                            onImage(ref, image);
                            block(`![${description.replace(/[[\]]/g, '')}](image:${ref})`);
                            setAsking(false);
                        }}
                    />
                </Suspense>
            ) : null}
        </div>
    );
}
