<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->string('slug')->unique();
            $t->string('name');
            $t->boolean('published')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->json('definition');
            $t->json('draft_definition')->nullable();
            $t->timestamps();
        });
        Schema::create('form_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->json('definition');
            $t->timestamps();
            $t->unique(['form_id', 'revision']);
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->string('token', 128)->unique();
            $t->boolean('retain')->default(true)->index();
            $t->timestamp('purged_at')->nullable();
            $t->longText('answers');
            $t->longText('snapshot');
            $t->timestamps();
            $t->index(['form_id', 'created_at']);
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $t->string('field_key');
            $t->string('path', 512);
            $t->string('name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 24);
            $t->string('status', 24)->default('pending')->index();
            $t->longText('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->unique(['submission_id', 'kind']);
        });
    }

    public function down(): void
    {
        foreach (['deliveries', 'attachments', 'submissions', 'form_revisions', 'forms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
