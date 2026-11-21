import { Link } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopProjectsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| H5, the projects (content.md §1.13, §4.5): cards of photo, title, city and client, newest first, 12 then Show More - and
| no page at all until the first is published. The caption is under the photo, which carries no overlay (flags-2026-11.md
| S4.2): the title at 16 px, then the city and "With <client>" in the muted ink.
*/

type Project = { id: string; title: string; slug: string; city: string | null; client: string | null; photo: PhotoSizes | null };

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
                            <Link href={link('storefront.content.project', { slug: project.slug })} className="group grid grid-cols-1 gap-3" data-test="project-card">
                                <ShopPhoto photo={project.photo} size="detail" alt="" className="aspect-[2/1] w-full rounded-xl" />
                                <span className="grid grid-cols-1 gap-1" data-test="project-caption">
                                    <span className="text-heading-16 text-ink group-hover:underline">{project.title}</span>
                                    {project.city || project.client ? (
                                        <span className="text-copy-14 break-words text-ink-muted">{[project.city, project.client ? t('content::shop.project.with', { client: project.client }) : null].filter(Boolean).join(' · ')}</span>
                                    ) : null}
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
