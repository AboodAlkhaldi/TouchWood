import { Link } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopProjectsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| H5, the projects (content.md §1.13, §4.5): cards of photo, title and city, newest first, 12 then Show More - and no
| page at all until the first is published. The design has none: drawn from its look (frontend.md §2.1; plan P4).
*/

type Project = { id: string; title: string; slug: string; city: string | null; photo: PhotoSizes | null };

export default function Projects(props: ShopProjectsPageData) {
    const t = useTranslator();
    const link = useLink();
    const list = useLoadMore<Project>(props.projects as unknown as Project[], (project) => project.id);

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <div className="grid gap-6" data-test="projects">
                <h1 className="text-heading-32 text-ink">{t('content::shop.projects.title')}</h1>
                <ul className="grid gap-4 sm:grid-cols-2">
                    {list.rows.map((project) => (
                        <li key={project.id}>
                            <Link href={link('storefront.content.project', { slug: project.slug })} className="group relative block overflow-hidden rounded-xl" data-test="project-card">
                                <ShopPhoto photo={project.photo} size="detail" alt="" className="aspect-[2/1] w-full" />
                                <span className="absolute inset-x-0 bottom-0 grid gap-1 bg-[var(--tw-overlay)] p-4 text-ink-on-brand">
                                    {project.city ? <span className="text-label-13">{project.city}</span> : null}
                                    <span className="text-heading-16 group-hover:underline sm:text-heading-20">{project.title}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
                {props.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(link('storefront.content.projects'), { after: props.next ?? '' }, ['projects', 'next'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
