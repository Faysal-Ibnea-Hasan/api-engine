import type { Auth } from '@/types/auth';
import type { CurrentWorkspace, WorkspaceSummary } from '@/types/workspace';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            features: Record<string, boolean>;
            /** Both null while the workspace_management flag is off. */
            workspaces: WorkspaceSummary[] | null;
            currentWorkspace: CurrentWorkspace | null;
            [key: string]: unknown;
        };
    }
}
