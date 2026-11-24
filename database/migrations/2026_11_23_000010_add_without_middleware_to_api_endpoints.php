<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the route removed with withoutMiddleware(). Laravel matches it by resolved class,
        // so the analyzer needs it to know the real stack. NULL = written by an older scan.
        Schema::table('api_endpoints', function (Blueprint $table) {
            $table->json('without_middleware')->nullable()->after('middleware_label');
        });
    }

    public function down(): void
    {
        Schema::table('api_endpoints', function (Blueprint $table) {
            $table->dropColumn('without_middleware');
        });
    }
};
