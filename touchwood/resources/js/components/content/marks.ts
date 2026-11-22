import { shopLink } from '@/components/content/links';
import { toLatinDigits } from '@/lib/digits';

/*
| A page's, post's or project's body as staff type it (content.md §1.2), read into the blocks the server keeps - the
| same reading as MarkText::blocks, for the editor's preview: what is seen is what is saved. A shared case table
| (tests/Modules/Content/Unit/mark-cases.json) is read by both sides (MarkTextCasesTest, marks.test.ts).
|
| A blank line ends a paragraph, a list or a table; `# ` and `## ` are headings (levels 2 and 3); `- ` and `1. ` list
| items; `**bold**`, `[words](address)`; `![description](image:3)` on its own line; `| a | b |` table rows, the first
| the heading; a backslash before `\ * [ ] | ) # - .` or a space makes it text. What the server would refuse - a link
| it does not take, an image mark inside a line, a table row of another width - the preview draws as best it can; the
| save says why.
*/

export type Run = { text: string; bold?: true; link?: string };

export type Block =
    | { type: 'paragraph'; runs: Run[] }
    | { type: 'heading'; level: 2 | 3; runs: Run[] }
    | { type: 'list'; ordered?: true; items: Run[][] }
    | { type: 'image'; ref: number; alt: string }
    | { type: 'table'; head: Run[][]; rows: Run[][][] };

// Each escaped character stands in as a control character while the line is read, as MarkText's.
const ESCAPES: Record<string, string> = { '\\': '\x01', '*': '\x02', '[': '\x03', ']': '\x04', '|': '\x05', ')': '\x06', '#': '\x07', '-': '\x08', '.': '\x0F', ' ': '\x0E' };
const UNMASK = Object.fromEntries(Object.entries(ESCAPES).map(([character, stand]) => [stand, character]));

const unmask = (text: string): string => text.replace(/[\x01-\x08\x0E\x0F]/g, (stand) => UNMASK[stand] ?? stand);

function mask(line: string): string {
    const characters = [...line];
    let masked = '';

    for (let at = 0; at < characters.length; at++) {
        const next = characters[at + 1];

        if (characters[at] === '\\' && next !== undefined && ESCAPES[next] !== undefined) {
            masked += ESCAPES[next];
            at++;
        } else {
            masked += characters[at];
        }
    }

    return masked;
}

// PHP's trim(): ASCII whitespace only.
const trim = (text: string): string => text.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

function links(part: string): Run[] {
    const runs: Run[] = [];
    let at = 0;

    for (const found of part.matchAll(/\[([^[\]]+)\]\(([^\s)]*)\)/gu)) {
        const start = found.index;

        if (start > at) {
            runs.push({ text: unmask(part.slice(at, start)) });
        }

        const address = shopLink(unmask(found[2] ?? ''));
        runs.push(address === null ? { text: unmask(found[0]) } : { text: unmask(found[1] ?? ''), link: address });
        at = start + found[0].length;
    }

    if (at < part.length) {
        runs.push({ text: unmask(part.slice(at)) });
    }

    return runs;
}

/** A line's runs: bold split first, then each part's links; runs side by side with the same marks joined, as RichText. */
function runs(line: string): Run[] {
    const parts = line.split('**');
    const found: Run[] = [];

    parts.forEach((part, index) => {
        const bold = index % 2 === 1 && index < parts.length - 1;

        for (const run of links(!bold && index % 2 === 1 ? `**${part}` : part)) {
            found.push(bold ? { ...run, bold: true } : run);
        }
    });

    const joined: Run[] = [];

    for (const run of found.filter((each) => each.text !== '')) {
        const last = joined[joined.length - 1];

        if (last !== undefined && last.bold === run.bold && last.link === run.link) {
            last.text += run.text;
        } else {
            joined.push({ ...run });
        }
    }

    return joined;
}

const cells = (line: string): string[] => line.slice(1, -1).split('|').map(trim);

export function readContentMarks(typed: string): Block[] {
    const blocks: Block[] = [];
    let paragraph: string | null = null;
    let list: { ordered: boolean; items: Run[][] } | null = null;
    let table: Run[][][] | null = null;

    const close = () => {
        if (paragraph !== null) {
            blocks.push({ type: 'paragraph', runs: runs(paragraph) });
        }

        if (list !== null) {
            blocks.push(list.ordered ? { type: 'list', ordered: true, items: list.items } : { type: 'list', items: list.items });
        }

        if (table !== null) {
            blocks.push({ type: 'table', head: table[0] ?? [], rows: table.slice(1) });
        }

        paragraph = null;
        list = null;
        table = null;
    };

    for (const raw of toLatinDigits(typed).split(/\r\n|[\n\x0B\f\r\x85\u2028\u2029]/u)) {
        const line = trim(mask(raw));
        const heading = /^(#{1,2}) (.*)$/u.exec(line);
        const image = /^!\[([^[\]]*)\]\(image:([0-9]{1,6})\)$/u.exec(line);
        const item = /^- (.*)$/u.exec(line) ?? /^[0-9]{1,4}\. (.*)$/u.exec(line);

        if (line === '') {
            close();
        } else if ([...line].length >= 2 && line.startsWith('|') && line.endsWith('|')) {
            const row = cells(line).map((cell) => (cell === '' ? [] : runs(cell)));

            if (table === null) {
                close();
                table = [row];
            } else {
                (table as Run[][][]).push(row);
            }
        } else if (heading !== null) {
            close();
            blocks.push({ type: 'heading', level: heading[1] === '##' ? 3 : 2, runs: runs(heading[2] ?? '') });
        } else if (image !== null) {
            close();
            blocks.push({ type: 'image', ref: Number(image[2]), alt: trim(unmask(trim(image[1] ?? ''))) });
        } else if (item !== null) {
            const ordered = !line.startsWith('- ');
            const current = list as { ordered: boolean; items: Run[][] } | null;

            if (current === null || current.ordered !== ordered) {
                close();
                list = { ordered, items: [] };
            }

            (list as unknown as { items: Run[][] }).items.push(runs(item[1] ?? ''));
        } else {
            if (paragraph === null) {
                close();
                paragraph = line;
            } else {
                paragraph = `${paragraph} ${line}`;
            }
        }
    }

    close();

    return blocks;
}
