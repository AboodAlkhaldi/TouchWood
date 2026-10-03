import { createCn } from 'cn/config';

/*
| shadcn's class merger (`cn`), told about Geist's type scale (frontend.md §1.10, §1.11: "Look").
|
| shadcn's components merge the classes a page gives them with their own, and a later class of the
| same kind wins. Geist's type classes - `text-label-14`, `text-copy-13`, `text-heading-20` - were
| unknown to it, so it read them as colours: a badge given `text-good text-label-12` lost its green,
| and a button given `text-label-14` kept shadcn's `text-sm` beside it (found in batch A of the
| rebuild, 2026-10-03). Here they are font sizes, as they are.
|
| shadcn's code is not edited for this: every `import { cn } from "cn"` in it is pointed here by
| the bundler and the type checker (`vite.config.ts`, `tsconfig.json`), so their imports stay as
| the CLI wrote them. A test keeps this list in step with the classes `resources/css/app.css`
| defines (tests/Architecture/ClassMergeTest).
*/

const TYPE_SCALE = /^(heading|label|copy|button)-\d+(-mono)?$/;

export const cn = createCn({
    extend: {
        classGroups: {
            'font-size': [{ text: [(value: string) => TYPE_SCALE.test(value)] }],
        },
    },
});
