import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export default function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-6 text-center',
                className,
            )}
        >
            {Icon && (
                <span className="flex size-10 items-center justify-center rounded-lg brand-gradient text-gradient-foreground">
                    <Icon aria-hidden="true" className="size-5" />
                </span>
            )}
            <h3 className="text-sm font-medium">{title}</h3>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
