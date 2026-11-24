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
