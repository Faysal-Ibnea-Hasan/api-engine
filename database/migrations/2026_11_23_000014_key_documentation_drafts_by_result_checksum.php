<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A re-analysis rewrites the analysis result, so one analysis can need several drafts: one per
     * distinct result. The new unique key is added before the old one is dropped (MySQL needs an
     * index that starts with the foreign-key column at all times).
     */
    public function up(): void
    {
        Schema::table('documentation_versions', function (Blueprint $table) {
            $table->unique(['endpoint_analysis_id', 'builder_version', 'contract_checksum'], 'doc_versions_analysis_checksum_unique');
        });
        Schema::table('documentation_versions', function (Blueprint $table) {
            $table->dropUnique('doc_versions_analysis_builder_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documentation_versions', function (Blueprint $table) {
            $table->unique(['endpoint_analysis_id', 'builder_version'], 'doc_versions_analysis_builder_unique');
        });
        Schema::table('documentation_versions', function (Blueprint $table) {
            $table->dropUnique('doc_versions_analysis_checksum_unique');
        });
    }
};
