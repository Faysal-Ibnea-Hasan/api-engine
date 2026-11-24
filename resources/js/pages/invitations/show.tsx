import { Form, Head, Link, router } from '@inertiajs/react';
import InvitationController from '@/actions/App/Http/Controllers/Workspaces/InvitationController';
import ErrorPanel from '@/components/error-panel';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home, logout } from '@/routes';

type Props = {
    state: 'invalid' | 'register' | 'accept' | 'wrong_account';
    token: string | null;
    invitation: { workspace: string; email: string; roleLabel: string } | null;
    passwordRules: string | null;
};

export default function ShowInvitation({
    state,
    token,
    invitation,
    passwordRules,
}: Props) {
    if (state === 'invalid' || state === 'wrong_account' || !invitation) {
        return (
            <>
                <Head title="Invitation" />
                <ErrorPanel
                    title={
                        state === 'wrong_account'
                            ? 'This invitation is for a different account'
                            : 'Invitation unavailable'
                    }
                    message={
                        state === 'wrong_account'
                            ? 'Log out, then open the link again and sign in with the invited email address.'
                            : 'This link is invalid, already used or expired. Ask a workspace owner for a new one.'
                    }
                    action={
                        state === 'wrong_account' ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(logout())}
                            >
                                Log out
                            </Button>
                        ) : (
                            <Link href={home()} className="underline">
                                Go to the home page
                            </Link>
                        )
                    }
                />
            </>
        );
    }

    const summary = (
        <p className="text-center text-sm text-muted-foreground">
            Join <strong>{invitation.workspace}</strong> as{' '}
            <strong>{invitation.roleLabel}</strong> with{' '}
            <strong>{invitation.email}</strong>.
        </p>
    );

    return (
        <>
            <Head title={`Join ${invitation.workspace}`} />
            <Form
                {...InvitationController.store.form(token!)}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {summary}
                        {state === 'register' && (
                            <div className="grid gap-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Your name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        Choose a password
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                        passwordrules={
                                            passwordRules ?? undefined
                                        }
                                    />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                    />
                                </div>
                            </div>
                        )}
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {state === 'register'
                                ? 'Create account and join'
                                : 'Accept invitation'}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ShowInvitation.layout = {
    title: 'Workspace invitation',
    description: '',
};
