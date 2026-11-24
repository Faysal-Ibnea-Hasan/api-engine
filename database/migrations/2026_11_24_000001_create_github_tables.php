<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P09: GitHub App installations, their workspace links, and the Git origin of every import. No tokens, codes or URLs; App secrets are encrypted. */
return new class extends Migration
{
    public function up(): void
    {
        // The one platform-wide GitHub App (single row). Secrets use Laravel's `encrypted` cast, so the columns hold ciphertext.
        Schema::create('github_app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('slug');
            $table->string('client_id');
            $table->text('client_secret')->nullable();
            $table->text('private_key')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Short-lived OAuth state: only its SHA-256 is stored; the raw value goes to GitHub and back once.
        Schema::create('github_connection_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('state_hash', 64)->unique();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        // Provider identity of one GitHub App installation. Linked to workspaces only through the pivot below.
        Schema::create('github_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('github_installation_id')->unique();
            $table->unsignedBigInteger('github_account_id');
            $table->string('account_login');
            $table->string('account_type', 30);
            $table->string('repository_selection', 20);
            $table->json('permissions')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        // Explicit authorization of one installation for one workspace (never inferred from account names).
        Schema::create('workspace_github_installations', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('github_installation_id')->constrained('github_installations')->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['workspace_id', 'github_installation_id']);
        });

        // The repository a project's Git source points at. Numeric provider IDs are the authority; names may change.
        Schema::create('github_repository_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_source_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('github_installation_id')->constrained('github_installations')->restrictOnDelete();
            $table->unsignedBigInteger('github_repository_id');
            $table->string('owner_login');
            $table->string('repository_name');
            $table->string('full_name', 512);
            $table->string('visibility', 20);
            $table->string('default_branch');
            $table->string('tracked_ref_type', 20)->nullable();
            $table->string('tracked_ref')->nullable();
            $table->char('last_imported_sha', 40)->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'project_id'], 'gh_repo_sources_workspace_project_unique');
            $table->index(['github_installation_id', 'github_repository_id'], 'gh_repo_sources_installation_repo_index');
        });

        // The immutable Git origin (repository + resolved commit) of each import.
        Schema::create('github_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('github_repository_source_id')->constrained()->restrictOnDelete();
            // Copied from the source at import time, so history survives switching the project to another repository.
            $table->unsignedBigInteger('github_repository_id');
            $table->string('repository_full_name', 512);
            $table->foreignUuid('processing_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignUuid('source_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_ref');
            $table->string('ref_type', 20);
            $table->char('commit_sha', 40);
            $table->string('commit_message', 1000)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->string('status', 20);
            $table->string('failure_code', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'github_repository_source_id', 'github_repository_id', 'commit_sha'], 'gh_imports_project_source_repo_sha_unique');
            $table->index(['project_id', 'created_at'], 'gh_imports_project_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_imports');
        Schema::dropIfExists('github_repository_sources');
        Schema::dropIfExists('workspace_github_installations');
        Schema::dropIfExists('github_installations');
        Schema::dropIfExists('github_connection_attempts');
        Schema::dropIfExists('github_app_settings');
    }
};
