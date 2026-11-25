import AppearanceMenu from '@/components/appearance-menu';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="relative flex h-12 shrink-0 items-center gap-2 border-b border-border px-4 after:pointer-events-none after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:brand-gradient">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto">
                <AppearanceMenu />
            </div>
        </header>
    );
}
