<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project's configured source (a connection, not analyzed content).
        Schema::create('project_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'type']);
        });

        // An exact, immutable imported source tree. Never changes once ready.
        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_source_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('manifest_version', 10);
            // Null until validated; unique so two identical imports can't both become ready.
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->string('storage_key')->nullable(); // server-side only, never sent to the browser
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sha256']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('source_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('source_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->char('path_hash', 64);
            $table->char('sha256', 64)->nullable(); // null for excluded files: their content is never read into storage
            $table->unsignedBigInteger('bytes');
            $table->string('language', 20)->nullable();
            $table->string('excluded_reason', 30)->nullable();

            $table->unique(['source_snapshot_id', 'path_hash']);
        });

        // One attempt at long-running work (import now; scans and analysis later).
        Schema::create('processing_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('source_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->char('idempotency_key', 64);
            // Set while queued/running, null once finished: the unique index forbids two active runs for the same work.
            $table->char('active_key', 64)->nullable()->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('deduplicated')->default(false);
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 36)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_runs');
        Schema::dropIfExists('source_files');
        Schema::dropIfExists('source_snapshots');
        Schema::dropIfExists('project_sources');
    }
};
