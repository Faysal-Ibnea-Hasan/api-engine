import { cn } from '@/lib/utils';

const tone = (method: string) => {
    switch (method) {
        case 'GET':
            return 'bg-success/10 text-success';
        case 'POST':
            return 'bg-info/10 text-info';
        case 'PUT':
        case 'PATCH':
            return 'bg-warning/10 text-warning';
        case 'DELETE':
            return 'bg-destructive/10 text-destructive';
        default:
            return 'bg-muted text-muted-foreground';
    }
};

export default function MethodBadge({ method }: { method: string }) {
    return (
        <span
            data-slot="method-badge"
            title={method}
            className={cn(
                'inline-block max-w-24 min-w-14 truncate rounded-sm px-1.5 py-0.5 text-center font-mono text-xs font-semibold',
                tone(method),
            )}
        >
            {method}
        </span>
    );
}
