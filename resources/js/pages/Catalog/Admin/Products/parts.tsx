import { Badge } from '@/components/ui/badge';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';

/*
| What the products screens share (catalog.md §4.4 S8, S9): a product's stage as words and colour - P3:
| Draft grey, Ready green, Archived amber, each subtle (Geist's Badge, never colour alone).
*/

const STAGE_TONES: Record<string, Parameters<typeof tone>[0]> = {
    DRAFT: 'gray-subtle',
    READY: 'green-subtle',
    ARCHIVED: 'amber-subtle',
};

export function StageBadge({ stage }: { stage: string }) {
    const t = useTranslator();

    return (
        <Badge className={tone(STAGE_TONES[stage] ?? 'gray-subtle')} data-test="stage">
            {t(`catalog::admin.stage.${stage}`)}
        </Badge>
    );
}
