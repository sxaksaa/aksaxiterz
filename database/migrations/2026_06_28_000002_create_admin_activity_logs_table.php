<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }

        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admin_name', 120);
            $table->string('admin_email', 190)->index();
            $table->string('section', 40)->index();
            $table->string('action', 120)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->string('subject_label', 190)->nullable();
            $table->string('details', 255)->nullable();
            $table->string('method', 10);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
