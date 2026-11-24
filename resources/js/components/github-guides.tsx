import { Check, ChevronDown, Copy, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useClipboard } from '@/hooks/use-clipboard';

/** A value to paste into GitHub, with a copy button. */
export function CopyValue({ label, value }: { label: string; value: string }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm text-muted-foreground">{label}</span>
            <code className="rounded-md bg-muted px-2 py-1 font-mono text-code break-all">
                {value}
            </code>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => void copy(value, label)}
                aria-label={`Copy ${label}`}
            >
                {copied === value ? (
                    <Check aria-hidden="true" className="size-4" />
                ) : (
                    <Copy aria-hidden="true" className="size-4" />
                )}
                {copied === value ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

function Guide({
    title,
    defaultOpen,
    children,
}: {
    title: string;
    defaultOpen: boolean;
    children: ReactNode;
}) {
    return (
        <Collapsible
            defaultOpen={defaultOpen}
            className="rounded-lg border bg-card"
        >
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="group flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    {title}
                    <ChevronDown
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                    />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="border-t px-4 py-3 text-sm">
                {children}
            </CollapsibleContent>
        </Collapsible>
    );
}

const link =
    'rounded-sm font-medium underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * The one place the GitHub App setup steps live (README "GitHub import: setup
 * tutorial" repeats them). URLs come from the server, so they are the real ones.
 */
export function GitHubAppSetupGuide({
    configured,
    urls,
}: {
    configured: boolean;
    urls: { homepage: string; callback: string; newApp: string };
}) {
    return (
        <Guide title="How to set up the GitHub App" defaultOpen={!configured}>
            <ol className="list-decimal space-y-4 pl-5">
                <li>
                    On GitHub, open{' '}
                    <a className={link} href={urls.newApp}>
                        Settings → Developer settings → GitHub Apps → New GitHub
                        App
                    </a>
                    . For an organization, use the organization's Settings →
                    Developer settings → GitHub Apps → New GitHub App instead.
                </li>
                <li className="space-y-2">
                    <p>Fill in the form exactly like this:</p>
                    <ul className="list-disc space-y-2 pl-5">
                        <li>
                            <strong>GitHub App name</strong>: any name, for
                            example “API Engine”.
                        </li>
                        <li>
                            <CopyValue
                                label="Homepage URL"
                                value={urls.homepage}
                            />
                        </li>
                        <li>
                            <CopyValue
                                label="Callback URL"
                                value={urls.callback}
                            />
                        </li>
                        <li>
                            <strong>
                                Request user authorization (OAuth) during
                                installation
                            </strong>
                            : turn it <strong>on</strong>. GitHub then hides the
                            Setup URL and sends people back to the Callback URL
                            above. If a Setup URL field is still shown, use the
                            same URL:{' '}
                            <CopyValue
                                label="Setup URL"
                                value={urls.callback}
                            />
                            Leave <strong>Redirect on update</strong> off.
                        </li>
                        <li>
                            <strong>Webhook</strong>: untick{' '}
                            <strong>Active</strong>. Imports are started by
                            hand, so no webhook is needed.
                        </li>
                        <li>
                            <strong>Repository permissions</strong>: Contents ={' '}
                            <strong>Read-only</strong>, Metadata ={' '}
                            <strong>Read-only</strong>. Leave every other
                            permission at <strong>No access</strong>.
                        </li>
                        <li>
                            <strong>
                                Where can this GitHub App be installed?
                            </strong>
                            : <strong>Any account</strong> if several
                            organizations or users will connect their own
                            repositories. <strong>Only on this account</strong>{' '}
                            is fine if only your own organization will connect.
                        </li>
                    </ul>
                </li>
                <li className="space-y-2">
                    <p>
                        Click <strong>Create GitHub App</strong>. On the app's
                        page (General):
                    </p>
                    <ul className="list-disc space-y-2 pl-5">
                        <li>
                            Copy the <strong>App ID</strong> and the{' '}
                            <strong>Client ID</strong> shown at the top.
                        </li>
                        <li>
                            Click <strong>Generate a new client secret</strong>{' '}
                            and copy it right away: GitHub shows it only once.
                        </li>
                        <li>
                            Under Private keys, click{' '}
                            <strong>Generate a private key</strong>. A .pem file
                            downloads. Upload it below, or open it and paste
                            everything, including the BEGIN and END lines.
                        </li>
                        <li>
                            The <strong>slug</strong> is the last part of the
                            app's public link, github.com/apps/
                            <em>slug</em> (shown as “Public link”).
                        </li>
                    </ul>
                </li>
                <li>
                    Enter the values in the form below and click{' '}
                    <strong>Save</strong>. Then click{' '}
                    <strong>Test connection</strong>. Success shows the app's
                    name and slug. An error usually means a wrong App ID or
                    private key: generate a new key, save, and test again.
                </li>
            </ol>
            <Alert variant="info" role="note" className="mt-4">
                <ShieldCheck aria-hidden="true" />
                <AlertDescription className="space-y-1">
                    <p>
                        <strong>Safety:</strong> API Engine asks GitHub for
                        read-only access and never writes to repositories.
                    </p>
                    <p>
                        The client secret and private key are stored encrypted
                        and are never shown again, not even here.
                    </p>
                    <p>
                        To rotate them, generate a new client secret or private
                        key on GitHub, save it here, then delete the old one on
                        GitHub.
                    </p>
                </AlertDescription>
            </Alert>
        </Guide>
    );
}

/** Short how-to for the project's GitHub source tab. */
export function GitHubImportHelp({
    integrationsUrl,
    manageUrl,
}: {
    integrationsUrl: string;
    manageUrl: string | null;
}) {
    return (
        <Guide title="How importing from GitHub works" defaultOpen={false}>
            <ol className="list-decimal space-y-2 pl-5">
                <li>
                    <a className={link} href={integrationsUrl}>
                        Connect GitHub
                    </a>{' '}
                    (workspace Owners and Maintainers). GitHub asks you to
                    authorize the app; every account or organization where it is
                    already installed is then connected. If it isn't installed
                    anywhere yet, GitHub asks where to install it and which
                    repositories to grant.
                </li>
                <li>
                    Back here, pick the repository, then a branch, tag, full
                    commit SHA, or the default branch, and click{' '}
                    <strong>Import</strong>.
                </li>
                <li>
                    The branch or tag is turned into one exact commit when you
                    click Import. Importing the same commit again reuses its
                    snapshot. If the branch has moved, you get a new snapshot
                    and the old one stays.
                </li>
                <li>
                    Then detection, routes, analysis and documentation run
                    exactly as for a ZIP upload.
                </li>
            </ol>
            <p className="mt-3 font-medium">If something goes wrong</p>
            <ul className="list-disc space-y-1 pl-5">
                <li>
                    <strong>GitHub isn't set up</strong>: ask a platform admin
                    to add the GitHub App in Settings → GitHub App.
                </li>
                <li>
                    <strong>The repository isn't listed</strong>: grant access
                    to it on GitHub, on the installation's{' '}
                    {manageUrl ? (
                        <a className={link} href={manageUrl}>
                            Configure page
                        </a>
                    ) : (
                        'Configure page'
                    )}
                    , then reload this page.
                </li>
                <li>
                    <strong>Access revoked</strong>: connect GitHub again.
                    Earlier snapshots and documentation are kept.
                </li>
                <li>
                    <strong>Rate limited</strong>: wait a few minutes and try
                    again. ZIP upload keeps working.
                </li>
            </ul>
        </Guide>
    );
}
