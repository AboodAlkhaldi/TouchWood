/*
| A description or a warranty's terms as the server keeps them (catalog.md §1.1, StructuredText):
| paragraphs, headings and lists, with bold runs. Drawn as the panel's preview draws the same marks.
*/

type Run = { text: string; bold: boolean };
type Block = { type: 'paragraph' | 'heading'; runs: Run[] } | { type: 'list'; items: Run[][] };

function blocksOf(document: Record<string, unknown>): Block[] {
    return Array.isArray(document.blocks) ? (document.blocks as Block[]) : [];
}

export function RichText({ document, className }: { document: Record<string, unknown>; className?: string }) {
    const blocks = blocksOf(document);

    if (blocks.length === 0) {
        return null;
    }

    return (
        <div className={['grid gap-3 text-copy-16 text-ink', className].filter(Boolean).join(' ')}>
            {blocks.map((block, index) =>
                block.type === 'list' ? (
                    <ul key={index} className="list-disc ps-5">
                        {block.items.map((item, at) => (
                            <li key={at}>
                                <Runs runs={item} />
                            </li>
                        ))}
                    </ul>
                ) : block.type === 'heading' ? (
                    <h3 key={index} className="text-heading-16">
                        <Runs runs={block.runs} />
                    </h3>
                ) : (
                    <p key={index}>
                        <Runs runs={block.runs} />
                    </p>
                ),
            )}
        </div>
    );
}

function Runs({ runs }: { runs: Run[] }) {
    return <>{runs.map((run, index) => (run.bold ? <strong key={index}>{run.text}</strong> : <span key={index}>{run.text}</span>))}</>;
}
