import { Fragment } from 'react';

/*
| A sentence that names a store's policies, each name a link opening that policy's page in a new tab
| (flags-2026-11.md S3.3, S3.4, S3.6): checkout's tick, sign-up's, a company application's. The words are one
| translated line with a `:name` where each policy goes, so each language keeps its own order; a policy the store
| has not published is its plain name, no link.
*/

export type PolicyName = { label: string; href: string | null; test: string };

export function PolicySentence({ text, names }: { text: string; names: Record<string, PolicyName> }) {
    const keys = Object.keys(names).sort((a, b) => b.length - a.length);
    const parts = keys.length === 0 ? [text] : text.split(new RegExp(`(${keys.map((key) => `:${key}`).join('|')})`, 'g'));

    return (
        <>
            {parts.map((part, index) => {
                const name = part.startsWith(':') ? names[part.slice(1)] : undefined;

                if (name === undefined) {
                    return <Fragment key={index}>{part}</Fragment>;
                }

                return name.href === null ? (
                    <Fragment key={index}>{name.label}</Fragment>
                ) : (
                    <a key={index} href={name.href} target="_blank" rel="noopener noreferrer" className="text-ink underline underline-offset-4" data-test={name.test}>
                        {name.label}
                    </a>
                );
            })}
        </>
    );
}
