<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->char('source_key', 64)->nullable()->unique();
            $table->json('assets')->nullable();
            $table->string('logo', 512)->nullable();
            $table->json('import_report')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::table('forms', fn (Blueprint $table) => $table->boolean('awaiting_theme_publication')->default(false));
    }

    public function down(): void
    {
        Schema::table('forms', fn (Blueprint $table) => $table->dropColumn('awaiting_theme_publication'));
        Schema::table('themes', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source_key', 'assets', 'logo', 'import_report', 'reviewed_at']);
        });
    }
};
