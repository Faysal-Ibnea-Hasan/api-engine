import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import CurrentWorkspaceController from '@/actions/App/Http/Controllers/Workspaces/CurrentWorkspaceController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

/** Renders nothing while the workspace feature is off. */
export function WorkspaceSwitcher() {
    const { workspaces, currentWorkspace, impersonation } = usePage().props;

    if (workspaces === null) {
        return null;
    }

    if (impersonation) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton data-test="workspace-switcher" disabled>
                        <span className="truncate font-medium">
                            {currentWorkspace?.name ?? 'No workspace'}
                        </span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    const switchTo = (id: string) =>
        router.put(CurrentWorkspaceController.url(), { workspace_id: id });

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            className="data-[state=open]:bg-sidebar-accent"
                            data-test="workspace-switcher"
                        >
                            <span className="truncate font-medium">
                                {currentWorkspace?.name ?? 'No workspace'}
                            </span>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="min-w-56" align="start">
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Workspaces
                        </DropdownMenuLabel>
                        {workspaces.map((workspace) => (
                            <DropdownMenuItem
                                key={workspace.id}
                                onSelect={() => switchTo(workspace.id)}
                            >
                                <span className="truncate">
                                    {workspace.name}
                                </span>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {workspace.roleLabel}
                                </span>
                                {workspace.id === currentWorkspace?.id && (
                                    <Check aria-label="Current workspace" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={WorkspaceController.index()}>
                                <Plus /> Manage or create workspaces
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
