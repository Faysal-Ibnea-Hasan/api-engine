<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One immutable detection per (snapshot, detector version). Only the root choice changes later.
        Schema::create('framework_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_snapshot_id')->constrained()->restrictOnDelete();
            $table->string('detector_version', 40);
            $table->char('snapshot_sha256', 64);
            $table->string('status', 20); // detected | partial | unsupported | not_found
            $table->json('result');
            $table->string('selected_root', 1024)->nullable();
            $table->string('root_selection_source', 20)->nullable(); // auto | project_setting | user
            $table->foreignId('root_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('root_selected_at')->nullable();
            $table->timestamps();

            $table->unique(['source_snapshot_id', 'detector_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_detections');
    }
};
