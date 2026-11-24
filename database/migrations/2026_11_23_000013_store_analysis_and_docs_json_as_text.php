<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL's JSON type re-sorts object keys on write, so a response body stored in it no longer
     * shows its keys in the order the code produces them. Plain text keeps the document exactly as
     * written; the models' array casts read and write it the same way.
     */
    public function up(): void
    {
        Schema::table('endpoint_analyses', fn (Blueprint $table) => $table->longText('result')->nullable()->change());
        Schema::table('documentation_versions', fn (Blueprint $table) => $table->longText('document')->change());
    }

    public function down(): void
    {
        Schema::table('endpoint_analyses', fn (Blueprint $table) => $table->json('result')->nullable()->change());
        Schema::table('documentation_versions', fn (Blueprint $table) => $table->json('document')->change());
    }
};
