import { Badge } from '@/components/ui/badge';
import type { ProjectSummary } from '@/types';

const labels: Record<ProjectSummary['status'], string> = {
    draft: 'Draft',
    active: 'Active',
    archived: 'Archived',
};

export default function ProjectStatusBadge({
    status,
    visibility,
}: Pick<ProjectSummary, 'status' | 'visibility'>) {
    return (
        <span className="flex gap-1">
            <Badge
                variant={
                    status === 'active'
                        ? 'success'
                        : status === 'archived'
                          ? 'outline'
                          : 'secondary'
                }
            >
                {Object.hasOwn(labels, status) && labels[status]}
            </Badge>
            {visibility === 'restricted' && (
                <Badge variant="outline">Restricted</Badge>
            )}
        </span>
    );
}
