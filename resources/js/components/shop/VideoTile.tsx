import { useState, type ReactNode } from 'react';
import { cn } from 'cn';
import { youTubePlayer, youTubeThumbnail } from '@/lib/youtube';

/*
| A YouTube video in the shop's galleries (flags-2026-11.md S4.4): before it is played, a tile - YouTube's own thumbnail
| (Q3: an image from Google's servers, no cookie), a play mark and the title - and nothing else from YouTube. Pressing
| it opens the player in its place, from youtube-nocookie.com, starting at once. The player carries its own referrer
| policy: the site's `same-origin` sends YouTube no referrer, and its player then refuses to play.
|
| Written small on purpose, for the pages' budgets (JavaScriptBudgetTest): no icon set, the play mark is one path. It
| merges its classes with `cn` as every drawn part does - left out, the bundler split the JSX runtime from `cn` and
| every page's chunks grew by an import each (+0.6 KB a page, measured 2026-11-21).
*/

export type ShopVideo = { id: string; title: string | null };

/** The thumbnail and the play mark, for a tile or for a row's button that only chooses the video. */
export function VideoPicture({ video, small = false }: { video: ShopVideo; small?: boolean }) {
    return (
        <span className="absolute inset-0 grid place-items-center bg-black" data-test="video-picture">
            <img src={youTubeThumbnail(video.id)} alt="" loading="lazy" className="absolute inset-0 size-full object-cover" data-test="video-thumbnail" />
            <svg viewBox="0 0 24 24" aria-hidden="true" className={cn('relative rounded-full bg-black/70 fill-white p-[0.3em] ps-[0.4em]', small ? 'size-7' : 'size-14')}>
                <path d="M7 4.5v15l12.5-7.5z" />
            </svg>
            {!small && video.title ? <span className="absolute inset-x-0 bottom-0 line-clamp-2 bg-black/60 px-3 py-2 text-start text-label-14 break-words text-white" data-test="video-title">{video.title}</span> : null}
        </span>
    );
}

/**
 * The tile: pressed, the player in its place. `label` is what Play is called aloud, the title after it; `onPress`, when
 * given, is called instead - a gallery that plays its videos in the viewer. `className` places it: `relative` or
 * `absolute`, as its slot needs.
 */
export function VideoTile({ video, label, className = '', autoPlay = false, onPress, small, test = 'video-tile', children }: { video: ShopVideo; label: string; className?: string; autoPlay?: boolean; onPress?: (tile: HTMLButtonElement) => void; small?: boolean; test?: string; children?: ReactNode }) {
    const [playing, setPlaying] = useState(autoPlay);

    return playing ? (
        <span className={cn('block bg-black', className)} data-test={`${test}-player`}>
            <iframe
                src={youTubePlayer(video.id)}
                title={video.title ?? label}
                referrerPolicy="strict-origin-when-cross-origin"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowFullScreen
                className="absolute inset-0 size-full"
                data-test="video-player"
            />
        </span>
    ) : (
        <button type="button" aria-label={video.title ? `${label}: ${video.title}` : label} onClick={(event) => (onPress ? onPress(event.currentTarget) : setPlaying(true))} className={cn('block overflow-hidden', className)} data-test={test}>
            <VideoPicture video={video} small={small} />
            {children}
        </button>
    );
}
