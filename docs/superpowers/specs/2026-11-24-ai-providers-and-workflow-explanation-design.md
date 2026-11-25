# AI providers, usage limits and AI workflow explanation: design

Date: 2026-11-24. Covers plan phase P11 (AI configuration) and the AI side of P10 (advanced analysis). P12 (AI documentation enhancement) is not part of this.

## 1. Intent

The P10 workflow trace works, but the user called it "useless". A large endpoint such as `POST /api/v1/customer/order/place` produces 300 classes and 373 conditional links, and nobody can read that tree to understand what the endpoint does. The user wants AI to turn the trace into a readable Markdown document. The document explains each step, class and data change and **its purpose**, with a Preview and a Copy option.

Before that, the platform needs somewhere to configure AI. The user wants:

- **Several providers:** Anthropic, OpenAI and Google Gemini can all be set up at once.
- **Per-provider controls:** model, effort, and a usage meter.
- **The meter:** "session" means a token limit the admin sets per provider (per day or per month). API Engine counts every call against it, shows used / left / reset date, and stops AI runs with a clear message once the limit is reached.

## 2. Decisions the user made

| Decision                                        | Choice                                                                                                                                                                              |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Who owns AI settings                            | **Platform-wide.** A platform admin adds keys and limits once, and all workspaces use them, like the GitHub App settings.                                                           |
| Providers                                       | Several at once, each with its own model, effort and limit.                                                                                                                         |
| "Session"                                       | A token limit set inside API Engine per provider, daily or monthly, with used / left / resets. The provider's real account balance is not read (an ordinary API key can't read it). |
| Live rate-limit headroom ("tokens this minute") | Not shown.                                                                                                                                                                          |
| What the AI reads                               | **The trace plus the method code** of the project's own methods in the trace. Secrets are removed first. No vendor code, comments or config files. A token budget applies per run.  |
| How providers are called                        | **One small adapter per provider on Laravel's HTTP client** (`Http::`), the same pattern as `app/Domain/GitHub/GitHubApiClient.php`. No AI SDK packages.                            |
| Advanced analysis without AI                    | Not allowed. The existing trace code stays, but it only runs as part of an AI explanation, and only when AI is ready.                                                               |

## 3. Goals, non-goals, anti-goals

**Goals**

1. A platform admin can add, replace, test and remove a key for Anthropic, OpenAI and Gemini, and for each one:
    - pick a model;
    - pick an effort (Low / Medium / High);
    - turn the provider on or off;
    - set a token limit (per day or per month);
    - mark it as the default.
2. The control panel shows each provider's status and its usage meter: used, left and reset time.
3. On an endpoint's Workflow tab, a user who may analyze clicks **Explain workflow with AI**. The static trace runs, then the default provider explains it. The result is a Markdown document shown with a Preview / Markdown toggle and a Copy button.
4. When AI can't run (no provider, provider not ready, limit used up), the button is disabled with the reason in plain words. Platform admins also get a link to the settings.
5. Static features (ZIP import, discovery, basic analysis, static docs) behave exactly as today, with or without AI.

**Non-goals**

- Per-workspace keys or limits; workspace-level overrides.
- Money or cost figures.
- Reading provider account balances or plans.
- A list of individual AI runs in the UI. Runs are stored for counting but not listed.
- Live rate-limit headroom.
- Automatic failover between providers.
- Streaming output to the browser.
- AI-enhanced documentation drafts (P12).
- Choosing a provider per run (the default provider is always used).

**Anti-goals (failures even if it "works")**

- **Key leaks:** an API key appearing in Inertia props, logs, job payloads, audit metadata, exceptions or the prompt.
- **Overspending:** an AI call made when the limit is already used up.
- **Source exposure:** source code, file paths or line numbers shown to QA or Viewer users.
- **AI writing facts:** AI output changing the static contract, route facts or the stored trace. AI only explains.
- **Vague wording:** "unknown", "?", "not stated" or "can't tell" appearing in the UI. Every gap gets a typed, explained reason (standing user rule).
- **Instructions from code:** source code steering the AI, for example a comment saying "ignore previous instructions". Code is data to the model.

## 4. Constraints (standing project rules)

- **Credentials** live in the database (encrypted, write-only) and are entered in settings, never in `.env` or config. Only the last 4 characters are ever shown.
- **Platform admin only:** the settings screen and its actions use the `platform-admin` gate, and anyone else gets a uniform 404. FormRequests authorize before they validate.
- **Rate limits:** named limiters only. Test Connection gets a throttle, and the explain action uses a named limiter.
- **Migrations:** additive only. Already-run migrations are never edited.
- **Feature flag:** `ai_settings` (P11 plan name), off by default. The explanation sits behind `advanced_analysis` and needs AI ready.
- **No code execution:** uploaded code is only read, never run.
- **Queue:** AI work runs on the existing `ai` queue, which is already in the worker's queue list.
- **Shared database:** dev and testing share the MySQL DB `api_engine_testing`. Tests use in-memory SQLite. The user runs dev migrations.
- **No commits** unless the user asks.

## 5. Approaches considered (provider layer)

1. **HTTP adapters on Laravel's `Http` client.** _Chosen by the user._ No new dependency, and we see each provider's real status codes and token counts. Matches the GitHub client. The cost is roughly 100–150 lines per provider, and its request shapes must be kept current.
2. **`laravel/ai` or Prism.** Less code, but they read keys from config at boot, which conflicts with DB-stored keys. Error and effort mapping are also only as good as the package.
3. **Official provider SDKs.** Three dependencies with three different error and usage formats to smooth over. More glue than option 1.

## 6. Design

### 6.1 Data

**`ai_providers`**: one row per provider; `provider` is unique.

| Column              | Notes                                                                                                                                                                 |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `provider`          | `anthropic` \| `openai` \| `gemini`                                                                                                                                   |
| `api_key`           | encrypted cast; never serialized (`$hidden`)                                                                                                                          |
| `key_suffix`        | last 4 characters, for display                                                                                                                                        |
| `key_version`       | int, incremented on every replace or remove                                                                                                                           |
| `model`             | must be one of `available_models`                                                                                                                                     |
| `available_models`  | json list from the provider's models endpoint, filled by Test                                                                                                         |
| `effort`            | `low` \| `medium` \| `high`                                                                                                                                           |
| `enabled`           | bool                                                                                                                                                                  |
| `is_default`        | bool; at most one row true, enforced in the service                                                                                                                   |
| `limit_tokens`      | int, nullable (null means no limit)                                                                                                                                   |
| `limit_period`      | `day` \| `month`                                                                                                                                                      |
| `status`            | `not_configured` \| `unchecked` \| `ready` \| `invalid_credentials` \| `quota_exceeded` \| `rate_limited` \| `provider_unavailable` \| `misconfigured` (P11 taxonomy) |
| `status_message`    | safe, fixed text per status, never raw provider text                                                                                                                  |
| `status_checked_at` | timestamp                                                                                                                                                             |

**`ai_runs`**: one row per provider call. This is the basis for the meter, and the table is append-only.

Columns:

- `ai_provider_id`, `provider`, `model`, `effort`;
- `workspace_id`, `project_id`, `endpoint_analysis_id` (the advanced row), `user_id`;
- `input_tokens`, `output_tokens` (provider-reported; 0 if none);
- `status` (`succeeded` \| `failed`), `failure_code`, `duration_ms`, `prompt_version`, `created_at`.

**Workflow explanation storage.** Either a new `workflow_explanations` table or columns on the advanced `endpoint_analyses` row; the builder chooses. Store:

- the Markdown text;
- the `ai_run_id`;
- provider, model and `prompt_version`;
- a list of what was summarized or left out because of the budget.

Only the latest explanation per advanced analysis is shown.

Wire everything new into `ProjectDeleter`; `ai_runs` must also survive project deletion (§ 7).

### 6.2 Usage meter and limit

- **Used:** the sum of `input_tokens + output_tokens` from `ai_runs` for that provider in the current period. A day is a calendar day and a month is a calendar month, both in the app timezone.
- **Left** is limit minus used, floored at 0. **Resets** is the start of the next period.
- **Before every call:** if a limit is set and used ≥ limit, the run is refused with `limit_reached`. The message reads, for example: "Anthropic's monthly AI limit of 2,000,000 tokens is used up. It resets on 1 Dec. A platform admin can raise the limit in AI settings."
- **The check repeats in the worker** right before the call, not only when the button is pressed.
- **Overshoot:** two runs started at the same time can go slightly over the limit, since each checks before it calls. This is acceptable; record it as a `shortcut:` comment. No reservation system.

### 6.3 Provider adapters

Interface (namespace `App\Domain\Ai`):

- `test(AiProvider): TestResult` returns a status plus the model list;
- `generate(AiProvider, string $system, string $user, int $maxOutputTokens): Generation` returns text, input tokens, output tokens and a stop reason, or throws a typed `AiError` carrying a status code.

Rules for all adapters:

- fixed trusted base URLs;
- connect timeout about 10 s, generate timeout about 300 s;
- no retries inside the adapter (the job decides);
- the key goes only into the request header.

**Anthropic** (checked against the current API docs bundled with Claude Code, 2026-11):

- **Endpoint:** `POST https://api.anthropic.com/v1/messages`, with headers `x-api-key`, `anthropic-version: 2026-10-21` and `content-type: application/json`.
- **Body:** `model`, `max_tokens` (about 16000; non-streaming), `system`, `messages: [{role: user, content}]`, and `output_config: {effort: low|medium|high}`.
- **Never send** `temperature`, `top_p` or `top_k`: they return a 400 on API version 2026-10-21. Thinking is adaptive by default on current models, so leave out `thinking`.
- **Response:** read `content[]` blocks of type `text` and `usage.input_tokens` / `usage.output_tokens`.
    - `stop_reason: "refusal"` → failure `refused`.
    - `"max_tokens"` → keep the text but mark the explanation as cut short.
- **Test:** `GET https://api.anthropic.com/v1/models` uses no tokens and fills `available_models` from `data[].id`. Suggested default: `claude-opus-5-5`.
- **Error mapping:**
    - 401 → `invalid_credentials`;
    - 402 `billing_error` → `quota_exceeded`;
    - 403 → `invalid_credentials`;
    - 404 (model) → `misconfigured`;
    - 429 whose `error.details.error_code` is `enforced_spend_limit_reached` → `quota_exceeded`; any other 429 → `rate_limited`;
    - 529, other 5xx, connection errors and timeouts → `provider_unavailable` (never "expired").

**OpenAI** and **Gemini:** the same shape. The builder must check current request and usage fields against each provider's official docs at build time, and must not rely on memory. Expected shapes:

- **OpenAI:** `POST /v1/responses` with `reasoning.effort` and `usage.input_tokens`/`output_tokens`; Test via `GET /v1/models`. A 429 whose code is `insufficient_quota` → `quota_exceeded`; any other 429 → `rate_limited`.
- **Gemini:** `models/{model}:generateContent` with the `x-goog-api-key` header, the thinking setting as effort, and `usageMetadata` token counts; Test via the models list. `RESOURCE_EXHAUSTED` → `rate_limited` or `quota_exceeded` according to the error detail.

**Effort:** map Low / Medium / High to each provider's own field. If a chosen model doesn't support effort, leave it out and show "Effort not supported by this model" next to the selector.

**Provider status on failure:** when a real call fails with `invalid_credentials`, `quota_exceeded` or `misconfigured`, set that status on the provider row, so the gate blocks later runs until an admin fixes it and tests again. Transient errors (`rate_limited`, `provider_unavailable`) don't change the stored status.

### 6.4 AI availability gate

`AiGate::check(): Available | Unavailable(reason)` checks four things:

- a default provider exists and is enabled;
- its status is `ready`;
- its limit isn't used up;
- the `ai_settings` flag is on.

Every reason has fixed user-facing text. The gate is used by the UI props, the explain request and the job.

### 6.5 Explain workflow flow

1. **Start.** On the Workflow tab, the user clicks **Explain workflow with AI**. The request requires the `ImportAndAnalyze` capability (as the existing trace route does), applies the named limiter, and checks the gate.
2. **Queue the trace.** The existing trace (the advanced analysis row from P10) is queued as today. If an up-to-date trace already exists, it's reused.
3. **Queue the explanation.** When the trace completes, a job on the `ai` queue starts. Its payload holds only the IDs: analysis ID, provider ID and `key_version`. It never holds the key.
4. **Checks in the job:**
    - it re-checks the gate and the limit;
    - it checks that `key_version` still matches, and fails with `key_changed` ("The AI key was changed while this was waiting. Run it again.") if not;
    - it builds the context (§ 6.6), calls the adapter, records an `ai_runs` row (on failure too) and stores the Markdown.
5. **Retries:** at most 2 attempts with backoff, for `rate_limited` and `provider_unavailable` only. No retry for credentials, quota, limit, refusal or a misconfigured model.
6. **The trace stays.** If the AI step fails, the trace is still saved, and the tab shows the failure reason with **Try again** (if retryable).

### 6.5a The existing trace route, re-running, and staleness (builder's calls, settled from the user's decisions)

- **One action.** The P10 route `POST projects/{project}/analyses/{analysis}/dependencies` (`WorkflowController::store`) stays, but `AiGate` gates it, and it now always runs trace then explanation. The separate "Trace workflow / Trace again" buttons in `workflow-panel.tsx` are replaced by **Explain workflow with AI** (or **Explain again** once one exists). This follows the user's rule that advanced analysis isn't run without AI.
- **Stored traces stay readable.** A trace that already exists is still shown under "Show trace details", even when AI isn't ready. The flag being off must not hide data that already exists.
- **QA and Viewer** keep today's behaviour for trace details: `WorkflowController::show` returns the graph with source refs stripped. They lose only the AI explanation (§ 6.8).
- **Re-running is allowed.** **Explain again** re-runs both steps and spends tokens again; the usage limit is the spend control. The line under the button shows the tokens left, so the cost is visible before clicking.
- **Never stale.** Trace and explanation always run together, so the stored explanation always matches the stored trace, and no stale state is needed. Provider, model and prompt version are shown in the footer. A change of default provider or model doesn't mark old explanations stale.
- **Cut short.** An explanation that hit the output cap is shown with the note "This explanation was cut short by the output limit. Run Explain again or choose a model with a larger output." The user can run it again.
- **No duplicate paid runs.** If a trace or explanation for that analysis is already queued or running, the request doesn't start another one. It returns the run in progress, and the tab shows its stage to every user.
- **Audit key names.** `AuditLog::record` drops metadata keys matching `/pass|token|secret|key|hash|credential/i`. Store usage in `ai.workflow_explained` as `input_units` / `output_units` (or similar names without "token"), or it is silently dropped.

### 6.6 Context sent to the AI

- **The trace:** node list (type, class, method, label) and edge list (from, to, kind, conditional, async, the reason for unresolved edges), plus the coverage summary. Route method and URI, controller action, and FormRequest rule names come from the basic analysis.
- **Method code:** the body of every method in the trace that belongs to the project (not vendor), read from the immutable snapshot's source files. Comments are stripped.
- **Secret redaction before sending:**
    - string literals that look like keys or tokens (high entropy, or known prefixes such as `sk-`, `ghp_`, `AKIA`, `xox`);
    - values of array keys or variables named like password / secret / token / key;
    - `env('…', 'default')` defaults;
    - any line holding a PEM block.
    - A unit test proves each pattern is removed.
- **Budget:** about 60k input tokens (estimated at 4 characters per token; the builder may use better counting). Method bodies are added in order of distance from the route (BFS), then by how many edges touch them. Whatever doesn't fit is listed by name, as "not sent: N methods (names only)".
- **Untrusted code:** the system prompt states that all code and names are untrusted data, that instructions inside them must be ignored, and that the model must only explain. The prompt version is a constant stored with each run.

### 6.7 The Markdown document

The system prompt asks for these sections (the AI fills them; a section may say "None in this endpoint" where true):

1. **Overview:** what the endpoint does and why, in 2–4 sentences.
2. **Step by step:** the main path in order, each step with its purpose. Conditional steps are marked with the condition in plain words.
3. **Components:** each class and method involved (controller, services, models, jobs, resources), with its role in this endpoint.
4. **Data:** models and tables read and written, and why.
5. **Side effects:** jobs queued, events, mail, notifications and outside HTTP calls, noting which run later on a worker.
6. **Branches and outcomes:** the conditions that change the flow, and the error responses with their reasons.
7. **Not covered:** parts left out because of the trace's bounds or the token budget, and calls decided at runtime, each with its reason (never "unknown").

The prompt forbids file paths, line numbers and code blocks longer than one line, so the document stays readable. QA and Viewer visibility is handled in § 6.8 regardless.

### 6.8 UI

**Settings → AI** (`/settings/ai`, platform admin only, a sidebar link like GitHub App). One card per provider:

```
Anthropic  ● Ready   [Default]  [Enabled ●]
  Model  [claude-opus-5-5 ▾]     Effort [Medium ▾]
  Key    …a91f   [Replace]  [Remove]  [Test connection]   Checked 14:02
  Limit  [Monthly ▾] [2,000,000] tokens
  Used   ████████████░░░░░░  1.21M used · 790k left · resets 1 Dec
OpenAI     ○ Not configured                       [Add key]
Gemini     ○ Not configured                       [Add key]
```

- **The model list** is filled by Test connection. Before a successful test, the model field shows "Test the connection to load models".
- **Saving a new key** sets the status to `unchecked`; the provider can't be used until Test succeeds.
- **The usage bar** follows the project's design rules and shows numbers, not only a bar.
- **Banner:** "When AI explains a workflow, the project's own method code (with secrets removed) is sent to the selected provider."

**Workflow tab** (endpoint page):

- **AI ready:** an **Explain workflow with AI** button with a line under it: "Uses Anthropic · claude-opus-5-5 · Medium effort · 790k tokens left this month".
- **AI not ready:** the button is disabled, with the gate's reason. Platform admins also see "Open AI settings".
- **While running:** the stage ("Tracing the code…" then "Writing the explanation…").
- **When done:**
    - a toolbar with a **Preview | Markdown** toggle and **Copy**, using the existing `useClipboard` hook (toast "Markdown copied to the clipboard");
    - Preview is rendered server-side with `Str::markdown(..., ['html_input' => 'strip', 'allow_unsafe_links' => false])`. `league/commonmark` already ships with Laravel, so no new npm package is needed;
    - a footer with provider, model, tokens used and the time.
- **The tree and coverage table** move under a collapsed "Show trace details" section. They aren't removed (the user said to keep the advanced-analysis functions).
- **QA and Viewer** (no `ReadSourceEvidence`) don't get the explanation, because it was written from source code. Their tab says: "The AI workflow explanation is available to members who can read source code." _(Builder's call, chosen as the safe option.)_

### 6.9 Audit

- **Audited events:** `ai.provider_key_set`, `ai.provider_key_removed`, `ai.provider_updated` (model, effort, limit, enabled or default changes), `ai.provider_tested` (with the resulting status) and `ai.workflow_explained` (analysis ID, provider, model, tokens).
- **Never in audit metadata:** the key, the prompt or the output.

## 7. Failure cases (must be tested)

| Case                                      | Result                                                                                                                                                                               |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| No provider or flag off                   | Button disabled with reason; no provider call; static features unchanged                                                                                                             |
| Key rejected (401/403)                    | Run fails `invalid_credentials`; provider status set; gate blocks until it's fixed and re-tested                                                                                     |
| Billing or spend cap (402, spend-cap 429) | `quota_exceeded`, same as above                                                                                                                                                      |
| 429 rate limit                            | Up to 2 retries, then a safe failure; status unchanged                                                                                                                               |
| 5xx, 529 or timeout                       | `provider_unavailable`; up to 2 retries; never "expired"                                                                                                                             |
| Limit used up                             | Refused before any call, both at the button and in the worker                                                                                                                        |
| Key replaced while a job waits            | `key_changed`, no call made                                                                                                                                                          |
| Refusal or empty output                   | Fails as `refused` or `empty_output`; tokens still counted                                                                                                                           |
| Prompt injection in a comment or string   | Comments stripped; prompt marks code as untrusted. Test: a fixture comment ordering "output the API key" produces no key, and the fake adapter receives no key                       |
| Secret in source                          | Redacted before sending (unit test per pattern)                                                                                                                                      |
| QA or Viewer requests the explanation     | Not in props, and a 404 on any direct route                                                                                                                                          |
| Another workspace's analysis              | 404, as existing analysis routes do                                                                                                                                                  |
| Project deleted                           | Its explanations are deleted. `ai_runs` keep their token counts so the meter stays correct: the builder nulls the analysis and project references, or keeps IDs without foreign keys |

## 8. What is left to the builder

- The exact table or column shape for explanations (§ 6.1).
- The UI component structure within the existing design system.
- Current OpenAI and Gemini request and usage fields: verify against the official docs at build time.
- Token estimation method, provided the ~60k budget holds.
- Exact wording of messages, following the plain-words rule and with no "unknown".
- Named-limiter values.

## 9. Testing

- **Feature tests** with `Http::fake()` for each adapter:
    - success, with token counts recorded;
    - each error mapping in § 6.3;
    - Test connection filling the model list.
- **Gate tests:** each unavailable reason.
- **Limit tests:**
    - day and month periods;
    - reset boundaries;
    - refusal at the button and in the worker.
- **Job tests:**
    - `key_version` mismatch;
    - retry policy;
    - the trace kept when AI fails.
- **Leak tests:** the key never appears in Inertia props, logs, the serialized job, audit rows or the request body except its header (assert on `Http::recorded()`).
- **Redaction unit tests** and the prompt-injection fixture test.
- **Authorization tests:**
    - non-admin gets a 404 on all settings routes;
    - QA and Viewer can't see or fetch the explanation;
    - cross-workspace access gets a 404.
- **Vitest tests:**
    - settings page states (not configured, unchecked, ready, failure statuses, meter);
    - Workflow tab states (disabled with reason, running, done, Preview/Markdown toggle, Copy toast);
    - no "unknown" text.
- **All existing gates must pass:**
    - PHP: Pint, PHPStan level 7, PHP tests on SQLite and MySQL (throwaway DB with a unique name);
    - frontend: `vp fmt`, `vp check`, `tsc`, `vp test run --exclude '.codewright/**'`;
    - `npm run build`.

## 10. Additions made during the build

- **New error codes:**
    - `ai.request_rejected`: a provider 4xx that no other code covers, such as an effort setting or request size it won't accept. It is not retried and doesn't change the provider's stored status.
    - `ai.internal`: an unexpected failure while writing the explanation. It is not retried; the run is metered with the response's tokens, or an input estimate if there was no response.
- **New gate reasons:**
    - `provider_off`: the default provider is turned off.
    - `no_default`: providers are ready but none is the default.
- **Metering fallbacks:** every generate attempt that times out, and every reply that reports 0 input tokens, is metered with an input estimate (characters ÷ 4).
- **Rendering:** images in AI Markdown are replaced by their alt text, and links get `rel="noopener noreferrer nofollow"`.
- **Test connection:** a failed Test keeps the model list and the chosen model. A transient failure (rate limited, provider unavailable) also leaves a ready provider ready.
