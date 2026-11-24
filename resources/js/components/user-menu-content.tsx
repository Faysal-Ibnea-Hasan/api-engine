import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings, Undo2 } from 'lucide-react';
import WorkspaceImpersonationController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceImpersonationController';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { impersonation } = usePage().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            {impersonation ? (
                <DropdownMenuItem
                    onSelect={() => {
                        cleanup();
                        router.delete(
                            WorkspaceImpersonationController.destroy.url(),
                        );
                    }}
                >
                    <Undo2 className="mr-2" />
                    Return to {impersonation.owner.name}
                </DropdownMenuItem>
            ) : (
                <>
                    <DropdownMenuGroup>
                        <DropdownMenuItem asChild>
                            <Link
                                className="block w-full cursor-pointer"
                                href={edit()}
                                prefetch
                                onClick={cleanup}
                            >
                                <Settings className="mr-2" />
                                Settings
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href={logout()}
                            as="button"
                            onClick={handleLogout}
                            data-test="logout-button"
                        >
                            <LogOut className="mr-2" />
                            Log out
                        </Link>
                    </DropdownMenuItem>
                </>
            )}
        </>
    );
}
