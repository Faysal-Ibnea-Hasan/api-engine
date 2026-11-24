import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceInvitationController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceInvitationController';
import WorkspaceImpersonationController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceImpersonationController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { RoleOption } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isSelf: boolean;
};

type Invitation = {
    id: string;
    email: string;
    roleLabel: string;
    expiresAt: string;
};

type Props = {
    workspace: { id: string; name: string };
    can: {
        manageMembers: boolean;
        updateWorkspace: boolean;
        impersonateMembers: boolean;
    };
    currentRole: string;
    assignableRoles: RoleOption[];
    members: Member[];
    invitations: Invitation[];
};

type Confirmation = {
    title: string;
    description: string;
    action: string;
    destructive?: boolean;
    run: () => void;
};

export default function Members({
    workspace,
    can,
    currentRole,
    assignableRoles,
    members,
    invitations,
}: Props) {
    const [confirm, setConfirm] = useState<Confirmation | null>(null);
    const canAssign = (role: string) =>
        assignableRoles.some((r) => r.value === role);
    // Server re-checks every rule; this only hides controls that would be refused.
    const canManage = (member: Member) =>
        can.manageMembers &&
        !member.isSelf &&
        (member.role !== 'owner' || currentRole === 'owner');

    const changeRole = (member: Member, role: string) => {
        const label =
            assignableRoles.find((r) => r.value === role)?.label ?? role;
        setConfirm({
            title: `Make ${member.name} ${label}?`,
            description: `${member.name} changes from ${member.roleLabel} to ${label}. This takes effect on their next request.`,
            action: 'Change role',
            run: () =>
                router.patch(
                    WorkspaceMemberController.update.url({
                        workspace: workspace.id,
                        member: member.id,
                    }),
                    { role },
                    { preserveScroll: true },
                ),
        });
    };

    const remove = (member: Member) =>
        setConfirm({
            title: member.isSelf
                ? `Leave ${workspace.name}?`
                : `Remove ${member.name}?`,
            description: member.isSelf
                ? 'You lose access immediately. You need a new invitation to come back.'
                : `${member.name} loses access to ${workspace.name} immediately.`,
            action: member.isSelf ? 'Leave workspace' : 'Remove member',
            destructive: true,
            run: () =>
                router.delete(
                    WorkspaceMemberController.destroy.url({
                        workspace: workspace.id,
                        member: member.id,
                    }),
                    { preserveScroll: true },
                ),
        });

    const loginAs = (member: Member) =>
        setConfirm({
            title: `Log in as ${member.name}?`,
            description: `You will use ${member.name}'s permissions inside ${workspace.name}. A banner will let you return to your owner account.`,
            action: 'Login as member',
            run: () =>
                router.post(
                    WorkspaceImpersonationController.store.url({
                        workspace: workspace.id,
                        member: member.id,
                    }),
                ),
        });

    return (
        <>
            <Head title={`${workspace.name} members`} />
            <div className="flex flex-col gap-8 p-4">
                <Heading
                    title={workspace.name}
                    description="People in this workspace and what their role lets them do."
                />

                {can.updateWorkspace && (
                    <Form
                        {...WorkspaceController.update.form(workspace.id)}
                        options={{ preserveScroll: true }}
                        className="flex max-w-md items-end gap-2"
                    >
                        {({ processing, errors }) => (
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="workspace-name">
                                    Workspace name
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="workspace-name"
                                        name="name"
                                        defaultValue={workspace.name}
                                        required
                                        maxLength={100}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Rename
                                    </Button>
                                </div>
                                <InputError message={errors.name} />
                            </div>
                        )}
                    </Form>
                )}

                <section className="space-y-4">
                    <Heading variant="small" title="Members" />
                    <ul className="divide-y rounded-xl border">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex flex-wrap items-center gap-4 p-4"
                            >
                                <div className="min-w-0">
                                    <p className="truncate font-medium">
                                        {member.name}
                                        {member.isSelf && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (you)
                                            </span>
                                        )}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {member.email}
                                    </p>
                                </div>
                                <div className="ml-auto flex items-center gap-2">
                                    {can.impersonateMembers &&
                                        !member.isSelf && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => loginAs(member)}
                                            >
                                                Login as
                                            </Button>
                                        )}
                                    {canManage(member) ? (
                                        <Select
                                            value={member.role}
                                            onValueChange={(role) =>
                                                changeRole(member, role)
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-36"
                                                aria-label={`Role for ${member.name}`}
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {!canAssign(member.role) && (
                                                    <SelectItem
                                                        value={member.role}
                                                        disabled
                                                    >
                                                        {member.roleLabel}
                                                    </SelectItem>
                                                )}
                                                {assignableRoles.map((role) => (
                                                    <SelectItem
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <span className="text-sm">
                                            {member.roleLabel}
                                        </span>
                                    )}
                                    {(canManage(member) || member.isSelf) && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(member)}
                                        >
                                            {member.isSelf ? 'Leave' : 'Remove'}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                {can.manageMembers && (
                    <section className="max-w-xl space-y-4">
                        <Heading
                            variant="small"
                            title="Invite someone"
                            description="They get an email link that works once and expires in 7 days."
                        />
                        <Form
                            {...WorkspaceInvitationController.store.form(
                                workspace.id,
                            )}
                            options={{ preserveScroll: true }}
                            resetOnSuccess={['email']}
                            className="flex flex-wrap items-start gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid min-w-56 flex-1 gap-2">
                                        <Label
                                            htmlFor="invite-email"
                                            className="sr-only"
                                        >
                                            Email address
                                        </Label>
                                        <Input
                                            id="invite-email"
                                            type="email"
                                            name="email"
                                            required
                                            placeholder="name@company.com"
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                    <Select
                                        name="role"
                                        defaultValue={
                                            canAssign('developer')
                                                ? 'developer'
                                                : assignableRoles[0]?.value
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-36"
                                            aria-label="Role"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {assignableRoles.map((role) => (
                                                <SelectItem
                                                    key={role.value}
                                                    value={role.value}
                                                >
                                                    {role.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Send invite
                                    </Button>
                                    <InputError
                                        className="basis-full"
                                        message={errors.role}
                                    />
                                </>
                            )}
                        </Form>

                        {invitations.length === 0 ? (
                            <EmptyState
                                title="No pending invitations"
                                className="p-4"
                            />
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {invitations.map((invitation) => (
                                    <li
                                        key={invitation.id}
                                        className="flex items-center gap-4 p-4 text-sm"
                                    >
                                        <span className="truncate">
                                            {invitation.email}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {invitation.roleLabel} · expires{' '}
                                            {new Date(
                                                invitation.expiresAt,
                                            ).toLocaleDateString()}
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="ml-auto"
                                            onClick={() =>
                                                router.delete(
                                                    WorkspaceInvitationController.destroy.url(
                                                        {
                                                            workspace:
                                                                workspace.id,
                                                            invitation:
                                                                invitation.id,
                                                        },
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Revoke
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>

            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent>
                    <DialogTitle>{confirm?.title}</DialogTitle>
                    <DialogDescription>
                        {confirm?.description}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant={
                                confirm?.destructive ? 'destructive' : 'default'
                            }
                            onClick={() => {
                                confirm?.run();
                                setConfirm(null);
                            }}
                        >
                            {confirm?.action}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Workspaces', href: WorkspaceController.index() }],
};
