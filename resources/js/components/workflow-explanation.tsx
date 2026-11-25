import { Copy, Sparkles, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useClipboard } from '@/hooks/use-clipboard';
import type {
    WorkflowAi,
    WorkflowExplanation,
    WorkflowExplanationDocument,
} from '@/types';

type View = 'preview' | 'markdown';

const STAGE = {
    tracing: 'Tracing the code…',
    writing: 'Writing the explanation…',
} as const;

const fmt = (n: number) => n.toLocaleString('en-US');

// No typography plugin: headings, lists and code in the rendered Markdown are styled here.
const PROSE =
    'prose max-w-none space-y-3 text-sm leading-relaxed [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono [&_code]:text-xs [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:pt-2 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:font-semibold [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5';

/** The explanation as rendered HTML or as its raw Markdown. */
export function ExplanationBody({
    explanation,
    view,
}: {
    explanation: WorkflowExplanationDocument;
    view: View;
}) {
    return view === 'markdown' ? (
        <pre className="overflow-x-auto rounded-md border bg-muted/40 p-3 font-mono text-xs whitespace-pre-wrap">
            {explanation.markdown}
        </pre>
    ) : (
        <div
            className={PROSE}
            // Rendered server-side with raw HTML stripped and unsafe links dropped.
            dangerouslySetInnerHTML={{ __html: explanation.html ?? '' }}
        />
    );
}

function Completed({
    explanation,
}: {
    explanation: WorkflowExplanationDocument;
}) {
    const [view, setView] = useState<View>('preview');
    const [, copy] = useClipboard();
    const footer = [
        explanation.provider,
        explanation.model,
        explanation.promptVersion && `prompt ${explanation.promptVersion}`,
        explanation.inputUnits !== null && explanation.outputUnits !== null
            ? `${fmt(explanation.inputUnits)} in / ${fmt(explanation.outputUnits)} out`
            : null,
        explanation.finishedAt
            ? new Date(explanation.finishedAt).toLocaleString()
            : null,
    ].filter(Boolean);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={view}
                    // Radix lets the pressed item be clicked off; keep one view shown.
                    onValueChange={(v) => v && setView(v as View)}
                    aria-label="Explanation view"
                >
                    <ToggleGroupItem value="preview">Preview</ToggleGroupItem>
                    <ToggleGroupItem value="markdown">Markdown</ToggleGroupItem>
                </ToggleGroup>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => copy(explanation.markdown ?? '', 'Markdown')}
                >
                    <Copy aria-hidden="true" />
                    Copy
                </Button>
            </div>

            {explanation.truncated && (
                <Alert variant="warning" role="note">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription>
                        This explanation was cut short by the output limit. Run
                        Explain again or choose a model with a larger output.
                    </AlertDescription>
                </Alert>
            )}

            <ExplanationBody explanation={explanation} view={view} />

            {explanation.omitted.length > 0 && (
                <details className="text-xs">
                    <summary className="cursor-pointer rounded-sm text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        Not sent to AI ({explanation.omitted.length})
                    </summary>
                    <ul className="mt-1 space-y-0.5">
                        {explanation.omitted.map((name) => (
                            <li key={name}>
                                <code>{name}</code>
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {footer.length > 0 && (
                <p
                    className="text-xs text-muted-foreground"
                    title="Provider, model, prompt version, tokens sent / received, finished at"
                >
                    {footer.join(' · ')}
                </p>
            )}
        </div>
    );
}

/** The Workflow tab's AI explanation: start it, follow its stage, read and copy the result. */
export function WorkflowExplanationView({
    explanation,
    ai,
    canExplain,
    onExplain,
}: {
    explanation: WorkflowExplanation | null;
    ai: WorkflowAi;
    canExplain: boolean;
    onExplain: (again: boolean) => void;
}) {
    const stage = explanation?.stage ?? null;
    const shown = explanation?.hidden === false ? explanation : null;
    const done = shown?.status === 'completed' && shown.markdown !== null;
    const retry =
        shown?.status === 'failed' &&
        shown.retryable &&
        ai.available &&
        canExplain;
    const uses = [
        ai.provider,
        ai.model,
        ai.effort &&
            `${ai.effort[0].toUpperCase()}${ai.effort.slice(1)} effort`,
        ai.left !== null &&
            ai.period &&
            `${fmt(ai.left)} tokens left this ${ai.period}`,
    ].filter(Boolean);

    return (
        <div className="space-y-4">
            {stage !== null ? (
                <p className="flex items-center gap-2 text-muted-foreground">
                    <Spinner /> {STAGE[stage]}
                </p>
            ) : (
                canExplain &&
                !retry && (
                    <div className="space-y-1">
                        <Button
                            variant={done ? 'outline' : 'default'}
                            disabled={!ai.available}
                            onClick={() => onExplain(done)}
                        >
                            <Sparkles aria-hidden="true" />
                            {done
                                ? 'Explain again'
                                : 'Explain workflow with AI'}
                        </Button>
                        {ai.available ? (
                            <p className="text-xs text-muted-foreground">
                                Uses {uses.join(' · ')}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {ai.reason}
                                {ai.settingsUrl && (
                                    <>
                                        {' '}
                                        <TextLink href={ai.settingsUrl}>
                                            Open AI settings
                                        </TextLink>
                                    </>
                                )}
                            </p>
                        )}
                    </div>
                )
            )}

            {explanation?.hidden && stage === null && (
                <p className="text-muted-foreground">
                    The AI workflow explanation is available to members who can
                    read source code.
                </p>
            )}

            {shown === null && !explanation?.hidden && !canExplain && (
                <p className="text-muted-foreground">
                    Not explained yet. A Developer, Maintainer or Owner can
                    explain the workflow with AI.
                </p>
            )}

            {shown?.status === 'failed' && (
                <Alert variant="destructive">
                    <AlertDescription>
                        <p>
                            {shown.failureMessage ??
                                'The explanation could not be written.'}
                        </p>
                        {retry && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-2"
                                onClick={() => onExplain(false)}
                            >
                                Try again
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {done && shown && <Completed explanation={shown} />}
        </div>
    );
}
