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
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_pair_id')->constrained('market_pairs')->cascadeOnDelete();
            $table->string('name'); // e.g. RSI_Scalping_BTC
            $table->string('type'); // e.g. rsi_cross, grid, macd
            $table->json('config'); // تنظیمات استراتژی (مثلا: {"rsi_period": 14, "overbought": 70, "oversold": 30})
            $table->decimal('allocated_capital', 20, 4)->default(0); // سرمایه تخصیص یافته
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
