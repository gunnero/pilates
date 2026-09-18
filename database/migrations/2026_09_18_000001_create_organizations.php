<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('locale', 10);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->timestampsTz();
        });
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->jsonb('permissions')->default('[]');
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
