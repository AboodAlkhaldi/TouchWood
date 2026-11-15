import { Badge } from '@/components/ui/badge';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';

/*
| What Content's lists draw the same way (content.md §4.2): the state badge, words as well as colour (§4.1). A list's rows
| as cards on a phone (round 3 #15) are every module's now: components/PhoneCards. Apart from the forms' parts (parts.tsx),
| so a list page pays for no form's code and a form for no list's (JavaScriptBudgetTest; lesson 195).
*/

const LOOKS: Record<string, Tone> = {
    DRAFT: 'gray-subtle',
    SCHEDULED: 'blue-subtle',
    LIVE: 'green-subtle',
    PUBLISHED: 'green-subtle',
    ENDED: 'gray-subtle',
    CANCELLED: 'gray-subtle',
    // A campaign or a theme whose stores do not agree (§4.1): each store's own state sits beside it.
    MIXED: 'amber-subtle',
};

export function StateBadge({ state }: { state: string }) {
    const t = useTranslator();
    const words: Record<string, string> = {
        DRAFT: t('content::admin_shared.state.DRAFT'),
        SCHEDULED: t('content::admin_shared.state.SCHEDULED'),
        LIVE: t('content::admin_shared.state.LIVE'),
        ENDED: t('content::admin_shared.state.ENDED'),
        PUBLISHED: t('content::admin_shared.state.PUBLISHED'),
        CANCELLED: t('content::admin_shared.state.CANCELLED'),
        MIXED: t('content::admin_shared.state.MIXED'),
    };

    return (
        <Badge className={tone(LOOKS[state] ?? 'gray-subtle')} data-test="state">
            {words[state] ?? state}
        </Badge>
    );
}
