<?php

namespace App\Domain\Workspaces;

/**
 * Everything a workspace member may be allowed to do. The role → capability
 * matrix lives in WorkspaceRole::can(); never re-implement it elsewhere.
 */
enum Capability: string
{
    case ManageMembers = 'manage_members';
    case UpdateWorkspace = 'update_workspace';
    case ManageProjects = 'manage_projects';
    /** Permanent removal of a project and all its imported source. Stricter than archiving. */
    case DeleteProjects = 'delete_projects';
    case ImportAndAnalyze = 'import_and_analyze';
    case ConfigureAi = 'configure_ai';
    case SubmitDocs = 'submit_docs';
    case PublishDocs = 'publish_docs';
    case ReadPublishedDocs = 'read_published_docs';
    case ReadSourceEvidence = 'read_source_evidence';
    /** Connect or disconnect provider integrations such as a GitHub App installation. */
    case ManageIntegrations = 'manage_integrations';
}
