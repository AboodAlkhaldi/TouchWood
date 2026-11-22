import type { ReactNode } from 'react';
import type { Block, Run } from '@/components/content/marks';

/*
| A page's, post's or project's body drawn from its blocks (content.md §1.2) - never `dangerouslySetInnerHTML`: the
| panel's preview draws it as the shop will. Headings at two levels, paragraphs, lists bulleted or numbered, bold, links
| (one leaving the shop opens in a new tab, `noopener noreferrer`), images at the text's whole width with their own
| width and height, lazy, and tables that scroll sideways inside their own frame on a phone. Small on purpose: it is on
| the editor's first paint, and the shop's pages draw with it.
*/

export type BlockImage = { src: string | null; width: number | null; height: number | null };

type Props = {
    blocks: Block[];
    /** A body image's file, by its `ref`: none while it is not uploaded or not ready. */
    image: (ref: number) => BlockImage | null;
    /** Where a shop link goes: the path with the store and language before it, a full address as it is; null for one that
     * is not a link the rules allow, drawn as its plain words (as ContentLink does). */
    href: (link: string) => string | null;
    dir?: 'rtl' | 'ltr';
    lang?: string;
    className?: string;
};

export function ContentBlocks({ blocks, image, href, dir, lang, className }: Props) {
    const runs = (line: Run[]) => <Runs runs={line} href={href} />;

    return (
        <div dir={dir} lang={lang} className={['grid min-w-0 gap-3 text-copy-16 break-words text-ink', className].filter(Boolean).join(' ')}>
            {blocks.map((block, index): ReactNode => {
                switch (block.type) {
                    case 'heading':
                        return block.level === 2 ? (
                            <h2 key={index} className="text-heading-24">
                                {runs(block.runs)}
                            </h2>
                        ) : (
                            <h3 key={index} className="text-heading-20">
                                {runs(block.runs)}
                            </h3>
                        );
                    case 'list': {
                        const items = block.items.map((item, at) => <li key={at}>{runs(item)}</li>);

                        return block.ordered ? (
                            <ol key={index} className="list-decimal ps-5">
                                {items}
                            </ol>
                        ) : (
                            <ul key={index} className="list-disc ps-5">
                                {items}
                            </ul>
                        );
                    }
                    case 'image': {
                        const file = image(block.ref);

                        return file?.src ? (
                            <img key={index} src={file.src} alt={block.alt} width={file.width ?? undefined} height={file.height ?? undefined} loading="lazy" className="h-auto w-full rounded-md" />
                        ) : (
                            <p key={index} className="material-small p-3 text-copy-13 text-ink-muted">
                                {block.alt}
                            </p>
                        );
                    }
                    case 'table':
                        return (
                            <div key={index} className="max-w-full overflow-x-auto rounded-md border border-line">
                                <table className="w-full text-copy-14">
                                    <thead className="bg-surface-sunken">
                                        <tr>
                                            {block.head.map((cell, at) => (
                                                <th key={at} className="px-3 py-2 text-start font-semibold">
                                                    {runs(cell)}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {block.rows.map((row, at) => (
                                            <tr key={at} className="border-t border-line">
                                                {row.map((cell, column) => (
                                                    <td key={column} className="px-3 py-2">
                                                        {runs(cell)}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    default:
                        return <p key={index}>{runs(block.runs)}</p>;
                }
            })}
        </div>
    );
}

function Runs({ runs, href }: { runs: Run[]; href: (link: string) => string | null }) {
    return (
        <>
            {runs.map((run, index) => {
                const words = run.bold ? <strong>{run.text}</strong> : run.text;

                const to = run.link === undefined ? null : href(run.link);

                if (run.link === undefined || to === null) {
                    return <span key={index}>{words}</span>;
                }

                const away = run.link.toLowerCase().startsWith('https://');

                return (
                    <a key={index} href={to} className="text-brand-strong underline" {...(away ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
                        {words}
                    </a>
                );
            })}
        </>
    );
}
