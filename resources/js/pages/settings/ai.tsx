import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import AiSettingsController from '@/actions/App/Http/Controllers/Settings/AiSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import Progress from '@/components/progress';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { edit } from '@/routes/ai-settings';
import type { AiMeter, AiProviderProps } from '@/types/ai';

const BANNER =
    "When AI explains a workflow, the project's own method code (with secrets removed) is sent to the selected provider.";

const FIELD_LABELS: Record<string, string> = {
    api_key: 'API key',
    model: 'Model',
    effort: 'Effort',
    enabled: 'Enabled',
    is_default: 'Default',
    limit_tokens: 'Limit',
    limit_period: 'Limit period',
};

const STATUS: Record<
    string,
    { label: string; variant: 'success' | 'warning' | 'outline' }
> = {
    ready: { label: 'Ready', variant: 'success' },
    not_configured: { label: 'Not configured', variant: 'outline' },
    unchecked: { label: 'Not tested', variant: 'warning' },
};

const fmt = (n: number) => n.toLocaleString('en-US');

/** "1 Dec", read from the date part so it matches the app timezone the server used. */
const resetDate = (iso: string) =>
    new Date(`${iso.slice(0, 10)}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

function UsageMeter({
    meter,
    period,
    label,
}: {
    meter: AiMeter;
    period: 'day' | 'month';
    label: string;
}) {
    if (meter.limit === null) {
        return (
            <p className="text-sm text-muted-foreground">
                No limit set · {fmt(meter.used)} used this {period}
            </p>
        );
    }

    return (
        <div className="grid gap-1">
            <Progress
                label={`${label} token usage`}
                value={(meter.used / meter.limit) * 100}
            />
            <p className="text-sm text-muted-foreground">
                {fmt(meter.used)} used · {fmt(meter.left ?? 0)} left
                {meter.resetsAt && ` · resets ${resetDate(meter.resetsAt)}`}
            </p>
        </div>
    );
}

function RemoveKey({ p }: { p: AiProviderProps }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="sm">
                    Remove
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remove the {p.label} key</DialogTitle>
                <DialogDescription>
                    {p.label} stops working until a new key is added and tested.
                    Its model list is cleared too.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Keep the key</Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    AiSettingsController.destroyKey.url(
                                        p.provider,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Remove key
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ProviderCard({ p }: { p: AiProviderProps }) {
    const [model, setModel] = useState(p.model ?? '');
    const [enabled, setEnabled] = useState(p.enabled);
    const [isDefault, setIsDefault] = useState(p.isDefault);
    const id = (field: string) => `${p.provider}-${field}`;
    const status = STATUS[p.status] ?? {
        label: 'Not connected',
        variant: 'warning',
    };
    const noEffort = p.models.find((m) => m.id === model)?.effort === false;

    return (
        <section
            data-provider={p.provider}
            aria-labelledby={id('title')}
            className="rounded-lg border bg-card p-4"
        >
            <Form
                {...AiSettingsController.update.form(p.provider)}
                options={{ preserveScroll: true }}
                resetOnSuccess={['api_key']}
                onError={(errors) => {
                    // Three cards share one page: say what failed and bring this card's first bad field into view.
                    const fields = Object.keys(errors);
                    toast.error(
                        `${p.label} not saved. Fix: ${fields.map((f) => FIELD_LABELS[f] ?? f).join(', ')}.`,
                    );
                    const first = document.getElementById(id(fields[0] ?? ''));
                    first?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                    first?.focus({ preventScroll: true });
                }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 id={id('title')} className="font-medium">
                                {p.label}
                            </h2>
                            <Badge variant={status.variant}>
                                {status.label}
                            </Badge>
                            {p.isDefault && <Badge>Default</Badge>}
                            {p.configured && p.status !== 'ready' && (
                                <span className="text-sm text-muted-foreground">
                                    {p.statusText.charAt(0).toUpperCase() +
                                        p.statusText.slice(1)}
                                    .
                                </span>
                            )}
                        </div>

                        {!p.configured ? (
                            <div className="grid gap-2">
                                <Label htmlFor={id('api_key')}>API key</Label>
                                <div className="flex gap-2">
                                    <Input
                                        id={id('api_key')}
                                        name="api_key"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <Button disabled={processing}>
                                        Add key
                                    </Button>
                                </div>
                                <InputError message={errors.api_key} />
                            </div>
                        ) : (
                            <>
                                <div className="flex flex-wrap gap-4">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={id('enabled')}
                                            checked={enabled}
                                            onCheckedChange={(v) =>
                                                setEnabled(v === true)
                                            }
                                        />
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value={enabled ? '1' : '0'}
                                        />
                                        <Label htmlFor={id('enabled')}>
                                            Enabled
                                        </Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={id('is_default')}
                                            checked={isDefault}
                                            onCheckedChange={(v) =>
                                                setIsDefault(v === true)
                                            }
                                        />
                                        <input
                                            type="hidden"
                                            name="is_default"
                                            value={isDefault ? '1' : '0'}
                                        />
                                        <Label htmlFor={id('is_default')}>
                                            Default provider
                                        </Label>
                                    </div>
                                </div>
                                <InputError
                                    message={
                                        errors.enabled ?? errors.is_default
                                    }
                                />

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor={id('model')}>
                                            Model
                                        </Label>
                                        <NativeSelect
                                            id={id('model')}
                                            name="model"
                                            value={model}
                                            onChange={(e) =>
                                                setModel(e.target.value)
                                            }
                                            disabled={p.models.length === 0}
                                        >
                                            {p.models.length === 0 ? (
                                                <option value="">
                                                    Test the connection to load
                                                    models
                                                </option>
                                            ) : (
                                                model === '' && (
                                                    <option value="">
                                                        Pick a model
                                                    </option>
                                                )
                                            )}
                                            {p.models.map((m) => (
                                                <option key={m.id} value={m.id}>
                                                    {m.free
                                                        ? `${m.id} · Free tier`
                                                        : m.id}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        {p.models.length === 0 && (
                                            <p className="text-sm text-muted-foreground">
                                                Test the connection to load
                                                models
                                            </p>
                                        )}
                                        <InputError message={errors.model} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={id('effort')}>
                                            Effort
                                        </Label>
                                        <NativeSelect
                                            id={id('effort')}
                                            name="effort"
                                            defaultValue={p.effort}
                                        >
                                            <option value="low">Low</option>
                                            <option value="medium">
                                                Medium
                                            </option>
                                            <option value="high">High</option>
                                        </NativeSelect>
                                        {noEffort && (
                                            <p className="text-sm text-muted-foreground">
                                                Effort not supported by this
                                                model
                                            </p>
                                        )}
                                        <InputError message={errors.effort} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={id('api_key')}>
                                        Key …{p.keySuffix}
                                    </Label>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Input
                                            id={id('api_key')}
                                            name="api_key"
                                            type="password"
                                            autoComplete="new-password"
                                            placeholder="Paste a new key to replace it"
                                            className="max-w-xs"
                                        />
                                        <RemoveKey p={p} />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    AiSettingsController.test.url(
                                                        p.provider,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Test connection
                                        </Button>
                                        {p.checkedAt && (
                                            <span className="text-sm text-muted-foreground">
                                                Checked{' '}
                                                {new Date(
                                                    p.checkedAt,
                                                ).toLocaleTimeString([], {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </span>
                                        )}
                                    </div>
                                    <InputError message={errors.api_key} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor={id('limit_tokens')}>
                                        Limit
                                    </Label>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <NativeSelect
                                            id={id('limit_period')}
                                            name="limit_period"
                                            aria-label="Limit period"
                                            defaultValue={p.limitPeriod}
                                            className="w-32"
                                        >
                                            <option value="month">
                                                Monthly
                                            </option>
                                            <option value="day">Daily</option>
                                        </NativeSelect>
                                        <Input
                                            id={id('limit_tokens')}
                                            name="limit_tokens"
                                            type="number"
                                            min={1000}
                                            step={1000}
                                            inputMode="numeric"
                                            defaultValue={p.limitTokens ?? ''}
                                            placeholder="No limit"
                                            className="w-40"
                                        />
                                        <span className="text-sm text-muted-foreground">
                                            tokens
                                        </span>
                                    </div>
                                    <InputError
                                        message={
                                            errors.limit_tokens ??
                                            errors.limit_period
                                        }
                                    />
                                </div>

                                <UsageMeter
                                    meter={p.meter}
                                    period={p.limitPeriod}
                                    label={p.label}
                                />

                                <Button disabled={processing}>Save</Button>
                            </>
                        )}
                    </>
                )}
            </Form>
        </section>
    );
}

/** Platform admins only. API keys are write-only: the page sees only their last four characters. */
export default function AiSettings({
    providers,
}: {
    providers: AiProviderProps[];
}) {
    return (
        <>
            <Head title="AI settings" />

            <h1 className="sr-only">AI settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI"
                    description="The AI providers that explain workflows. The default provider is used for every run."
                />

                <Alert variant="info">
                    <AlertDescription>{BANNER}</AlertDescription>
                </Alert>

                {providers.map((p) => (
                    // A new key or test result remounts the card, so the selects pick up the server's values.
                    <ProviderCard
                        key={[p.provider, p.keySuffix, p.checkedAt].join('|')}
                        p={p}
                    />
                ))}
            </div>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: [{ title: 'AI settings', href: edit() }],
};
