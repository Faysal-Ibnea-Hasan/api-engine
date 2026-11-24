<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One analysis per (snapshot, root, route identity, analyzer version). Completed ones are final.
        Schema::create('endpoint_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_snapshot_id')->constrained()->restrictOnDelete();
            // The endpoint row belongs to one scan; the analysis outlives a rescan by route identity.
            $table->foreignUuid('api_endpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->char('route_identity', 64);
            $table->string('application_root', 1024);
            $table->string('analyzer_version', 40);
            $table->string('schema_version', 10);
            $table->char('analysis_key', 64)->unique();
            $table->string('status', 20); // queued | running | completed | partial | failed | cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->json('result')->nullable(); // validated AnalysisResultV1, never edited after it is written
            $table->unsignedInteger('field_count')->default(0);
            $table->unsignedInteger('response_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 36)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'route_identity']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('analysis_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('endpoint_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('evidence_key', 20); // "ev-3", as referenced inside the result
            $table->string('path', 1024);
            $table->char('sha256', 64)->nullable();
            $table->string('symbol', 512)->nullable();
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->string('kind', 30);

            $table->unique(['endpoint_analysis_id', 'evidence_key']);
        });

        Schema::create('analysis_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('endpoint_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10); // info | warning | error
            $table->string('code', 40);
            $table->string('message', 500);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_findings');
        Schema::dropIfExists('analysis_evidence');
        Schema::dropIfExists('endpoint_analyses');
    }
};
