import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'rounded-sm text-foreground underline decoration-muted-foreground/50 underline-offset-4 transition-colors outline-none hover:decoration-current! focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
