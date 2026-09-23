<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletEscrowHoldsTable extends Migration
{
    public function up()
    {
        Schema::create('wallet_escrow_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_id')->unique();
            $table->uuid('buyer_wallet_id');
            $table->string('coin', 10);
            $table->decimal('amount_atomic', 36, 0);
            $table->enum('status', ['active', 'released', 'refunded'])->default('active');
            $table->uuid('released_to')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            $table->foreign('buyer_wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->foreign('released_to')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallet_escrow_holds');
    }
}
