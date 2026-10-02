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
        Schema::create('market_pairs', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique(); // e.g. BTCIRT, USDTIRT
            $table->string('src_currency', 20); // e.g. btc
            $table->string('dst_currency', 20); // e.g. irt
            $table->decimal('min_amount', 20, 8)->default(0); // حداقل حجم سفارش
            $table->decimal('tick_size', 20, 8)->default(0);  // کوچک‌ترین واحد تغییر قیمت
            $table->decimal('last_price', 20, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_pairs');
    }
};
