<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A copy of result.auth.status so the API Explorer can show and filter it without reading JSON.
        Schema::table('endpoint_analyses', function (Blueprint $table) {
            $table->string('auth_status', 24)->nullable()->after('unresolved_count');
            $table->index(['api_endpoint_id', 'analyzer_version', 'auth_status'], 'endpoint_analyses_explorer_auth_index');
        });

        DB::table('endpoint_analyses')->whereNotNull('result')->orderBy('id')->select(['id', 'result'])
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $status = json_decode((string) $row->result, true)['auth']['status'] ?? null;
                    is_string($status) && DB::table('endpoint_analyses')->where('id', $row->id)->update(['auth_status' => mb_substr($status, 0, 24)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('endpoint_analyses', function (Blueprint $table) {
            $table->dropIndex('endpoint_analyses_explorer_auth_index');
            $table->dropColumn('auth_status');
        });
    }
};
