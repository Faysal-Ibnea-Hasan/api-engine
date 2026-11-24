import { cn } from '@/lib/utils';

/** Pass `value` (0–100) for determinate progress; omit it while the total is unknown. */
export default function Progress({
    label,
    value,
    className,
}: {
    label: string;
    value?: number;
    className?: string;
}) {
    const clamped =
        value === undefined || Number.isNaN(value)
            ? undefined
            : Math.min(100, Math.max(0, value));

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={clamped}
            className={cn(
                'h-2 w-full overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full bg-info transition-[width]',
                    clamped === undefined &&
                        'w-1/3 animate-pulse motion-reduce:animate-none',
                )}
                style={
                    clamped === undefined ? undefined : { width: `${clamped}%` }
                }
            />
        </div>
    );
}
