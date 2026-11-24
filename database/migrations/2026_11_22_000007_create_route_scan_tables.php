<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One scan per (snapshot, analyzer version, application root). Completed scans are final.
        Schema::create('route_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('framework_detection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application_root', 1024);
            $table->string('analyzer_version', 40);
            $table->char('scan_key', 64)->unique();
            $table->string('status', 20); // queued | running | completed | partial | failed | cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->unsignedInteger('endpoint_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('partial_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);
            $table->unsignedInteger('finding_count')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 36)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('api_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_snapshot_id')->constrained()->restrictOnDelete();
            // sha256(sorted methods, domain, URI): stable across rescans and analyzer versions.
            $table->char('route_identity', 64);
            $table->json('methods');
            $table->string('methods_label', 100);   // "|GET|HEAD|" for portable filtering
            $table->string('uri', 2048);
            $table->char('uri_hash', 64);
            $table->string('domain')->nullable();
            $table->string('name')->nullable();
            $table->string('handler_type', 20);      // controller | invokable | closure | view | redirect | unknown
            $table->string('controller')->nullable();
            $table->string('action')->nullable();
            $table->json('middleware');
            $table->text('middleware_label');        // "|api|auth:sanctum|"
            $table->string('auth', 10);              // required | unknown (never "none")
            $table->string('route_group', 80);       // api | web | module:Name | other
            $table->string('module', 120)->nullable();
            $table->string('source_path', 1024);
            $table->unsignedInteger('source_line');
            $table->text('expression')->nullable();
            $table->string('resolution', 12);        // resolved | partial | unresolved
            $table->json('reasons');

            $table->unique(['route_scan_id', 'route_identity']);
            $table->index(['route_scan_id', 'route_group']);
            $table->index(['route_scan_id', 'resolution']);
            $table->index(['route_scan_id', 'uri_hash']);
        });

        Schema::create('scan_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('route_scan_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10); // info | warning | error
            $table->string('code', 40);
            $table->string('message', 500);
            $table->string('source_path', 1024)->nullable();
            $table->unsignedInteger('source_line')->nullable();
            $table->text('expression')->nullable();

            $table->index(['route_scan_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_findings');
        Schema::dropIfExists('api_endpoints');
        Schema::dropIfExists('route_scans');
    }
};
