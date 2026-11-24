export type ProjectSummary = {
    id: string;
    name: string;
    slug: string;
    status: 'draft' | 'active' | 'archived';
    visibility: 'workspace' | 'restricted';
    setupComplete: boolean;
    updatedAt: string | null;
};

export type ProjectDetail = Omit<ProjectSummary, 'updatedAt'> & {
    description: string | null;
    archivedAt: string | null;
    wizardStep: string;
    sourcePreference: string | null;
    framework: string;
    rootPath: string;
    analysisMode: string;
    documentationMode: string;
    lockVersion: number;
    creator: string | null;
    updatedAt: string | null;
};

export type ProjectOption = {
    value: string;
    label: string;
    available: boolean;
    note?: string;
};

export type ProjectOptions = Record<
    'source_preference' | 'framework' | 'analysis_mode' | 'documentation_mode',
    ProjectOption[]
>;
