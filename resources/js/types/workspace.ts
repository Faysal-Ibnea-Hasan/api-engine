export type WorkspaceSummary = {
    id: string;
    name: string;
    roleLabel: string;
};

/** Capabilities are computed server-side from the one permission matrix. */
export type CurrentWorkspace = {
    id: string;
    name: string;
    role: string;
    roleLabel: string;
    can: Record<string, boolean>;
};

export type RoleOption = {
    value: string;
    label: string;
};
