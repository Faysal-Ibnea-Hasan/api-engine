import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function Heading({
    title,
    description,
    variant = 'default',
    icon: Icon,
    children,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    icon?: LucideIcon;
    children?: ReactNode;
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
            className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg bg-gradient-from brand-gradient p-4 text-gradient-foreground shadow-sm [--color-ring:var(--gradient-foreground)] md:p-5 [&_:focus-visible]:ring-ring!"
        >
            {Icon && (
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gradient-foreground/15">
                    <Icon aria-hidden="true" className="size-5" />
                </span>
            )}
            <div className="min-w-48 flex-1 space-y-0.5">
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
