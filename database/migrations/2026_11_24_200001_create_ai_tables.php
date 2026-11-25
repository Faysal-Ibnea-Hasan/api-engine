<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI providers (one platform-wide row each), a usage ledger and the stored workflow explanations.
     * ai_runs keeps plain id columns without foreign keys: usage must survive project deletion.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->unique(); // anthropic | openai | gemini
            $table->text('api_key')->nullable(); // encrypted by the model cast
            $table->string('key_suffix', 4)->nullable();
            $table->unsignedInteger('key_version')->default(0);
            $table->string('model')->nullable();
            $table->json('available_models')->nullable(); // list of {id, effort}
            $table->string('effort', 16)->default('medium');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('limit_tokens')->nullable();
            $table->string('limit_period', 16)->default('month');
            $table->string('status', 24)->default('not_configured');
            $table->timestamp('status_checked_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('model');
            $table->string('effort', 16);
            $table->uuid('workspace_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('endpoint_analysis_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('status', 16); // succeeded | failed
            $table->string('failure_code', 40)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('prompt_version', 40);
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_provider_id', 'created_at'], 'ai_runs_provider_created_index');
        });

        Schema::create('workflow_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('endpoint_analysis_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('status', 16); // tracing | queued | running | completed | failed
            $table->string('failure_code', 40)->nullable();
            $table->unsignedBigInteger('ai_provider_id')->nullable();
            $table->unsignedInteger('key_version')->nullable();
            $table->string('provider', 16)->nullable();
            $table->string('model')->nullable();
            $table->string('effort', 16)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->longText('markdown')->nullable();
            $table->boolean('truncated')->default(false);
            $table->json('omitted')->nullable(); // list of strings
            $table->unsignedBigInteger('ai_run_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_explanations');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_providers');
    }
};
