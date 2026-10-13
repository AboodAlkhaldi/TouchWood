import { useState, type ComponentType } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useLink } from '@/lib/routes';

/*
| The shop's search box (catalog.md §4.5), as every page first draws it: a plain field in the header
| that searches on Enter - a form, so it searches before any script has loaded. The moment the
| shopper reaches for it, the suggestions' half (SearchSuggest: shadcn's Command in a Popover) is
| loaded and takes its place, what was typed kept - so no shop page carries it before it shows
| (frontend.md §5; lesson 180: nothing lazy on the first paint).
*/

type Suggest = ComponentType<{ words: Record<string, string>; typed: string }>;

export function SearchBox({ words }: { words: Record<string, string> }) {
    const link = useLink();
    const { url } = usePage();
    const [text, setText] = useState(() => (url.includes('/search?') ? (new URLSearchParams(url.split('?')[1]).get('q') ?? '') : ''));
    const [Full, setFull] = useState<Suggest | null>(null);

    function load() {
        if (Full === null) {
            void import('@/components/shop/SearchSuggest').then((loaded) => setFull(() => loaded.SearchSuggest));
        }
    }

    if (Full !== null) {
        return <Full words={words} typed={text} />;
    }

    const action = link('storefront.catalog.search');

    return (
        <form
            action={action}
            method="get"
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                router.visit(link('storefront.catalog.search', { q: text.trim() }));
            }}
            className="material-small flex h-9 w-full items-center gap-2 bg-surface px-3"
            data-test="search-box"
        >
            <Search aria-hidden="true" className="size-4 shrink-0 opacity-50" />
            <input
                type="search"
                name="q"
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    load();
                }}
                onFocus={load}
                // Fetched as the pointer comes near, shown only once the shopper is in the field.
                onPointerEnter={() => void import('@/components/shop/SearchSuggest')}
                placeholder={words.search_placeholder}
                aria-label={words.search}
                autoComplete="off"
                className="h-9 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                data-test="search-input"
            />
        </form>
    );
}
