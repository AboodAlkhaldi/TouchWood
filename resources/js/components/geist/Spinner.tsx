import { cx } from './cx';

/*
| Geist's Spinner and Loading Dots (frontend.md 1.10).
|
| Spinner: an indeterminate wait of a second or three, the size of the type beside it. A button never
| holds one by hand - it takes `loading` instead. Loading Dots: a wait inside a sentence, after the
| word for the work ("Saving"), never after a finished verb. Both honour reduced motion, and both are
| decorative: the words around them carry the meaning.
*/

export function Spinner({ size = 16, className }: { size?: number; className?: string }) {
    return (
        <svg
            aria-hidden="true"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            className={cx('shrink-0 animate-spin motion-reduce:animate-none', className)}
        >
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
            <path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

export function LoadingDots({ size = 4 }: { size?: number }) {
    return (
        <span aria-hidden="true" className="inline-flex items-center gap-0.5 ps-0.5 align-middle">
            {[0, 1, 2].map((dot) => (
                <span
                    key={dot}
                    className="inline-block animate-pulse rounded-full bg-current motion-reduce:animate-none"
                    style={{ width: size, height: size, animationDelay: `${dot * 150}ms` }}
                />
            ))}
        </span>
    );
}
