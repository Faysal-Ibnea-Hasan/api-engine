import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

export type CopiedValue = string | null;
export type CopyFn = (text: string, what?: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

// navigator.clipboard only exists on secure origins (https, localhost); plain-http hosts like *.test need the old textarea path.
function legacyCopy(text: string): boolean {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        area.remove();
    }
}

export async function copyText(text: string): Promise<boolean> {
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // permission denied or document not focused: fall through to the legacy path
        }
    }

    return legacyCopy(text);
}

/** Copies text, toasts the outcome, and remembers the copied value for 1.5s so buttons can show "Copied". */
export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    const copy: CopyFn = async (text, what = 'Text') => {
        const ok = await copyText(text);
        clearTimeout(timer.current);

        if (!ok) {
            setCopiedText(null);
            toast.error(
                "Couldn't copy to the clipboard. Select the text and copy it manually.",
            );

            return false;
        }

        setCopiedText(text);
        toast.success(`${what} copied to the clipboard`);
        timer.current = setTimeout(() => setCopiedText(null), 1500);

        return true;
    };

    return [copiedText, copy];
}
