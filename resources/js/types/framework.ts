export type DetectionStatus =
    | 'detected'
    | 'partial'
    | 'unsupported'
    | 'not_found';

export type DetectedRoot = {
    path: string;
    declaredVersion: string | null;
    lockedVersion: string | null;
    phpConstraint: string | null;
    routeLayout: 'bootstrap_app' | 'route_service_provider' | 'unknown';
    routeFiles: string[] | null; // null when the viewer may not see source paths
    routeFileCount: number;
    modules: string[];
    confidence: 'high' | 'medium';
    evidence: string[] | null;
};

export type FrameworkDetectionData = {
    id: string;
    status: DetectionStatus;
    framework: string | null;
    roots: DetectedRoot[];
    warnings: string[];
    /** Warnings name files, so roles without source access only get a count. */
    hiddenWarningCount: number;
    selectedRoot: string | null;
    selectionSource: 'auto' | 'project_setting' | 'user' | null;
    selectedAt: string | null;
    detectorVersion: string;
    outdated: boolean;
    detectedAt: string | null;
};
