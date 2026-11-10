import type { HomeSectionPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { SortableList } from '../../../Catalog/Admin/SortableList';
import { SectionForm } from './section-form';

/* The logos band's own page (content.md §1.4 #8, §4.4 W1): the section's form with its logos dragged into order. */
export default function Logos(props: HomeSectionPageData) {
    return <SectionForm {...props} Sortable={SortableList} />;
}
