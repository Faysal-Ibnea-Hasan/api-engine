<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The repositories the connecting GitHub user could reach in that installation.
     * Null: the whole installation (it is on the connecting user's own account).
     */
    public function up(): void
    {
        Schema::table('workspace_github_installations', function (Blueprint $table) {
            $table->json('repository_ids')->nullable()->after('connected_by');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_github_installations', function (Blueprint $table) {
            $table->dropColumn('repository_ids');
        });
    }
};
