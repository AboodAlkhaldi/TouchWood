import { createCn } from 'cn/config';

/*
| shadcn's class merger (`cn`), told about Geist's type scale and Geist's materials (frontend.md
| §1.10, §1.11: "Look").
|
| shadcn's components merge the classes a page gives them with their own, and a later class of the
| same kind wins. Two kinds of ours were unknown to it:
|
| - Geist's type classes - `text-label-14`, `text-copy-13`, `text-heading-20` - which it read as
|   colours: a badge given `text-good text-label-12` lost its green, and a button given
|   `text-label-14` kept shadcn's `text-sm` beside it (found in batch A of the rebuild, 2026-10-03).
|   Here they are font sizes, as they are.
| - Geist's materials - `material-base`, `material-modal` - each a surface, a ring or shadow and the
|   10 px corners in one class. Unknown to the merger, a Card given `material-base` kept shadcn's
|   own `rounded-xl` and `shadow-sm` beside it, and those come later in the stylesheet, so every
|   card had 12 px corners and Tailwind's shadow instead (the review of batch C, 2026-10-04). Here
|   a material replaces the corners, the shadow and the background given before it; a class given
|   after it still wins, as with any other kind.
|
| shadcn's code is not edited for this: every `import { cn } from "cn"` in it is pointed here by
| the bundler and the type checker (`vite.config.ts`, `tsconfig.json`), so their imports stay as
| the CLI wrote them. A test keeps both lists in step with the classes `resources/css/app.css`
| defines (tests/Architecture/ClassMergeTest).
*/

const TYPE_SCALE = /^(heading|label|copy|button)-\d+(-mono)?$/;

const MATERIALS = ['base', 'small', 'medium', 'large', 'tooltip', 'menu', 'modal', 'fullscreen'];

export const cn = createCn({
    extend: {
        classGroups: {
            'font-size': [{ text: [(value: string) => TYPE_SCALE.test(value)] }],
            material: [{ material: MATERIALS }],
        },
        conflictingClassGroups: {
            material: ['rounded', 'shadow', 'bg-color'],
        },
    },
});
