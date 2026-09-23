<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletExchangesTable extends Migration
{
    public function up()
    {
        Schema::create('wallet_exchanges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('source_wallet_id');
            $table->uuid('target_wallet_id');
            $table->string('source_coin', 10);
            $table->string('target_coin', 10);
            $table->decimal('source_amount_atomic', 36, 0);
            $table->decimal('target_amount_atomic', 36, 0);
            $table->decimal('fee_amount_atomic', 36, 0);
            $table->decimal('rate', 36, 18);
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('source_wallet_id')->references('id')->on('wallets')->onDelete('restrict');
            $table->foreign('target_wallet_id')->references('id')->on('wallets')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallet_exchanges');
    }
}
