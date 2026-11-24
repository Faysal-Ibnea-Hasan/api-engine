import { Form, Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Row = {
    id: string;
    name: string;
    role: string;
    roleLabel: string;
    members: number;
};

export default function WorkspacesIndex({
    myWorkspaces,
}: {
    myWorkspaces: Row[];
}) {
    return (
        <>
            <Head title="Workspaces" />
            <div className="flex flex-col gap-8 p-4">
                <Heading
                    title="Workspaces"
                    description="Workspaces you belong to. Everything you create later lives inside one."
                />

                {myWorkspaces.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="You're not in a workspace yet"
                        description="Create one below, or ask a workspace owner to invite you."
                    />
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {myWorkspaces.map((workspace) => (
                            <li key={workspace.id}>
                                <Link
                                    href={WorkspaceMemberController.index(
                                        workspace.id,
                                    )}
                                    className="flex items-center gap-4 p-4 hover:bg-muted/50"
                                >
                                    <span className="font-medium">
                                        {workspace.name}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {workspace.members}{' '}
                                        {workspace.members === 1
                                            ? 'member'
                                            : 'members'}
                                    </span>
                                    <span className="ml-auto text-sm">
                                        {workspace.roleLabel}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <section className="max-w-md space-y-4">
                    <Heading
                        variant="small"
                        title="Create a workspace"
                        description="You'll be its owner."
                    />
                    <Form
                        {...WorkspaceController.store.form()}
                        resetOnSuccess
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        placeholder="e.g. Platform team"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="w-fit"
                                >
                                    {processing && <Spinner />}
                                    Create workspace
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

WorkspacesIndex.layout = {
    breadcrumbs: [{ title: 'Workspaces', href: WorkspaceController.index() }],
};
