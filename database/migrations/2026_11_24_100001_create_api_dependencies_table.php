<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P10: an analysis profile (basic rows keep their fingerprint; advanced rows add a workflow
     * graph) and the graph's edges as normalized, queryable rows. Additive only.
     */
    public function up(): void
    {
        Schema::table('endpoint_analyses', function (Blueprint $table) {
            $table->string('profile', 16)->default('basic')->after('schema_version');
        });

        Schema::create('api_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('endpoint_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->char('edge_hash', 64); // sha256 of from|to|kind: the full symbols are too long for a unique index
            $table->string('kind', 20); // handled_by | validates_with | calls | reads_from | writes_to | dispatches | responds_with | throws
            $table->string('from_node', 512);
            $table->string('from_type', 20);
            $table->string('to_node', 512);
            $table->string('to_type', 20);
            $table->boolean('conditional'); // recorded inside a branch, loop or catch on every path
            $table->string('resolution', 12); // resolved | unresolved
            $table->string('reason', 40)->nullable(); // why it was not followed (container_binding, dynamic_method, cycle, bound_depth …)
            $table->boolean('async')->nullable(); // dispatches: queued (runs later) or not
            $table->string('note', 500)->nullable();
            $table->string('expression', 255)->nullable(); // source text; for roles that may read source only
            $table->text('evidence')->nullable(); // evidence ids ("ev-12") of the same analysis
            $table->timestamp('created_at')->nullable();

            $table->unique(['endpoint_analysis_id', 'edge_hash'], 'api_dependencies_analysis_edge_unique');
            $table->index(['project_id', 'to_type'], 'api_dependencies_project_to_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_dependencies');
        Schema::table('endpoint_analyses', fn (Blueprint $table) => $table->dropColumn('profile'));
    }
};
