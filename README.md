# API Engine — Laravel API Documentation Generator

Laravel 13 + React/TypeScript + Inertia. Build plan and contracts: [development-plan/](development-plan/README.md).

## Local setup

Requires PHP 8.3+ (with `pdo_mysql`, `pcntl`, `posix`), Node 22+, and MySQL 8.4+ (phpMyAdmin optional).

```bash
mysql -uroot -e "CREATE DATABASE api_engine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
composer setup      # install, copy .env.example → .env, key, migrate, build assets
composer dev        # runs Laravel's dev processes (php artisan dev)
composer queue      # queue worker only; use this when Herd serves the web app
```

Redis is optional locally: queues use the `database` driver until `QUEUE_CONNECTION=redis`. Then run Horizon (`php artisan horizon`) instead of `queue:work`.

## Verified commands

| Purpose                      | Command                                        |
| ---------------------------- | ---------------------------------------------- |
| PHP tests (SQLite in memory) | `php artisan test`                             |
| React tests                  | `npm run test`                                 |
| PHP style                    | `vendor/bin/pint --test`                       |
| Static analysis              | `vendor/bin/phpstan analyse --memory-limit=1G` |
| TS types / lint + format     | `npm run types:check` / `npm run check`        |
| Everything CI runs           | `composer ci:check`                            |

CI ([.github/workflows/tests.yml](.github/workflows/tests.yml)) runs migrations and the test suite on a temporary MySQL service, and fails on lint, types, tests, the build, or high-severity `composer`/`npm audit` findings.

## Health

- `GET /health`: liveness. Public, touches no dependency.
- `GET /ready`: readiness. Platform admins only (403 for other users). Returns `200` or `503` with `ok`/`fail` per dependency (database, queue, snapshot storage). Failure details go to the log, never the response.

## Conventions (P00)

- **Modules:** domain code in `app/Domain/<Module>/`; cross-module interfaces and versioned DTOs in `app/Contracts/`. Dependency direction follows [SHARED_CONTRACTS.md](development-plan/SHARED_CONTRACTS.md) §2.
- **Identifiers:** new domain models use `HasUuids` (ordered UUIDv7). Timestamps are UTC (`config/app.php`).
- **Contract versions:** every JSON contract payload carries `schema_version`; v1 only gains backward-compatible fields.
- **Errors:** expected failures extend `App\Domain\Shared\DomainError` with a stable `errorCode()`. JSON clients get `{error: {code, message, correlation_id}}`; Inertia pages go back with an error toast.
- **Feature flags:** add each flag to `config/features.php`, default off. Check with `App\Support\Features::enabled()` or guard routes with `feature:<name>` (404 while off).
- **Correlation IDs:** every request gets `X-Correlation-ID`, reusing the caller's only if it's a UUID. It's attached to every log entry and carried into queued jobs via `Context`.
- **Queues:** `default`, `imports`, `discovery`, `analysis`, `ai` (Horizon supervisor in `config/horizon.php`). Jobs dispatch after the DB commit.
- **Storage:** imported source and exports go on the private `snapshots` disk only, never `public`.
- **UI states:** use `EmptyState`, `ErrorPanel` and `Progress` from `resources/js/components/` for empty, error and loading states. Flash toasts go through `Inertia::flash('toast', …)`.

## First account

Public signup is closed. Create the first owner (and their workspace) on any server:

```bash
php artisan app:create-owner --platform-admin   # prompts for name, email, workspace and password
```

Everyone else joins through an email invitation from a workspace Owner or Maintainer. Workspace features sit behind `FEATURE_WORKSPACE_MANAGEMENT=true`.

## Conventions (P01: tenancy and authorization)

- **One permission matrix:** `App\Domain\Workspaces\WorkspaceRole::can(Capability)`. Never re-implement role checks in controllers or React. The UI gets `currentWorkspace.can` from the server.
- **Authorize with the policy:** `Gate::authorize('perform', [$workspace, Capability::X])`. Non-members get **404**, so they never learn the workspace exists; members without the capability get **403**.
- **Scope every query:** workspace-owned models use the `BelongsToWorkspace` trait and `->forWorkspace($workspace)`. Nested routes use `scopeBindings()`, so a child ID from another workspace returns 404. A missing model and another tenant's model return the same 404 body.
- **Memberships** are created only by `WorkspaceService::addMember()`, and never mass-assigned. Owner rules (a last owner must remain; only owners manage owners) live in `MembershipService` and run under row locks.
- **Audit:** use `AuditLog::record($event, $workspace, $actor, $target, [...allowlisted metadata])`. Never pass a request body; keys that look like secrets are dropped.
- **Ops-only surfaces** (`/ready`, Horizon) use the `platform-admin` gate (`users.is_platform_admin`).

## Conventions (P02: projects)

- **Projects** belong to a workspace. They are created in the user's _current_ workspace, and only `ProjectService` writes them; they are never mass-assigned.
- **Visibility:** `workspace` means every member can see the project; `restricted` limits it to Owners, Maintainers and its creator. The rule lives in `Project::isVisibleTo()` and `scopeVisibleTo()`. Listings use the scope; `ProjectPolicy` uses the method. Anyone who may not see a project gets 404.
- **Concurrent edits:** every form sends `lock_version`. A stale write fails with `project.stale` (409) instead of overwriting.
- **Archive:** archived projects are read-only, and restoring returns them to the status they had before.
- **Wizard choices** come from `ProjectOptions`. Options marked unavailable are shown as "not yet supported", and the server rejects them. The wizard stores preferences only: no credentials, and it never starts an import or a scan.
- Features sit behind `FEATURE_PROJECT_WIZARD=true`, which also needs `FEATURE_WORKSPACE_MANAGEMENT`.

## Conventions (P03: source snapshots and jobs)

- **One import path:** every source provider (fixture now, ZIP in P04, Git in P09) implements `App\Contracts\Sources\SourceProvider`. It stages files with `SnapshotImporter::begin()` and returns a `SourceImportResult`. Consumers get a snapshot ID only, never paths, branches or credentials.
- **Immutable once ready:** a `SourceSnapshot` with status `ready` cannot be updated or deleted. Model events refuse it, and every query-level write adds `status != ready`.
- **Content-addressed storage:** file content is stored by SHA-256 under a server-generated key on the private `snapshots` disk. Uploaded paths are manifest data only and never touch the filesystem. `storage_key` never leaves the server.
- **Excluded, never stored:** secrets (`.env*` except `.env.example`, `auth.json`, `*.pem`, `*.key`), `vendor/`, `node_modules/`, `.git/`, configured Laravel-root folders such as `public/`, logs, binaries and oversized files. They are listed in the manifest with a reason, and they don't affect the manifest hash, so identical code dedupes to one snapshot.
- **Jobs:** the payload is the run ID only. A worker claims the run before working, so two workers never process the same run. An identical import while one is running returns that run. Failures store a fixed `failure_code`; details go to the log. Import timeout (1800s) < Horizon timeout (1830s) < queue `retry_after` (1900s).
- **Local workers:** `composer dev` listens on `default,imports,discovery,analysis,ai`. When Herd serves the web app, keep `composer queue` running separately. Do not use `QUEUE_CONNECTION=sync`: imports can exceed the web request timeout.
- **Try it:** use the "Import demo fixture" button on a project page (local/testing only), or `php artisan app:import-fixture <project-id> --as=<email>`.
- **Clean-up:** `app:prune-sources` runs daily. It fails abandoned runs and deletes expired quarantine folders and failed-snapshot files. It never touches ready snapshots.
- Features sit behind `FEATURE_SOURCE_SNAPSHOTS=true`, which needs `FEATURE_PROJECT_WIZARD` and `FEATURE_WORKSPACE_MANAGEMENT`.

## Conventions (P04: ZIP import)

- **Resumable, chunked upload** for archives up to **10 GB**. The browser sends 50 MB pieces in order:
    - `POST /projects/{project}/uploads` with `{total_bytes, file_name}` starts a session.
    - `PUT …/uploads/{upload}/chunk?offset=N` sends a raw piece. It's accepted only if `N` equals the bytes received so far.
    - `GET …/uploads/{upload}` returns where to resume.
    - `POST …/uploads/{upload}/complete` starts the import.
    - A dropped connection or page reload resumes from the last piece the server received. Only the uploader can use their session. Unfinished uploads expire after 24h and are deleted by `app:prune-sources`.
- **The worker unpacks; no request ever opens the archive.** The archive moves to quarantine as `runs/<run>/archive.zip`, and the client's file name is never used.
    - `ZipExtractor` checks every entry from the central directory and reads entries by index, never by name.
    - It rejects: not a ZIP, damaged or encrypted archives, zip-slip/absolute/NUL paths, names over 255 bytes, file/folder or case-only clashes, too deep, too many entries, files or folders, compression-ratio bombs, lying headers, and slow extraction.
    - Each failure has a fixed message in `SourceRejection`; entry names never reach messages or responses.
- **Never extracted:** symbolic links (e.g. `public/storage` from `storage:link`) and device/pipe entries, which are skipped, never recreated; secrets, `vendor/`, `node_modules/`, `.git/`, configured folders such as the Laravel root's `public/` (one manifest entry per folder), media and executables, logs, caches and sessions, databases, oversized files, and nested archives. Wrapper folders named `public` are preserved; configured folders are ignored only beneath a detected Laravel root. `vendor` and `node_modules` match lowercase only, so app folders such as `Controllers/Api/V1/Vendor` are kept. _Deviation from the plan:_ nested archives are excluded instead of rejecting the whole upload, so normal repositories containing `composer.phar` or test archives still import.
- **Nothing uploaded is ever executed:** no `include`, Composer, artisan or shell. Files are written with mode 0644. A sentinel test proves it.
- **Limits** (`config/sources.php`): 10 GB archive, 1 GB / 100,000 kept files per snapshot, 2M archive entries. The import job timeout is 30 min, below Horizon (1830s) and below queue `retry_after` (1900s).
    - **Server:** PHP `post_max_size` and nginx `client_max_body_size` only need to exceed one piece (e.g. `64M`), not the whole archive.
    - **Disk:** quarantine needs room for the archive plus up to 1 GB extracted, per concurrent import.
- Features sit behind `FEATURE_ZIP_IMPORT=true`, which also needs `FEATURE_SOURCE_SNAPSHOTS`.

## Conventions (P05: framework detection)

- `LaravelDetector` implements `App\Contracts\Analysis\FrameworkAnalyzer::detect()`. It reads a ready snapshot through `SnapshotReader`, as text only: no Composer, artisan or `include`. P06 and P07 add `discover()` and `analyze()` to the same interface.
- **Result statuses:**
    - `detected`: one clear app, root selected automatically.
    - `partial`: several apps, or incomplete or unparseable metadata. A person chooses; there is no arbitrary default.
    - `unsupported`: PHP without Laravel, or Lumen.
    - `not_found`: no `composer.json`.
- **Versions:** the declared constraint (`^11.0`) and the version locked in `composer.lock` are stored separately, and a constraint is never shown as the installed version. A folder that requires Laravel but has no `artisan` or `bootstrap/app.php` is a package, not an app.
- **Stored per (snapshot, detector version)** in `framework_detections`. The facts are immutable; only the root choice changes (`selected_root`, plus `root_selection_source`: `auto`, `project_setting` or `user`). Re-running with the same detector version is a no-op.
- **The root** must be a folder inside the snapshot. `..`, absolute paths and invented folders are rejected before saving. P06 reads `selected_root`.
- **When detection runs:** automatically after each import, through the `SnapshotReady` event and a queued listener on the `discovery` queue, or by hand from the snapshot page. Sources never depends on Framework.
- Features sit behind `FEATURE_FRAMEWORK_DETECTION=true`, which also needs `FEATURE_SOURCE_SNAPSHOTS`.
- **Rate limits:** never put a bare `throttle:N,1` on a route, because it shares one counter per user across routes. Add a named limiter in `AppServiceProvider::configureRateLimits()`.

## Conventions (P06: route discovery)

- **Nothing is run.** `RouteDiscoverer` reads `bootstrap/app.php` (`withRouting`, including `apiPrefix`), `RouteServiceProvider` (`boot()`/`map*()`, `$this->routes()`, `$namespace`) and module providers as ASTs (nikic/php-parser). `RouteEvaluator` interprets the routing calls as data.
    - It never touches `bootstrap/cache/routes-*.php`: those are serialized, and unserializing them is unsafe.
    - Includes resolve to snapshot paths inside the application root, with cycle and depth guards.
- **Ground truth:** `RouterParityTest` registers `tests/Fixtures/routes/corpus-api.php` in Laravel's real `Router`, and the static result must match it exactly (methods, URI, name, handler, domain, middleware). Extend that corpus whenever the analyzer learns a new pattern.
- **Never guessed:**
    - Dynamic URIs and unfollowable includes become findings.
    - Closure handlers are `unresolved`.
    - Conditional routes and controller strings without a namespace are `partial`.
    - Auth is `required` (an `auth`, `auth:*` or `auth.basic` middleware) or `unknown`. It is never "none", because custom middleware may enforce auth.
- **Identity:** `route_identity` = sha256(sorted methods, domain, URI), stable across scans and analyzer versions. A later identical registration wins, like Laravel's router, and is noted as a finding.
- **Scans:** one per (snapshot, analyzer version, root). Completed and partial scans are final; a failed scan re-queues the same row. Endpoints, findings and status are written in one transaction. A parse error in one file gives a `partial` scan that keeps the other files. `app:prune-sources` fails abandoned scans.
- **Triggers:** detection fires `ApplicationRootSelected` (auto-selected or chosen by a person), and `ScanRoutesOnRootSelected` starts the scan on the `discovery` queue. There is also a manual button, throttled by the named `route-scan` limiter.
- **API Explorer** (`/projects/{project}/endpoints`):
    - It shows the scan of the latest completed import's snapshot and its current root. It defaults to the API group.
    - It filters by method, group, auth and status; LIKE search terms are escaped with `ESCAPE '!'`, which behaves the same on MySQL and SQLite.
    - Source path, line and original expression are shown only to `viewSource` roles.
- Features sit behind `FEATURE_ROUTE_DISCOVERY=true`, which also needs `FEATURE_FRAMEWORK_DETECTION`.

## Conventions (P07: endpoint contract analysis)

- **Nothing is run.** `EndpointAnalyzer` (`laravel-ast-v1`) resolves the controller through `ClassIndex`: the app's and modules' composer.json PSR-4 maps, then a filename fallback, always checking that the file declares that exact class. Traits and parents are followed (depth 6, at most 80 files, 256 KB each).
- **What it reads:**
    - Path parameters from the URI, and route-model bindings (inferred 404).
    - FormRequest `rules()` and `authorize()`.
    - Inline `$request->validate`, `$this->validate`, `Validator::make` / `validator()`. Rules may come from a literal, from variables built in the action, from `array_merge`, or from a `$this->method()` returning a literal.
    - Input reads (`input`, `query`, `file`, `header`, `$request->x`, `request('x')`).
    - Route and controller middleware, including `->only/->except` and `HasMiddleware`.
    - Returned responses: `response()->json(…, N)`, `noContent`, `abort*`, JSON resources with their `toArray()` keys, and arrays.
- **verified / inferred / unknown, never guessed.**
    - A status code typed in the code is verified. 401/403/404/422/429 from Laravel's own behavior are inferred.
    - A key read from the request is a field whose requiredness is `unknown`: a read is not a rule. `query()` and `file()` are verified locations; `input()` is `query_or_body`.
    - `Validator::make` doesn't throw, so no 422 is added; the response is whatever the code returns.
    - Closures, custom rule objects and `Rule::requiredIf` are unknown without hiding a field's other verified rules.
    - `RuleParityTest` checks static rules against Laravel's own `ValidationRuleParser` for trusted fixtures.
- **Authentication is read from the middleware's code** (`laravel-ast-v2`), never from its name:
    - `MiddlewareMap` builds the stack Laravel would run:
        - global middleware, groups and aliases from `bootstrap/app.php` (`withMiddleware`), `app/Http/Kernel.php` (Laravel ≤ 10) and module providers (`aliasMiddleware`), on top of Laravel's defaults;
        - `withoutMiddleware` is applied by resolved class, like the router (recorded per endpoint since this release).
    - `MiddlewareInspector` follows every path through `handle()` that reaches `$next`, tracking what is true on each side of every `if`, negation and `&&`/`||`. Verdicts:
        - _authenticates_: every path verifies a credential (guard user, token lookup, `exists:` on a token field, `hash_equals`/`Hash::check`);
        - _presence_only_: a credential must be sent but some path never verifies it;
        - _guest_or_user_: a credential or a plain value such as `guest_id`;
        - _optional_: guests pass, a user is attached when sent;
        - _conditional_: some path skips the check for another reason;
        - _none_;
        - _unknown_: unreadable class, unregistered alias, or a 401 decided elsewhere.
    - Laravel's `Authenticate` (and subclasses) uses the guard driver from `config/auth.php`.
    - The action itself counts too: an up-front `if (! auth()->user()) return …401/403` makes it required. A refusal deeper in the action that depends on the user means _can't tell_. Login actions (`attempt`) are excluded.
    - Endpoint status, in this order:
        - `required`: any unconditional _authenticates_;
        - `required_unverified`;
        - `guest_or_user`;
        - `unknown` ("Can't tell"): any unreadable or conditional piece;
        - `not_required`: only when every middleware was read and none checks credentials.
    - Results from `laravel-ast-v1` (`not_detected`) stay valid and are shown as "Can't tell, analyze again".
    - The API Explorer badge and auth filter use each endpoint's current analysis (`endpoint_analyses.auth_status`, current analyzer version only). Endpoints never analyzed are "Not analyzed", never guessed from middleware names. With the flag off, the Explorer falls back to discovery's name-based value.
    - QA and Viewer see auth statuses and notes without source line numbers.
- **Contract (`laravel-ast-v6`): payload, curl, every success and error body.** `Shape\Interpreter` is an abstract interpreter that evaluates the app's PHP over typed shapes instead of values. Nothing is executed, included or autoloaded.
    - **What it follows:**
        - `$this`/static/global-function calls, memoized and depth-capped;
        - container-built controllers and services, with constructor injection;
        - `config()` (parsed from `config/*.php`) and English lang files;
        - class constants and static properties;
        - Eloquent models: columns from the installer's SQL dump plus migrations, with casts, `$hidden`/`$visible`/`$appends`, accessors and relations;
        - JSON resources (`toArray`, `parent::toArray`, `when`/`whenLoaded`/`mergeWhen`, collections, pagination);
        - caches (`Cache::remember` returns its callback's value).
    - **Branches:** a condition decidable from the shapes takes one branch; otherwise both are kept as a union. Loops over constant arrays are unrolled.
    - **`Shape\Pipeline` follows a request like the HTTP kernel:**
        - the action's returns;
        - exceptions rendered by the app's own `withExceptions()->render()` callbacks, else Laravel's production defaults (`FrameworkRenderingParityTest`);
        - every response carried back out through middleware that rewrites it (for example an envelope middleware);
        - middleware rejections, FormRequest `failedValidation` overrides, Authenticate 401, model-binding 404, throttle 429, maintenance 503, 405 and 500.
    - **Values:** every value is _exact_ (stated by the code), an _example_ (typed by the code; the value is database or request data), or _unread_ (a typed placeholder plus the reason; it counts as a hole). `Shape\Examples` is deterministic: rules first (`in:`, `min`, formats), then field-name conventions.
    - **`curl`:** `{{base_url}}` is the only placeholder. The UI fills it from a per-project base URL kept in the browser.
    - **Accuracy:** `ContractTest` runs the same trusted fixture code in Laravel and requires the static body to equal what Laravel sends.
- **Result:** `AnalysisResultV1` (SHARED_CONTRACTS §6, schema `1.0`), checked by `AnalysisResultV1::problems()` before storage. A malformed result fails as `invalid_result` and is never saved as complete.
    - Every fact cites evidence ids (`ev-N`): path, content sha256, symbol and line range in the immutable snapshot.
    - Anything not read goes to `unresolved` and becomes an `analysis_findings` row. An analysis with any unknown is `partial`.
- **Lifecycle:** one row per fingerprint in `endpoint_analyses`: snapshot, root, route identity, what discovery resolved (its version and handler) and the analyzer version. A rescan that resolves the handler differently gets a fresh analysis.
    - A repeated click returns the row. A failed one is re-queued, a queued one whose job was lost is dispatched again, and completed and partial are final.
    - `AnalyzeEndpointJob` runs on the `analysis` queue (timeout 120s, dispatched after commit).
    - The result, evidence and findings are written in one transaction.
    - `app:prune-sources` fails abandoned analyses. `ProjectDeleter` removes them.
- **Access:**
    - Analyze: Owner, Maintainer, Developer (`analyze` policy, named `endpoint-analysis` limiter).
    - QA and Viewer see the contract but no evidence (paths, hashes, line ranges) and no source line numbers (`AnalysisPresenter`). Class and method names stay, as in the API Explorer.
    - `GET /projects/{project}/analyses/{analysis}/evidence/{ev-N}` returns the exact lines (±3, at most 200) to `viewSource` roles only. Other workspaces and projects get a uniform 404.
- Features sit behind `FEATURE_ENDPOINT_ANALYSIS=true`, which also needs `FEATURE_ROUTE_DISCOVERY`.

## Conventions (P08: static documentation)

- **Built only from the stored analysis.** `StaticDocumentationBuilder` (`static-docs-v1`) maps one validated `AnalysisResultV1`, plus the endpoint's method, URI, name and handler from discovery, to a document. It uses no AI, no network and no uploaded source. It is pure: the same input gives the same document, and nothing time- or user-dependent goes into it.
- **Sections:** overview, request (path, query and body fields, plus the contract payload), headers, validation, cURL, responses, errors and unknowns. Documentation shows no evidence (`static-docs-v2`); source evidence stays in the analysis panel for roles that may read source.
    - Banners are stable codes in `document.notices`: `partial`, `legacy_result`, `budget_reached` and `auth_unknown`. The page turns them into text.
    - Example and unread values keep their `notes`, so `JsonView` still marks them.
- **Results without a `contract`** (analyzers before `laravel-ast-v6`) still give honest docs: fields, headers, auth, statuses and unknowns. They carry a `legacy_result` notice ("Analyze again for full response bodies").
- **cURL:** the contract's command is used as-is. Otherwise one is built:
    - placeholders only: `{{base_url}}`, path `{param}` → `<param>`, `<type>` values and `Bearer <token>`;
    - single-quoted with `'` → `'\''`;
    - a header value is copied only when it is already a placeholder or the header is benign (Accept, Content-Type); any other value could be a literal secret, so it becomes `<header-name>`.
- **Drafts** (`documentation_versions`) are immutable and generated synchronously in one transaction:
    - one per (analysis, builder version), enforced by a unique key, so a repeated click returns the existing draft;
    - a newer analysis gets a new draft beside the old ones;
    - each draft stores the snapshot, analysis, analyzer and builder versions, `contract_checksum` (sha256 of the result as canonical JSON, with keys sorted) and the document.
    - Documents carry no evidence, so there is no `documentation_evidence` table. Drafts from `static-docs-v1` that stored evidence have it stripped when shown, for every role.
- **Routes** (behind every earlier flag plus `static_docs`, with scoped bindings):
    - `POST /projects/{project}/analyses/{analysis}/documentation` generates a draft. It needs `generateDocs` (`Capability::SubmitDocs`) and the named `static-docs` limiter. Archived projects are refused, and so are analyses that are not completed or partial.
    - `GET /projects/{project}/endpoints/{endpoint}/documentation` shows the newest draft for the route identity.
    - `GET /projects/{project}/documentation/{documentation}` shows one draft and lists the others, newest first.
- **Access:** anyone who can view the project can read drafts. `DocumentationPresenter` removes from what QA and Viewer see:
    - source line numbers, using the same patterns as `AnalysisPresenter`;
    - the snapshot hash and the checksum.
- `ProjectDeleter` removes drafts before analyses.
- Features sit behind `FEATURE_STATIC_DOCS=true`, which also needs `FEATURE_ENDPOINT_ANALYSIS`.

## Deleting projects

- `DELETE /projects/{project}` with `confirm_name` set to the exact project name. Works for active, draft and archived projects.
- Owners and Maintainers only (`Capability::DeleteProjects`); Developers can archive but not delete.
- Refused with `project.busy` (409) only while a worker is actively running an import, route scan or endpoint analysis (claimed within the job timeout). Unfinished uploads and queued work are discarded with the project.
- `ProjectDeleter` removes, in one transaction: sources, snapshots and their manifests, runs, upload sessions, detections, scans, endpoints, analyses (with their evidence), findings and documentation drafts. It then deletes the project's stored snapshot files and quarantine leftovers.
    - It is the only code allowed to remove ready snapshots, and it deliberately uses query-level deletes past the model guard.
    - An audit event `project.deleted` with the name and slug is kept.
