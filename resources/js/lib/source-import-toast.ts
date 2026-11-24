const trackedImportKey = (projectId: string) =>
    `source-import:pending:${projectId}`;

export const sourceImportToastId = (runId: string) => `source-import:${runId}`;

export function trackSourceImport(projectId: string, runId: string): void {
    try {
        sessionStorage.setItem(trackedImportKey(projectId), runId);
    } catch {
        // The inline status still works when browser storage is unavailable.
    }
}

export function trackedSourceImport(projectId: string): string | null {
    try {
        return sessionStorage.getItem(trackedImportKey(projectId));
    } catch {
        return null;
    }
}

export function clearTrackedSourceImport(projectId: string): void {
    try {
        sessionStorage.removeItem(trackedImportKey(projectId));
    } catch {
        // Nothing to clear when browser storage is unavailable.
    }
}
