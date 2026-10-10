/*
| A description or a warranty's terms as staff type them (catalog.md §4.4, P1): the products file's own
| marks (catalog.md §1.12) - a blank line starts a paragraph, a line starting "- " a list item, "# " a
| heading, and **…** is bold. The server reads the same marks (DescriptionText) and keeps what it makes
| of them; this only draws the preview under the box, the same way, so what is seen is what is saved.
*/

export type Run = { text: string; bold: boolean };

export type Block = { type: 'paragraph'; runs: Run[] } | { type: 'heading'; runs: Run[] } | { type: 'list'; items: Run[][] };

export function readMarks(text: string): Block[] {
    const blocks: Block[] = [];
    let paragraph: string | null = null;
    let items: string[] | null = null;

    const close = () => {
        if (paragraph !== null) {
            blocks.push({ type: 'paragraph', runs: runs(paragraph) });
        }

        if (items !== null) {
            blocks.push({ type: 'list', items: items.map(runs) });
        }

        paragraph = null;
        items = null;
    };

    for (const raw of text.split(/\r\n|\r|\n/)) {
        const line = raw.trim();

        if (line === '') {
            close();
        } else if (line.startsWith('# ')) {
            close();
            blocks.push({ type: 'heading', runs: runs(line.slice(2).trim()) });
        } else if (line.startsWith('- ')) {
            if (paragraph !== null) {
                close();
            }

            items = [...(items ?? []), line.slice(2).trim()];
        } else {
            if (items !== null) {
                close();
            }

            paragraph = paragraph === null ? line : `${paragraph} ${line}`;
        }
    }

    close();

    return blocks;
}

/** `**…**` marks bold; a `**` left open is the text it is. */
function runs(line: string): Run[] {
    const parts = line.split('**');

    return parts.flatMap((part, index) => {
        const bold = index % 2 === 1 && index < parts.length - 1;
        const text = !bold && index % 2 === 1 ? `**${part}` : part;

        return text === '' ? [] : [{ text, bold }];
    });
}
