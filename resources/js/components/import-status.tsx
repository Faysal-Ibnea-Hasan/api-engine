import { Badge } from '@/components/ui/badge';
import type { RunStatus, SnapshotStatus } from '@/types';

const labels: Record<RunStatus | SnapshotStatus, string> = {
    queued: 'Queued',
    running: 'Importing',
    completed: 'Completed',
    partial: 'Partly completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
    pending: 'Pending',
    validating: 'Validating',
    ready: 'Ready',
    rejected: 'Rejected',
};

const tone: Partial<
    Record<
        RunStatus | SnapshotStatus,
        'success' | 'warning' | 'secondary' | 'destructive' | 'outline'
    >
> = {
    completed: 'success',
    ready: 'success',
    partial: 'warning',
    failed: 'destructive',
    rejected: 'destructive',
    cancelled: 'outline',
};

/** One status vocabulary for every import, scan or analysis run. */
export default function ImportStatus({
    status,
}: {
    status: RunStatus | SnapshotStatus;
}) {
    return (
        <Badge
            variant={Object.hasOwn(tone, status) ? tone[status] : 'secondary'}
        >
            {Object.hasOwn(labels, status) && labels[status]}
        </Badge>
    );
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(1)} ${units[unit]}`;
}
