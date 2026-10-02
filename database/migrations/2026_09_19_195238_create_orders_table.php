<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_pair_id')->constrained('market_pairs');
            $table->foreignId('strategy_id')->nullable()->constrained('strategies')->nullOnDelete();
            $table->string('nobitex_order_id')->nullable()->index(); // شناسه سفارش در سمت نوبیتکس
            $table->enum('type', ['buy', 'sell']); // نوع سفارش
            $table->enum('execution_type', ['limit', 'market'])->default('limit'); // مدل سفارش
            $table->decimal('price', 20, 8); // قیمت سفارش
            $table->decimal('amount', 20, 8); // مقدار ارزی
            $table->decimal('filled_amount', 20, 8)->default(0); // مقدار معامله شده
            $table->decimal('stop_loss', 20, 8)->nullable(); // حد زیان
            $table->decimal('take_profit', 20, 8)->nullable(); // حد سود
            $table->decimal('fee', 20, 8)->default(0); // کارمزد معامله
            $table->enum('status', ['new', 'partially_filled', 'filled', 'canceled', 'failed'])->default('new');
            $table->text('error_message')->nullable(); // ذخیره پیام خطا در صورت ناموفق بودن
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
