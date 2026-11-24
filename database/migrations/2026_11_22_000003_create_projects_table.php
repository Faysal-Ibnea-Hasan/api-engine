<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // restrict: deleting a workspace must never silently delete its projects
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->text('description')->nullable();
            $table->string('visibility', 20)->default('workspace');
            $table->string('status', 20)->default('draft');
            $table->string('status_before_archive', 20)->nullable();
            $table->timestamp('archived_at')->nullable();

            // Wizard settings: preferences only, never credentials or an imported source.
            $table->string('wizard_step', 20)->default('source');
            $table->timestamp('wizard_completed_at')->nullable();
            $table->string('source_preference', 10)->nullable();
            $table->string('framework', 20)->default('laravel');
            $table->string('root_path')->default('.');
            $table->string('analysis_mode', 20)->default('static');
            $table->string('documentation_mode', 20)->default('on_demand');

            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
