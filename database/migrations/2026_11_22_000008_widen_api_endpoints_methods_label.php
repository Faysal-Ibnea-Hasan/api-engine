<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A long Route::match([...]) could overflow 100 characters and fail the whole scan.
    public function up(): void
    {
        Schema::table('api_endpoints', function (Blueprint $table) {
            $table->string('methods_label', 255)->change();
        });
    }

    public function down(): void
    {
        // Widening is safe to keep; shrinking could truncate stored rows.
    }
};
