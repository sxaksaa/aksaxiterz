<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'package_id']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('package_name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_idr');
            $table->decimal('unit_price_usdt', 20, 6);
            $table->unsignedBigInteger('line_total_idr');
            $table->decimal('line_total_usdt', 20, 6);
            $table->timestamps();
            $table->unique(['order_id', 'package_id']);
            $table->index(['product_id', 'package_id']);
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_item_id')->nullable()->after('order_id');
            $table->index('order_item_id');
        });

        DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('packages', 'packages.id', '=', 'orders.package_id')
            ->select([
                'orders.id as order_id',
                'orders.product_id',
                'orders.package_id',
                'orders.quantity',
                'orders.created_at',
                'orders.updated_at',
                'products.name as product_name',
                'packages.name as package_name',
                'packages.price as unit_price_idr',
                'packages.price_usdt as unit_price_usdt',
            ])
            ->orderBy('orders.id')
            ->chunk(250, function ($orders): void {
                $rows = [];

                foreach ($orders as $order) {
                    $quantity = max(1, (int) $order->quantity);
                    $unitIdr = max(0, (int) $order->unit_price_idr);
                    $unitUsdt = max(0, (float) $order->unit_price_usdt);

                    $rows[] = [
                        'order_id' => $order->order_id,
                        'product_id' => $order->product_id,
                        'package_id' => $order->package_id,
                        'product_name' => $order->product_name,
                        'package_name' => $order->package_name,
                        'quantity' => $quantity,
                        'unit_price_idr' => $unitIdr,
                        'unit_price_usdt' => number_format($unitUsdt, 6, '.', ''),
                        'line_total_idr' => $unitIdr * $quantity,
                        'line_total_usdt' => number_format($unitUsdt * $quantity, 6, '.', ''),
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table('order_items')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropIndex(['order_item_id']);
            $table->dropColumn('order_item_id');
        });

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('cart_items');
    }
};
