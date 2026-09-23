<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketFeeSweepsTable extends Migration
{
    public function up()
    {
        Schema::create('market_fee_sweeps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('coin', 10);
            $table->text('destination_address');
            $table->decimal('amount_atomic', 36, 0);
            $table->enum('status', ['pending', 'broadcasting', 'broadcast', 'failed'])->default('pending');
            $table->text('transaction_hash')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('market_fee_sweeps');
    }
}
