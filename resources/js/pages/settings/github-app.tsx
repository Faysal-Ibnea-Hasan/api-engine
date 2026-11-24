import { Form, Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import GitHubAppSettingsController from '@/actions/App/Http/Controllers/Settings/GitHubAppSettingsController';
import { CopyValue, GitHubAppSetupGuide } from '@/components/github-guides';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { edit } from '@/routes/github-app';

type Props = {
    settings: {
        appId: string;
        slug: string;
        clientId: string;
        clientSecretSet: boolean;
        privateKeySet: boolean;
        configured: boolean;
        updatedAt: string | null;
        updatedBy: string | null;
    };
    urls: {
        homepage: string;
        callback: string;
        newApp: string;
        publicPage: string | null;
    };
};

const FIELD_LABELS: Record<string, string> = {
    app_id: 'App ID',
    slug: 'App slug',
    client_id: 'Client ID',
    client_secret: 'Client secret',
    private_key: 'Private key',
    private_key_file: 'Private key',
};

function SecretState({ set }: { set: boolean }) {
    return (
        <Badge variant={set ? 'success' : 'outline'}>
            {set ? 'Saved' : 'Not set'}
        </Badge>
    );
}

/** Platform admins only. Secrets are write-only: the page never receives them. */
export default function GitHubAppSettings({ settings, urls }: Props) {
    return (
        <>
            <Head title="GitHub App settings" />

            <h1 className="sr-only">GitHub App settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="GitHub App"
                    description="The one GitHub App every workspace uses to import repositories (read-only)."
                />

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge
                        variant={settings.configured ? 'success' : 'warning'}
                    >
                        {settings.configured ? 'Configured' : 'Not set up'}
                    </Badge>
                    {settings.updatedAt && (
                        <span className="text-muted-foreground">
                            Last saved{' '}
                            {new Date(settings.updatedAt).toLocaleString()}
                            {settings.updatedBy && ` by ${settings.updatedBy}`}
                        </span>
                    )}
                    {urls.publicPage && (
                        <a
                            className="ml-auto font-medium underline underline-offset-4"
                            href={urls.publicPage}
                        >
                            Open on GitHub
                        </a>
                    )}
                </div>

                <div className="space-y-2 rounded-lg border bg-card p-4">
                    <CopyValue label="Callback URL" value={urls.callback} />
                    <CopyValue label="Setup URL" value={urls.callback} />
                    <CopyValue label="Homepage URL" value={urls.homepage} />
                </div>

                <GitHubAppSetupGuide
                    configured={settings.configured}
                    urls={urls}
                />

                <Form
                    {...GitHubAppSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={[
                        'client_secret',
                        'private_key',
                        'private_key_file',
                    ]}
                    onError={(errors) => {
                        // The form sits below a long guide: say what failed and bring the first bad field into view.
                        const fields = Object.keys(errors);
                        toast.error(
                            `Not saved. Fix: ${fields.map((f) => FIELD_LABELS[f] ?? f).join(', ')}.`,
                        );
                        const first = document.getElementById(
                            fields[0] === 'private_key_file'
                                ? 'private_key'
                                : (fields[0] ?? ''),
                        );
                        first?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                        });
                        first?.focus({ preventScroll: true });
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="app_id">App ID</Label>
                                <Input
                                    id="app_id"
                                    name="app_id"
                                    inputMode="numeric"
                                    defaultValue={settings.appId}
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.app_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="slug">App slug</Label>
                                <Input
                                    id="slug"
                                    name="slug"
                                    defaultValue={settings.slug}
                                    placeholder="api-engine"
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.slug} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="client_id">Client ID</Label>
                                <Input
                                    id="client_id"
                                    name="client_id"
                                    defaultValue={settings.clientId}
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.client_id} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <Label htmlFor="client_secret">
                                        Client secret
                                    </Label>
                                    <SecretState
                                        set={settings.clientSecretSet}
                                    />
                                </div>
                                <Input
                                    id="client_secret"
                                    name="client_secret"
                                    type="password"
                                    autoComplete="new-password"
                                    placeholder={
                                        settings.clientSecretSet
                                            ? 'Leave blank to keep the saved secret'
                                            : ''
                                    }
                                />
                                <InputError message={errors.client_secret} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <Label htmlFor="private_key">
                                        Private key (.pem)
                                    </Label>
                                    <SecretState set={settings.privateKeySet} />
                                </div>
                                <Textarea
                                    id="private_key"
                                    name="private_key"
                                    rows={5}
                                    spellCheck={false}
                                    autoComplete="off"
                                    className="font-mono text-code"
                                    placeholder={
                                        settings.privateKeySet
                                            ? 'Leave blank to keep the saved key'
                                            : '-----BEGIN RSA PRIVATE KEY-----'
                                    }
                                />
                                <Label
                                    htmlFor="private_key_file"
                                    className="text-muted-foreground"
                                >
                                    Or upload the .pem file
                                </Label>
                                <Input
                                    id="private_key_file"
                                    name="private_key_file"
                                    type="file"
                                    accept=".pem"
                                />
                                <InputError
                                    message={
                                        errors.private_key ??
                                        errors.private_key_file
                                    }
                                />
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button disabled={processing}>Save</Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!settings.configured}
                                    onClick={() =>
                                        router.post(
                                            GitHubAppSettingsController.test.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Test connection
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

GitHubAppSettings.layout = {
    breadcrumbs: [{ title: 'GitHub App settings', href: edit() }],
};
