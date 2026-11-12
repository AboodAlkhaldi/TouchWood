import type { ComponentType } from 'react';
import { Award, BadgeCheck, Clock, createLucideIcon, Headset, Leaf, Package, RotateCcw, Wrench } from 'lucide-react';

/*
| The home's promise icons (content.md §1.4 PROMISES): the fixed list staff pick from (HomeSectionSettings::ICONS).
|
| Four of them - truck, store, shield-check, credit-card - the panel's menu draws too (MenuIcon). Imported from their
| modules here, each was split out of the panel's chunk, and every panel page paid for the split: Shipping's Carriers
| page went over 60 KB (JavaScriptBudgetTest, measured 2026-11-11, +223 bytes for two of them). So they are drawn by
| Lucide's own createLucideIcon from Lucide's own icon data (lucide-react 1.47.0, the same nodes), and the panel keeps
| its modules. The rest are no panel frame's, and are imported as any icon.
*/

export type PromiseIcon = ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;

const Truck = createLucideIcon('truck', [
    ['path', { d: 'M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2', key: 'wrbu53' }],
    ['path', { d: 'M15 18H9', key: '1lyqi6' }],
    ['path', { d: 'M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14', key: 'lysw3i' }],
    ['circle', { cx: '17', cy: '18', r: '2', key: '332jqn' }],
    ['circle', { cx: '7', cy: '18', r: '2', key: '19iecd' }],
]);

const Store = createLucideIcon('store', [
    ['path', { d: 'M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5', key: 'slp6dd' }],
    [
        'path',
        {
            d: 'M17.774 10.31a1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.451 0 1.12 1.12 0 0 0-1.548 0 2.5 2.5 0 0 1-3.452 0 1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.77-3.248l2.889-4.184A2 2 0 0 1 7 2h10a2 2 0 0 1 1.653.873l2.895 4.192a2.5 2.5 0 0 1-3.774 3.244',
            key: 'o0xfot',
        },
    ],
    ['path', { d: 'M4 10.95V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8.05', key: 'wn3emo' }],
]);

const ShieldCheck = createLucideIcon('shield-check', [
    ['path', { d: 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z', key: 'oel41y' }],
    ['path', { d: 'm9 12 2 2 4-4', key: 'dzmm74' }],
]);

const CreditCard = createLucideIcon('credit-card', [
    ['rect', { width: '20', height: '14', x: '2', y: '5', rx: '2', key: 'ynyp8z' }],
    ['line', { x1: '2', x2: '22', y1: '10', y2: '10', key: '1b3vmo' }],
    ['path', { d: 'M6 14h2', key: 'mk7k0u' }],
]);

export const PROMISE_ICONS: Record<string, PromiseIcon> = {
    truck: Truck,
    'shield-check': ShieldCheck,
    'badge-check': BadgeCheck,
    headset: Headset,
    wrench: Wrench,
    package: Package,
    'credit-card': CreditCard,
    'rotate-ccw': RotateCcw,
    store: Store,
    clock: Clock,
    award: Award,
    leaf: Leaf,
};

/** A promise's icon, the badge's for one not on the list. */
export function promiseIcon(name: string): PromiseIcon {
    return PROMISE_ICONS[name] ?? BadgeCheck;
}
