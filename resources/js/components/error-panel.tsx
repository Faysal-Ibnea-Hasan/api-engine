import { AlertCircleIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export default function ErrorPanel({
    title = 'Something went wrong.',
    message,
    correlationId,
    action,
}: {
    title?: string;
    message: string;
    correlationId?: string;
    action?: ReactNode;
}) {
    return (
        <Alert variant="destructive" role="alert">
            <AlertCircleIcon />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>
                <p>{message}</p>
                {correlationId && (
                    <p className="text-xs">
                        Reference: <code>{correlationId}</code>
                    </p>
                )}
                {action && <div className="mt-2">{action}</div>}
            </AlertDescription>
        </Alert>
    );
}
