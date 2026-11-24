<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable static documentation drafts, one per (analysis, builder version). Evidence ids and their
        // mapping live inside `document` (copied from the analysis result), so there is no separate evidence table.
        Schema::create('documentation_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('endpoint_analysis_id')->constrained()->restrictOnDelete();
            $table->char('route_identity', 64);
            $table->string('analyzer_version', 40);
            $table->string('builder_version', 40);
            $table->string('status', 20)->default('draft'); // draft → in_review → published → superseded (later phases)
            $table->char('contract_checksum', 64); // sha256 of the analysis result as canonical JSON
            $table->json('document');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['endpoint_analysis_id', 'builder_version'], 'doc_versions_analysis_builder_unique');
            $table->index(['project_id', 'route_identity', 'created_at'], 'doc_versions_endpoint_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_versions');
    }
};
