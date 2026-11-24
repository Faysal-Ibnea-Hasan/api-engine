import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            richColors
            closeButton
            duration={5000}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--border-radius': 'var(--radius)',
                    '--success-bg': 'color-mix(in srgb, var(--success) 10%, var(--popover))',
                    '--success-text': 'var(--success)',
                    '--success-border': 'color-mix(in srgb, var(--success) 30%, var(--popover))',
                    '--info-bg': 'color-mix(in srgb, var(--info) 10%, var(--popover))',
                    '--info-text': 'var(--info)',
                    '--info-border': 'color-mix(in srgb, var(--info) 30%, var(--popover))',
                    '--warning-bg': 'color-mix(in srgb, var(--warning) 10%, var(--popover))',
                    '--warning-text': 'var(--warning)',
                    '--warning-border': 'color-mix(in srgb, var(--warning) 30%, var(--popover))',
                    '--error-bg': 'color-mix(in srgb, var(--destructive) 10%, var(--popover))',
                    '--error-text': 'var(--destructive)',
                    '--error-border': 'color-mix(in srgb, var(--destructive) 30%, var(--popover))',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
