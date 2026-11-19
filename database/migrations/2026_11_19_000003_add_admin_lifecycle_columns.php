<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable()->index());
        Schema::table('users', fn (Blueprint $t) => $t->unsignedInteger('role_version')->default(0));
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $t) {
            $t->dropIndex(['archived_at']);
            $t->dropColumn('archived_at');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role_version'));
    }
};
