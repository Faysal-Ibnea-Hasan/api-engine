import { Badge } from '@/components/ui/badge';
import type { EndpointResolution } from '@/types';

const labels: Record<EndpointResolution, string> = {
    resolved: 'Resolved',
    partial: 'Partial',
    unresolved: 'Unresolved',
};

export default function ResolutionBadge({
    resolution,
}: {
    resolution: EndpointResolution;
}) {
    return (
        <Badge
            variant={
                resolution === 'resolved'
                    ? 'success'
                    : resolution === 'partial'
                      ? 'warning'
                      : 'destructive'
            }
        >
            {Object.hasOwn(labels, resolution) && labels[resolution]}
        </Badge>
    );
}
