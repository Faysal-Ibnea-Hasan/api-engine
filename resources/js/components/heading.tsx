import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export default function Heading({
    title,
    description,
    variant = 'default',
    icon: Icon,
    children,
    compact = false,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    icon?: LucideIcon;
    children?: ReactNode;
    compact?: boolean;
}) {
    if (variant === 'small') {
        return (
            <header>
                <h2 className="mb-0.5 flex items-center gap-2 text-sm font-semibold wrap-anywhere">
                    {Icon && (
                        <Icon
                            aria-hidden="true"
                            className="size-4 shrink-0 text-info"
                        />
                    )}
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
                {children}
            </header>
        );
    }

    return (
        <header
            data-slot="page-hero"
            className={cn(
                'flex flex-wrap items-center rounded-lg bg-gradient-from brand-gradient text-gradient-foreground shadow-sm [--color-ring:var(--gradient-foreground)] [&_:focus-visible]:ring-ring!',
                compact
                    ? 'gap-x-3 gap-y-2 p-3 md:px-4 md:py-3'
                    : 'gap-x-4 gap-y-3 p-4 md:p-5',
            )}
        >
            {Icon && (
                <span
                    className={cn(
                        'flex shrink-0 items-center justify-center rounded-lg bg-gradient-foreground/15',
                        compact ? 'size-9' : 'size-10',
                    )}
                >
                    <Icon
                        aria-hidden="true"
                        className={compact ? 'size-4' : 'size-5'}
                    />
                </span>
            )}
            <div
                className={cn(
                    'min-w-48 flex-1',
                    compact ? 'space-y-0' : 'space-y-0.5',
                )}
            >
                <h2 className="text-lg font-semibold tracking-tight wrap-anywhere">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm wrap-anywhere">{description}</p>
                )}
            </div>
            {children}
        </header>
    );
}
