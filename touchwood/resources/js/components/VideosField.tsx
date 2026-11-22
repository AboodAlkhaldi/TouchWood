import { useState } from 'react';
import { X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { SortableList } from '@/components/content/sortable';
import { TextField } from '@/components/Fields';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { toLatinDigits } from '@/lib/digits';
import { useChecks, type Checks } from '@/lib/use-checks';
import { youTubeThumbnail } from '@/lib/youtube';
import { youTubeId } from '@/lib/youtube-id';

/*
| YouTube videos in the panel (flags-2026-11.md S4.4): a product's Photos tab and a project's form. A link is pasted
| and checked as it is typed - only a link to one YouTube video is taken ("Paste a YouTube link."), each video once,
| at most `max` - then the list is dragged into order, each video with a title per language, and removed. Each row
| shows YouTube's own thumbnail. The server reads the links again (Platform's YouTubeLink); this saves a round trip.
|
| It draws, its owner keeps: the list and its titles' checks are the owner's, so a project's titles are held to its
| Publish and a product's are saved as its Photos are. Each owner fetches it once the page is drawn (JavaScriptBudgetTest):
| nothing of it is first paint but its legend.
*/

export type PanelVideo = { videoId: string; titleAr: string; titleEn: string };

/** Every word, written out whole by the page that draws it (TranslationKeysTest). */
export type VideoWords = {
    legend: string;
    helper: string;
    link: string;
    linkHelper: string;
    add: string;
    titleAr: string;
    titleEn: string;
    none: string;
    max: string;
    /** The rules' own sentences, as translation keys: not a YouTube video's link, a video already listed. */
    pasteKey: string;
    onceKey: string;
    video: (number: number) => string;
    removeNamed: (name: string) => string;
};

export function VideosField({
    videos,
    onChange,
    onTitleLeave,
    titleBox,
    words,
    checks,
    errors,
    max,
    busy = false,
    reason,
    testPrefix = 'video',
}: {
    videos: PanelVideo[];
    /** The list as it now is; `added` when a video was just added, so its owner may save at once. */
    onChange: (videos: PanelVideo[], how: 'added' | 'moved' | 'removed' | 'typed') => void;
    /** A title box left: the product saves then. */
    onTitleLeave?: () => void;
    /** The id of a video's title box, as the owner's checks name it: by the video, so a message stays with its video when the list is moved. */
    titleBox: (language: 'ar' | 'en', videoId: string) => string;
    words: VideoWords;
    checks: Checks;
    errors: Record<string, string | undefined>;
    max: number;
    busy?: boolean;
    /** Why the reader may not change them, or undefined. */
    reason?: string;
    testPrefix?: string;
}) {
    const [link, setLink] = useState('');
    // A fresh box after each video is added: its check starts again.
    const [round, setRound] = useState(0);
    const taken = videos.map((video) => video.videoId);
    const adding = useChecks(
        [
            {
                id: `${testPrefix}-link`,
                label: words.link,
                value: link,
                rules: { required: true, own: (text) => (youTubeId(text) === null ? words.pasteKey : taken.includes(youTubeId(text) ?? '') ? words.onceKey : null) },
            },
        ],
        round,
    );
    const full = videos.length >= max;
    // Add Video, by its button or by Enter in the link box - never the form's own Enter, which would publish a project.
    const add = () => {
        if (busy) {
            return;
        }

        adding.submit(() => {
            const id = youTubeId(link);

            if (id !== null) {
                onChange([...videos, { videoId: id, titleAr: '', titleEn: '' }], 'added');
                setLink('');
                setRound((was) => was + 1);
            }
        });
    };
    const title = (index: number, language: 'ar' | 'en', value: string) => onChange(videos.map((video, at) => (at === index ? { ...video, [language === 'ar' ? 'titleAr' : 'titleEn']: toLatinDigits(value) } : video)), 'typed');
    const serverError = errors.videos ?? errors.video_title_ar ?? errors.video_title_en;

    return (
        <FieldSet className="min-w-0" data-test={`${testPrefix}s`}>
            <FieldLegend variant="label">{words.legend}</FieldLegend>
            <FieldDescription>{words.helper}</FieldDescription>
            {videos.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test={`${testPrefix}s-none`}>
                    {words.none}
                </p>
            ) : (
                <SortableList
                    testPrefix={testPrefix}
                    disabled={busy || reason !== undefined}
                    items={videos.map((video, index) => ({
                        id: video.videoId,
                        label: words.video(index + 1),
                        content: (
                            <div className="grid min-w-0 flex-1 gap-3 py-1 sm:grid-cols-[7rem_minmax(0,1fr)_minmax(0,1fr)] sm:items-start">
                                <img src={youTubeThumbnail(video.videoId)} alt="" loading="lazy" className="aspect-video w-28 rounded-md bg-surface-sunken object-cover" data-test={`${testPrefix}-thumb`} />
                                {(['ar', 'en'] as const).map((language) => (
                                    <TextField
                                        key={language}
                                        id={titleBox(language, video.videoId)}
                                        dir={language === 'ar' ? 'rtl' : 'ltr'}
                                        lang={language}
                                        label={`${language === 'ar' ? words.titleAr : words.titleEn} · ${words.video(index + 1)}`}
                                        value={language === 'ar' ? video.titleAr : video.titleEn}
                                        disabled={reason !== undefined}
                                        onChange={(event) => title(index, language, event.target.value)}
                                        onBlur={() => onTitleLeave?.()}
                                        check={checks.box(titleBox(language, video.videoId))}
                                        data-test={`${testPrefix}-title-${language}-${index}`}
                                    />
                                ))}
                            </div>
                        ),
                        extra:
                            reason === undefined ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    disabled={busy}
                                    aria-label={words.removeNamed(words.video(index + 1))}
                                    onClick={() => onChange(videos.filter((each) => each.videoId !== video.videoId), 'removed')}
                                    data-test={`${testPrefix}-remove-${index}`}
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            ) : undefined,
                    }))}
                    onChange={(ids) => onChange(ids.flatMap((id) => videos.filter((video) => video.videoId === id)), 'moved')}
                />
            )}
            {serverError ? <FieldError data-test={`${testPrefix}s-error`}>{serverError}</FieldError> : null}
            {reason !== undefined ? null : full ? (
                <p className="text-copy-14 text-ink-muted" data-test={`${testPrefix}s-full`}>
                    {words.max}
                </p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                    <TextField
                        key={round}
                        id={`${testPrefix}-link`}
                        // Text, not `url`: a link pasted without https:// is the server's to read, not the browser's to refuse.
                        type="text"
                        dir="ltr"
                        inputMode="url"
                        autoComplete="off"
                        label={words.link}
                        helper={words.linkHelper}
                        value={link}
                        onChange={(event) => setLink(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                add();
                            }
                        }}
                        check={adding.box(`${testPrefix}-link`)}
                        data-test={`${testPrefix}-link`}
                    />
                    <ActionButton
                        type="button"
                        variant="outline"
                        className="sm:mt-6"
                        loading={busy}
                        disabledReason={adding.reason}
                        onClick={add}
                        data-test={`${testPrefix}-add`}
                    >
                        {words.add}
                    </ActionButton>
                </div>
            )}
        </FieldSet>
    );
}
