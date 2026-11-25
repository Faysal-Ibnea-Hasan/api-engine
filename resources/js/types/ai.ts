export type AiEffort = 'low' | 'medium' | 'high';

export type AiMeter = {
    used: number;
    limit: number | null;
    left: number | null;
    resetsAt: string | null;
};

/** One provider card on Settings → AI. The key itself never reaches the page. */
export type AiProviderProps = {
    provider: 'anthropic' | 'openai' | 'gemini';
    label: string;
    configured: boolean;
    keySuffix: string | null;
    status: string;
    statusText: string;
    checkedAt: string | null;
    model: string | null;
    models: { id: string; effort: boolean; free: boolean }[];
    effort: AiEffort;
    enabled: boolean;
    isDefault: boolean;
    limitTokens: number | null;
    limitPeriod: 'day' | 'month';
    meter: AiMeter;
};
