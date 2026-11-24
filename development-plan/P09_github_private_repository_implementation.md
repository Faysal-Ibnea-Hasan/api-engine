# P09 — Multi-tenant GitHub Private Repository Integration

> Detailed implementation guide for connecting one API Engine GitHub App to many customer GitHub accounts and importing selected public or private repositories without write access.

**Status:** implementation plan  
**Scope:** GitHub only, manual pull/import first  
**Feature flag:** `git_import`  
**Depends on:** workspace RBAC, projects, immutable source snapshots, secure ZIP import, queue workers  
**Related guide:** [P09 — Authenticated Git Repository Import](P09_git_repository_import.md)

## 1. Target result

After this plan is implemented:

1. An authenticated API Engine workspace Owner or Maintainer clicks **Connect GitHub**.
2. GitHub authenticates that person and installs the API Engine GitHub App on a user or organization account.
3. The person grants read access to selected repositories.
4. API Engine verifies the installation and links it to the initiating workspace.
5. An authorized workspace member selects a repository and a branch, tag, or commit.
6. API Engine resolves the ref to an immutable commit SHA.
7. A queue worker downloads a ZIP archive for that SHA using a short-lived installation token.
8. The existing `SnapshotImporter` validates it and creates the same immutable `SourceSnapshot` produced by ZIP upload.
9. Existing framework detection, discovery, analysis, and documentation run unchanged.

API Engine never commits, pushes, opens pull requests, or modifies repository settings.

## 2. Identity model

There is one GitHub App owned by the API Engine operator. Its application credentials are global server secrets.

Each GitHub account or organization that connects creates a separate GitHub installation:

```text
API Engine GitHub App
├── installation 501 → 6amMart organization
│   ├── 6amtech/6ammart-api
│   └── 6amtech/6ammart-admin
└── installation 702 → StackFood organization
    ├── stackfood/stackfood-api
    └── stackfood/stackfood-admin
```

An API Engine workspace is linked to an installation only after an authenticated workspace member completes and verifies the GitHub authorization flow.

### 2.1 Global/static information

Keep these in the server environment or secret manager:

```dotenv
GITHUB_APP_ID=
GITHUB_APP_CLIENT_ID=
GITHUB_APP_CLIENT_SECRET=
GITHUB_APP_SLUG=api-engine
GITHUB_APP_PRIVATE_KEY_PATH=/run/secrets/api-engine-github-app.pem
GITHUB_APP_CALLBACK_URL=https://api-engine.example.com/integrations/github/callback
GITHUB_API_URL=https://api.github.com
```

These values identify API Engine's GitHub App. They are not customer credentials.

### 2.2 Tenant-specific information

Persist these in MySQL:

- API Engine workspace ID
- GitHub installation ID
- GitHub account ID, login, and account type
- connecting API Engine user ID
- selected GitHub repository ID and full name
- selected ref type and ref name
- resolved commit SHA for every import

Do not persist installation access tokens. Generate them on demand; GitHub installation tokens are short-lived.

## 3. One-time GitHub App registration

Create the app under the GitHub account or organization that operates API Engine:

```text
GitHub Settings
→ Developer settings
→ GitHub Apps
→ New GitHub App
```

Configure:

| Setting | Value |
|---|---|
| App name | API Engine |
| Homepage URL | Production API Engine URL |
| Callback URL | `/integrations/github/callback` |
| Request user authorization during installation | Enabled |
| Webhooks | Disabled for the manual-import MVP |
| Installation availability | Any account for multi-vendor SaaS |
| Repository Contents permission | Read-only |
| Repository Metadata permission | Read-only |
| All write permissions | None |

Generate a private key, store it outside the repository with mode `0600`, and record the App ID, Client ID, and Client Secret.

Official references:

- [Choosing GitHub App permissions](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/choosing-permissions-for-a-github-app)
- [Authenticating as an installation](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/authenticating-as-a-github-app-installation)
- [GitHub App setup URL security](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/about-the-setup-url)

## 4. Add configuration and the feature flag

### 4.1 Files

Create:

```text
config/github.php
```

Update:

```text
config/features.php
.env.example
README.md
```

### 4.2 Configuration shape

`config/github.php` should expose:

```php
return [
    'app_id' => env('GITHUB_APP_ID'),
    'client_id' => env('GITHUB_APP_CLIENT_ID'),
    'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
    'slug' => env('GITHUB_APP_SLUG'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    'callback_url' => env('GITHUB_APP_CALLBACK_URL'),
    'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
    'connect_ttl_minutes' => 10,
    'http_timeout_seconds' => 30,
    'archive_timeout_seconds' => 300,
];
```

Add to `config/features.php`:

```php
'git_import' => (bool) env('FEATURE_GIT_IMPORT', false),
```

Fail readiness or disable the feature when required GitHub settings are missing. Never print the client secret or private key in readiness output.

## 5. Database design

Use additive migrations. All tenant-owned records must carry `workspace_id` and use the repository's `BelongsToWorkspace` conventions where appropriate.

### 5.1 `github_connection_attempts`

Stores short-lived OAuth state and binds the callback to the authenticated API Engine user and workspace.

```text
id UUID primary key
state_hash CHAR(64) unique
workspace_id UUID foreign key
user_id BIGINT foreign key
expires_at TIMESTAMP
consumed_at TIMESTAMP nullable
created_at / updated_at
```

Store only `hash('sha256', $state)`; send the raw random state to GitHub. Consume it exactly once.

### 5.2 `github_installations`

Represents a GitHub App installation. This is provider identity, not source code.

```text
id UUID primary key
github_installation_id BIGINT unsigned unique
github_account_id BIGINT unsigned
account_login VARCHAR(255)
account_type VARCHAR(30)          # User or Organization
repository_selection VARCHAR(20) # selected or all
permissions JSON nullable
suspended_at TIMESTAMP nullable
last_verified_at TIMESTAMP nullable
created_at / updated_at
```

### 5.3 `workspace_github_installations`

Explicitly authorizes one installation for one API Engine workspace.

```text
workspace_id UUID foreign key
github_installation_id UUID foreign key to github_installations.id
connected_by BIGINT nullable foreign key to users.id
created_at / updated_at

primary/unique: workspace_id + github_installation_id
```

Never infer workspace ownership from GitHub account names.

### 5.4 `github_repository_sources`

Connects a project's existing `ProjectSource` of type `git` to one repository.

```text
id UUID primary key
workspace_id UUID foreign key
project_id UUID foreign key
project_source_id UUID unique foreign key
github_installation_id UUID foreign key
github_repository_id BIGINT unsigned
owner_login VARCHAR(255)
repository_name VARCHAR(255)
full_name VARCHAR(512)
visibility VARCHAR(20)
default_branch VARCHAR(255)
tracked_ref_type VARCHAR(20) nullable # branch, tag, commit
tracked_ref VARCHAR(255) nullable
last_imported_sha CHAR(40) nullable
last_imported_at TIMESTAMP nullable
created_by BIGINT nullable
created_at / updated_at

unique: workspace_id + project_id
index: github_installation_id + github_repository_id
```

### 5.5 `github_imports`

Records the immutable Git origin for each processing run and snapshot.

```text
id UUID primary key
workspace_id UUID foreign key
project_id UUID foreign key
github_repository_source_id UUID foreign key
processing_run_id UUID nullable unique foreign key
source_snapshot_id UUID nullable foreign key
requested_ref VARCHAR(255)
ref_type VARCHAR(20)
commit_sha CHAR(40)
commit_message VARCHAR(1000) nullable
committed_at TIMESTAMP nullable
status VARCHAR(20)
failure_code VARCHAR(40) nullable
created_by BIGINT nullable
created_at / updated_at

unique: project_id + github_repository_source_id + commit_sha
```

Do not put provider tokens, OAuth codes, archive URLs, or private keys in these tables.

## 6. Models and relationships

Create:

```text
app/Models/GitHubConnectionAttempt.php
app/Models/GitHubInstallation.php
app/Models/GitHubRepositorySource.php
app/Models/GitHubImport.php
```

Add the workspace-installation pivot relationship to `Workspace`.

Add to `ProjectSource`:

```text
githubRepositorySource(): HasOne
```

Add to `SourceSnapshot`:

```text
githubImport(): HasOne
```

Use UUIDs consistently with the existing source models. Hide any internal/provider-only fields that should not enter Inertia props.

## 7. Authorization rules

Add a capability such as:

```text
ManageIntegrations
```

Recommended permissions:

| Action | Capability |
|---|---|
| Connect/disconnect a GitHub installation | Owner/Maintainer: `ManageIntegrations` |
| Select repository for a project | `ManageProjects` |
| Pull/import a repository version | `ImportAndAnalyze` |
| View repository metadata | Project `view` |
| View imported source | `ReadSourceEvidence` |

Every controller must authorize the workspace or project before reading installation or repository records. A cross-workspace ID must return 404, matching the existing project policy behavior.

## 8. GitHub client services

Create these classes:

```text
app/Domain/GitHub/GitHubAppJwt.php
app/Domain/GitHub/GitHubApiClient.php
app/Domain/GitHub/GitHubConnectionService.php
app/Domain/GitHub/GitHubInstallationTokens.php
app/Domain/GitHub/GitHubRepositories.php
app/Domain/GitHub/GitHubError.php
```

### 8.1 `GitHubAppJwt`

Responsibilities:

1. Load the private key from the configured path.
2. Generate a short-lived RS256 JWT containing `iat`, `exp`, and `iss`.
3. Keep the JWT lifetime within GitHub's accepted limit.
4. Never log the JWT or private key.

Prefer a maintained JWT library rather than hand-written OpenSSL/JWT encoding. Lock and audit the dependency.

### 8.2 `GitHubInstallationTokens`

Responsibilities:

1. Generate an App JWT.
2. Call:

```http
POST /app/installations/{installation_id}/access_tokens
Authorization: Bearer {app-jwt}
```

3. Request read-only `contents` permission and, where useful, restrict the token to the selected repository ID.
4. Cache the installation token encrypted for slightly less than its GitHub expiry.
5. Never store it in MySQL, job payloads, logs, exceptions, or Inertia props.

### 8.3 `GitHubApiClient`

Use Laravel's HTTP client with:

- `Accept: application/vnd.github+json`
- an explicit GitHub API version header
- connect and total timeouts
- bounded retries for 429 and transient 5xx responses
- no retry for 401, 403, or 404 without first refreshing the installation token
- response-size limits for JSON endpoints
- token redaction from exceptions and logs

Centralize all GitHub requests here. Controllers and jobs must not assemble arbitrary provider URLs.

## 9. Secure connection flow

### 9.1 Start connection

Route:

```text
POST /workspaces/{workspace}/integrations/github/connect
```

Controller flow:

1. Require authenticated and verified API Engine user.
2. Authorize `ManageIntegrations` for the workspace.
3. Generate at least 32 random bytes for OAuth `state`.
4. Store its SHA-256 hash with workspace ID, user ID, and ten-minute expiry.
5. Redirect to the GitHub App installation/authorization URL with the raw state.

### 9.2 Callback

Route:

```text
GET /integrations/github/callback
```

Callback flow:

1. Require the same authenticated API Engine session.
2. Hash the returned state and lock the matching attempt row.
3. Reject missing, expired, consumed, or wrong-user state.
4. Exchange the one-time GitHub authorization code for a GitHub user access token.
5. Use that user token to list the user's installations.
6. Verify the returned installation ID belongs to that GitHub user and this GitHub App.
7. Fetch installation/account metadata with App authentication.
8. Upsert `github_installations`.
9. Insert the workspace pivot using the workspace stored in the connection attempt.
10. Mark the attempt consumed.
11. Discard the user token unless a later feature explicitly requires persistent GitHub-user access.
12. Audit `github.installation_connected` with allowlisted IDs only.

Do not trust `installation_id` from the browser or callback by itself. GitHub documents that setup callbacks can be called with spoofed installation IDs.

### 9.3 Disconnect

Route:

```text
DELETE /workspaces/{workspace}/integrations/github/{installation}
```

Disconnect only the workspace pivot. Do not delete immutable snapshots or published documentation. Prevent new pulls from projects that no longer have an authorized installation.

## 10. Repository and ref selection

Routes:

```text
GET  /projects/{project}/github/installations
GET  /projects/{project}/github/installations/{installation}/repositories
GET  /projects/{project}/github/repositories/{repository}/refs
PUT  /projects/{project}/github/source
```

Rules:

1. Resolve the project's workspace first.
2. Require a workspace-installation pivot.
3. Generate an installation token.
4. List repositories available to that installation.
5. Return only repository ID, full name, visibility, default branch, archived state, and permissions needed by the picker.
6. Before saving, fetch the repository by numeric ID and confirm it is still included in the installation.
7. Store numeric provider IDs as authority; names may change.

The UI should allow:

- repository selection
- branch selection
- tag selection
- direct full commit SHA
- default-branch latest

Reject arbitrary repository URLs and arbitrary hosts.

## 11. Resolve a ref to an immutable commit

Before downloading source, resolve the requested ref through GitHub:

```http
GET /repos/{owner}/{repo}/commits/{ref}
```

Persist the returned full commit SHA. All subsequent work must use that SHA, never the moving branch name.

Validate:

- repository still belongs to the authorized installation
- SHA is exactly 40 hexadecimal characters
- ref length is bounded
- repository is not disabled or inaccessible
- the same project/repository/SHA import is idempotent

Fingerprint the processing run with stable IDs, for example:

```text
github:{installation_id}:{repository_id}:{commit_sha}
```

## 12. Queue-safe archive download

Do not download a repository inside an HTTP request and do not place an installation token in a queued job.

Create:

```text
app/Jobs/FetchGitHubArchiveJob.php
app/Domain/GitHub/GitHubArchiveDownloader.php
app/Domain/Sources/GitHubSourceProvider.php
```

### 12.1 Required importer refactor

The current `SnapshotImporter::begin()` stages an archive and immediately dispatches `ImportSnapshotJob`. GitHub download must happen in a worker first.

Add a deferred-start path without breaking ZIP, for example:

```text
SnapshotImporter::beginDeferred(...)
SnapshotImporter::dispatchImport(runId)
```

Expected lifecycle:

```text
HTTP request
  → resolve SHA
  → create github_import + processing run + pending snapshot
  → dispatch FetchGitHubArchiveJob(run ID only)

FetchGitHubArchiveJob
  → load run and GitHub origin from database
  → verify workspace/installation/repository relationship again
  → generate fresh installation token
  → download ZIP into the run's quarantine folder
  → dispatch existing ImportSnapshotJob(run ID only)

ImportSnapshotJob
  → existing SnapshotImporter::process()
  → existing validation, manifest, blob storage and ready snapshot flow
```

The job payload must contain only the run ID. Resolve installation and repository data from tenant-scoped database records inside the job.

### 12.2 Download endpoint

Use GitHub's ZIP archive endpoint with the resolved SHA:

```http
GET /repos/{owner}/{repo}/zipball/{commit_sha}
Authorization: Bearer {installation-token}
```

GitHub may return a redirect. Permit redirects only to explicitly allowlisted GitHub archive hosts such as `codeload.github.com`. Reject every other scheme and host.

Stream bytes directly to the quarantine disk. Do not buffer the full archive in PHP memory.

Enforce:

- HTTPS only
- maximum compressed bytes
- download timeout
- low-speed/idle timeout where supported
- redirect count limit
- content-length limit when provided
- actual streamed-byte limit even when content length is absent
- cleanup of partial files after any failure

Store the archive using the existing `SnapshotImporter::ARCHIVE` path so the existing `ZipExtractor` and ignored-directory behavior are reused.

## 13. Reuse the immutable snapshot pipeline

`GitHubSourceProvider` must implement the existing `SourceProvider` contract and use `SourceType::Git`.

Do not create a Git-specific analyzer. After the snapshot becomes ready, these existing components should continue unchanged:

```text
SnapshotReady event
→ Laravel framework detection
→ application-root selection
→ route discovery
→ endpoint analysis
→ documentation generation
```

The same commit may deduplicate to an existing ready snapshot through manifest SHA, but its `github_imports` origin record should still show what repository/ref/commit was requested.

Never execute imported Composer scripts, Artisan commands, PHP, Node scripts, Git hooks, or repository binaries.

## 14. Errors and recovery

Create stable provider errors such as:

```text
github.not_configured
github.connection_expired
github.oauth_denied
github.installation_unverified
github.installation_suspended
github.repository_not_allowed
github.repository_not_found
github.ref_not_found
github.rate_limited
github.provider_unavailable
github.archive_too_large
github.archive_download_failed
github.permission_revoked
```

Behavior:

- Store only the stable `failure_code` on imports/runs.
- Put detailed provider responses in protected logs after removing tokens and source URLs.
- A revoked installation prevents new imports but never deletes existing snapshots.
- A retry uses the already-resolved commit SHA.
- A second request for the same active fingerprint returns the active run.
- A second completed request for the same repository/SHA returns or adopts the existing snapshot.

## 15. Inertia and React UI

Add a GitHub integration page at workspace scope:

```text
resources/js/pages/workspaces/integrations/github.tsx
```

It should show:

- GitHub connection status
- connected GitHub account/organization
- installation repository-selection mode
- Connect GitHub
- Manage on GitHub
- Disconnect
- permission/revocation errors

Add a Git Repository source option to the project wizard/source page:

```text
ZIP Upload | GitHub Repository
```

GitHub form states:

1. GitHub not connected
2. Loading installations
3. Select installation
4. Select repository
5. Select branch/tag/commit
6. Resolving commit
7. Queued download
8. Downloading/importing
9. Snapshot ready
10. Recoverable error

Display both the friendly ref and immutable SHA:

```text
main @ 4f7c18a
v2.4.0 @ 118d92c
```

Never send access tokens, OAuth codes, private keys, archive redirect URLs, or unredacted GitHub responses to React.

## 16. Proposed route files and controllers

Create:

```text
routes/github.php

app/Http/Controllers/GitHub/GitHubConnectionController.php
app/Http/Controllers/GitHub/GitHubCallbackController.php
app/Http/Controllers/GitHub/GitHubRepositoryController.php
app/Http/Controllers/GitHub/GitHubRefController.php
app/Http/Controllers/Sources/GitHubImportController.php

app/Http/Requests/GitHub/StartGitHubConnectionRequest.php
app/Http/Requests/GitHub/SelectGitHubRepositoryRequest.php
app/Http/Requests/Sources/StartGitHubImportRequest.php
```

Require `routes/github.php` from `routes/web.php`. Keep callback rate-limited and state-protected.

Controllers should authorize and delegate only. Provider communication, token generation, persistence, and import orchestration belong in domain services.

## 17. Audit events

Record allowlisted events through the existing `AuditLog`:

```text
github.connection_started
github.installation_connected
github.installation_disconnected
github.repository_selected
github.import_started
github.import_completed
github.import_failed
```

Safe metadata:

- installation numeric ID
- GitHub account login
- repository numeric ID/full name
- commit SHA
- processing run ID

Never record tokens, authorization codes, private keys, raw callback query strings, or temporary archive URLs.

## 18. Automated test plan

Use `Http::fake()` for GitHub. Do not call the real GitHub API from the normal test suite.

### 18.1 Unit tests

- App JWT contains correct issuer and bounded lifetime.
- Private key errors fail safely.
- Installation tokens are cached below provider expiry.
- API client sends required headers and redacts tokens from failures.
- Redirect allowlist accepts GitHub archive hosts and rejects everything else.
- Stream limit stops downloads larger than configured maximum.
- Branch/tag is resolved once and later download uses only commit SHA.

### 18.2 Feature tests

- Owner/Maintainer can start a connection; Developer/QA/Viewer cannot.
- Callback rejects missing, expired, consumed, spoofed, wrong-user, and wrong-workspace state.
- Callback verifies installation ownership through GitHub user authorization.
- 6amMart workspace cannot list or import StackFood installation repositories.
- Repository not included in an installation returns 404/403 without leaking its existence.
- Private repository import creates a `SourceSnapshot` through the existing importer.
- Public and private repository imports use the same snapshot contract.
- Same repository/SHA is idempotent.
- Moving `main` produces a new SHA and a new immutable snapshot.
- Revocation prevents new imports but preserves historical snapshots/docs.
- No credential appears in jobs, logs, Inertia props, audit metadata, or database JSON.

### 18.3 Parity test

Import the same fixture once through ZIP upload and once through the fake GitHub ZIP endpoint. Assert equivalent:

- manifest paths and hashes
- selected application root
- discovered routes
- endpoint analysis contracts
- generated static documentation

### 18.4 Regression commands

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test
npm run check
npm run types:check
npm run test
npm run build
```

## 19. Production queue requirements

GitHub downloads and snapshot imports require a persistent worker for the `imports` queue.

Example Supervisor command:

```ini
command=php /var/www/api-engine/current/artisan queue:work redis --queue=imports,discovery,analysis,default --sleep=2 --tries=3 --timeout=1800 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
```

Requirements:

- `retry_after` must exceed the longest worker timeout.
- Supervisor/Horizon must automatically restart exited workers.
- Every deployment must run `php artisan reload` or `php artisan queue:restart` under a process monitor.
- Production should use Redis/Horizon or another supervised queue setup; a terminal-owned worker is not sufficient.
- Monitor queue depth, download failures, GitHub 401/403/429 rates, and import duration.

## 20. Build order

Implement in this sequence:

1. Register the GitHub App with read-only permissions.
2. Add environment configuration and `git_import` feature flag.
3. Add migrations, models, factories, and tenant relationships.
4. Add `ManageIntegrations` capability and policies.
5. Implement App JWT and installation-token generation.
6. Implement the centralized GitHub API client.
7. Implement state creation, OAuth callback, and installation verification.
8. Add workspace GitHub connection UI.
9. Implement installation repository listing and repository verification.
10. Add project repository/ref selection UI.
11. Implement ref-to-commit-SHA resolution.
12. Add deferred snapshot-import start without changing ZIP behavior.
13. Implement the queued, streamed GitHub ZIP downloader.
14. Feed the archive into the existing `SnapshotImporter`.
15. Add import status/version history to the project UI.
16. Add audit events and stable errors.
17. Complete unit, feature, tenant-isolation, parity, and regression tests.
18. Deploy with the feature disabled.
19. Test one controlled private repository in staging.
20. Enable for one pilot workspace, then expand gradually.

## 21. Manual acceptance walkthrough

Use two unrelated GitHub organizations and two API Engine workspaces.

1. Sign in as a 6amMart workspace Owner.
2. Connect GitHub and select only a 6amMart private repository.
3. Import `main`; confirm a commit SHA and ready snapshot appear.
4. Generate routes, analysis, and documentation.
5. Sign in as a StackFood workspace Owner.
6. Connect a different GitHub installation and select only a StackFood repository.
7. Confirm StackFood cannot see the 6amMart installation, repository, processing run, snapshot, or evidence.
8. Import StackFood and complete the downstream workflow.
9. Advance a test branch, pull again, and confirm a new immutable snapshot is created.
10. Re-import the old SHA and confirm idempotent behavior.
11. Revoke GitHub access and confirm new pulls fail safely while historical docs remain accessible.

## 22. Definition of done

- One static GitHub App supports multiple API Engine tenants.
- Every installation is verified and explicitly linked to a workspace.
- Private repositories are readable only through short-lived, read-only installation tokens.
- API Engine never requests GitHub write permissions.
- Repository refs are resolved to immutable commit SHAs before download.
- GitHub archives pass through the existing secure ZIP/snapshot pipeline.
- No imported code is executed.
- No provider credential reaches the browser, queue payload, database history, audit event, or logs.
- Cross-workspace tests prove 6amMart and StackFood cannot access each other's installations or repositories.
- ZIP/Git parity tests pass.
- Persistent production workers process imports and reload on every deployment.
- The feature can be disabled without affecting ZIP imports or historical snapshots.

## 23. Explicitly deferred work

Do not include these in the first implementation:

- automatic push webhooks
- scheduled repository polling
- pull-request checks or comments
- GitHub write permissions
- GitLab/Bitbucket/self-hosted Git
- arbitrary SSH clone URLs or deploy keys
- storing full Git history
- executing repository build/install commands

Add those only after the manual, read-only GitHub import path is stable and security-reviewed.
