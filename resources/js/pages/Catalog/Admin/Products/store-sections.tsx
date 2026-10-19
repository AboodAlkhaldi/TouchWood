import { type ComponentType, lazy, type LazyExoticComponent, Suspense } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { useTranslator } from '@/lib/t';
import type { StoreCardData, StoreSectionData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The modules' parts of a store card on a product's Stores tab (catalog.md amendment 23(e)): Pricing's
| prices, Inventory's stock. The server sends each card its sections - the module, the component under
| `resources/js/sections/` that draws it, and its data - and Catalog draws them without knowing what they
| hold, each component fetched only when a card first shows it (the page budget, frontend.md §5).
|
| A component the build does not have draws nothing: the module that registered it ships it with its own
| screens, and a card is never broken by a part that is not there.
*/

/** What a section's component is given: its data for this store, and the store's card. */
export type StoreSectionProps = {
    data: StoreSectionData['data'];
    store: StoreCardData;
};

type Section = { default: ComponentType<StoreSectionProps> };

// Every section component the build has, by its path: fetched when first drawn, never with the page.
const FILES = import.meta.glob<Section>('../../../../sections/**/*.tsx');
const loaded = new Map<string, LazyExoticComponent<ComponentType<StoreSectionProps>>>();

function component(name: string): LazyExoticComponent<ComponentType<StoreSectionProps>> | null {
    const load = FILES[`../../../../sections/${name}.tsx`];

    if (load === undefined) {
        return null;
    }

    if (!loaded.has(name)) {
        loaded.set(name, lazy(load));
    }

    return loaded.get(name) ?? null;
}

export function StoreSections({ store }: { store: StoreCardData }) {
    const t = useTranslator();

    return (
        <>
            {store.sections.map((section) => {
                const Part = component(section.component);

                return Part === null ? null : (
                    <div key={`${section.module}-${section.component}`} className="grid gap-2" data-test={`store-${store.code}-section-${section.module}`}>
                        <Suspense fallback={<Spinner className="text-ink-muted" aria-label={t('ui.loading')} />}>
                            <Part data={section.data} store={store} />
                        </Suspense>
                    </div>
                );
            })}
        </>
    );
}
