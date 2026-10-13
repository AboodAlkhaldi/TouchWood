import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Command, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { useLink } from '@/lib/routes';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The shop's search box (catalog.md §4.5), in every page's header: as the shopper types - two letters
| or more, a moment after they stop - up to eight suggestions, photo and name, asked of the server and
| shown under the box: shadcn's Command in a Popover, as its combobox examples are built (the panel's
| SearchCombobox filters a list it is given and is not this). Enter, or "See All Results", opens the
| results page, which logs the search; the suggestions are never logged (amendment 5(e)).
|
| Enter means the results: "See All Results" is the item chosen until the shopper moves to a
| suggestion with the arrows.
|
| The heavy half of the box: SearchBox draws a plain field first and loads this the moment the
| shopper reaches for it, so no shop page carries it before it shows (frontend.md §5).
*/

const ALL = '__all';
const PAUSE = 250;

export function SearchSuggest({ words, typed: initial }: { words: Record<string, string>; typed: string }) {
    const link = useLink();
    const [text, setText] = useState(initial);
    const [open, setOpen] = useState(true);
    const [cards, setCards] = useState<ShopCardData[]>([]);
    const [selected, setSelected] = useState(ALL);
    const anchor = useRef<HTMLDivElement>(null);
    const typed = text.trim();
    const long = [...typed].length >= 2;

    useEffect(() => {
        if (!long) {
            setCards([]);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(link('storefront.catalog.suggest', { q: typed }), { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
                .then((response) => (response.ok ? (response.json() as Promise<{ products: ShopCardData[] }>) : { products: [] }))
                .then((body) => {
                    setCards(body.products);
                    setSelected(ALL);
                })
                .catch(() => undefined);
        }, PAUSE);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
        // The link helper is rebuilt with each render; only what was typed asks again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed, long]);

    function go(href: string) {
        setOpen(false);
        router.visit(href);
    }

    return (
        <Popover open={open && long} onOpenChange={setOpen}>
            <Command
                shouldFilter={false}
                value={selected}
                onValueChange={setSelected}
                label={words.search}
                className="material-small h-auto w-full overflow-visible bg-surface **:data-[slot=command-input-wrapper]:border-b-0"
                data-test="search-box"
            >
                <PopoverAnchor asChild>
                    <div ref={anchor} role="search">
                        <CommandInput
                            value={text}
                            onValueChange={(value) => {
                                setText(value);
                                setOpen(true);
                            }}
                            onFocus={() => setOpen(true)}
                            // It takes the plain field's place while the shopper is in it.
                            autoFocus
                            placeholder={words.search_placeholder}
                            aria-label={words.search}
                            data-test="search-input"
                        />
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    align="start"
                    className="w-(--radix-popover-trigger-width) min-w-72 p-0"
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onInteractOutside={(event) => {
                        if (anchor.current?.contains(event.target as Node)) {
                            event.preventDefault();
                        }
                    }}
                    data-test="search-suggestions"
                >
                    <CommandList>
                        {cards.length === 0 ? <p className="px-3 py-3 text-copy-14 text-ink-muted">{words.no_suggestions}</p> : null}
                        <CommandGroup>
                            {cards.map((card) => (
                                <CommandItem
                                    key={card.productId}
                                    value={card.productId}
                                    onSelect={() => go(link('storefront.catalog.product', { slug: card.slug }))}
                                    data-test="suggestion"
                                >
                                    <ShopPhoto photo={card.photo} size="thumb" alt="" className="size-8 shrink-0 rounded" />
                                    <span className="truncate">{card.name}</span>
                                </CommandItem>
                            ))}
                            <CommandItem value={ALL} onSelect={() => go(link('storefront.catalog.search', { q: typed }))} data-test="see-all">
                                <Search aria-hidden="true" />
                                {words.see_all}
                            </CommandItem>
                        </CommandGroup>
                    </CommandList>
                </PopoverContent>
            </Command>
        </Popover>
    );
}
