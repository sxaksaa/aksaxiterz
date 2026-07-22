<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gopay_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('device_id', 191);
            $table->string('package_name', 191);
            $table->string('title', 255);
            $table->text('notification_text');
            $table->unsignedBigInteger('amount_idr');
            $table->unsignedBigInteger('notification_posted_at_ms');
            $table->string('status', 32)->default('received');
            $table->foreignId('matched_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('last_received_at');
            $table->timestamps();

            $table->index(['status', 'amount_idr'], 'gopay_events_status_amount_index');
            $table->unique('matched_order_id', 'gopay_events_matched_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gopay_notification_events');
    }
};
