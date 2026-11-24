export type RunStatus =
    | 'queued'
    | 'running'
    | 'completed'
    | 'partial'
    | 'failed'
    | 'cancelled';

export type SnapshotStatus =
    | 'pending'
    | 'validating'
    | 'ready'
    | 'rejected'
    | 'failed';

export type ProcessingRunSummary = {
    id: string;
    status: RunStatus;
    active: boolean;
    attempts: number;
    deduplicated: boolean;
    failureCode: string | null;
    failureMessage: string | null;
    snapshotId: string | null;
    cancelRequested: boolean;
    createdAt: string | null;
    finishedAt: string | null;
};

export type SnapshotSummary = {
    id: string;
    status: SnapshotStatus;
    sourceType: 'zip' | 'git' | 'fixture';
    sha256: string | null;
    manifestVersion: string;
    fileCount: number;
    excludedCount: number;
    totalBytes: number;
    readyAt: string | null;
    createdAt: string | null;
    failureMessage: string | null;
};

export type SourcePanelData = {
    latestRun: ProcessingRunSummary | null;
    snapshots: SnapshotSummary[];
    canImportFixture: boolean;
    canUpload: boolean;
    maxUploadBytes: number;
    maxSourceBytes: number;
    /** Folders of the latest ready snapshot that contain artisan and composer.json. */
    rootCandidates: string[];
};

export type GitHubImportSummary = {
    id: string;
    repository: string;
    refType: 'default' | 'branch' | 'tag' | 'commit';
    requestedRef: string;
    commitSha: string;
    commitMessage: string | null;
    committedAt: string | null;
    status: string;
    failureCode: string | null;
    failureMessage: string | null;
    runId: string | null;
    snapshotId: string | null;
    createdAt: string | null;
};

/** The project page's GitHub tab. Never holds tokens, codes or URLs from GitHub. */
export type GitHubPanelData = {
    configured: boolean;
    connected: boolean;
    source: {
        installationId: string;
        installationLinked: boolean;
        repositoryId: number;
        fullName: string;
        visibility: string;
        defaultBranch: string;
        trackedRefType: string | null;
        trackedRef: string | null;
        lastImportedSha: string | null;
        lastImportedAt: string | null;
    } | null;
    imports: GitHubImportSummary[];
    canSelect: boolean;
    canImport: boolean;
    canConnect: boolean;
    workspaceId: string;
};

export type GitHubInstallationSummary = {
    id: string;
    githubId: number;
    accountLogin: string;
    accountType: string;
    repositorySelection: string;
    suspended: boolean;
    manageUrl: string;
    connectedAt?: string | null;
};

export type GitHubRepositorySummary = {
    id: number;
    fullName: string;
    visibility: string;
    defaultBranch: string;
    archived: boolean;
};
