import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/40 p-4 md:p-6">
            <div className="w-full max-w-sm rounded-lg border bg-card p-6">
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-3">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 rounded-md font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <div className="flex size-8 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-8 fill-current text-primary" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="flex flex-col gap-1 text-center">
                            <h1 className="text-lg font-semibold">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
