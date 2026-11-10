import type { HomeSectionPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { SectionForm } from './section-form';

/* A home section's own page (content.md §1.4, §4.4 W1 Edit), every type but the logos band's (Home/Logos). */
export default function Section(props: HomeSectionPageData) {
    return <SectionForm {...props} />;
}
