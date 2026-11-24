import { Link, usePage } from '@inertiajs/react';
import { FolderKanban, GitBranch, LayoutGrid, Users } from 'lucide-react';
import GitHubConnectionController from '@/actions/App/Http/Controllers/GitHub/GitHubConnectionController';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

export function AppSidebar() {
    const { currentWorkspace, features } = usePage().props;
    const navItems: NavItem[] = currentWorkspace
        ? [
              ...mainNavItems,
              ...(features.project_wizard
                  ? [
                        {
                            title: 'Projects',
                            href: ProjectController.index(),
                            icon: FolderKanban,
                        },
                    ]
                  : []),
              {
                  title: 'Members',
                  href: WorkspaceMemberController.index(currentWorkspace.id),
                  icon: Users,
              },
              ...(features.git_import
                  ? [
                        {
                            title: 'GitHub',
                            href: GitHubConnectionController.show(
                                currentWorkspace.id,
                            ),
                            icon: GitBranch,
                        },
                    ]
                  : []),
          ]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <WorkspaceSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
