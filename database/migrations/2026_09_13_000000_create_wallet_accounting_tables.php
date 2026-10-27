<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWalletAccountingTables extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('withdrawal_pin')->nullable()->after('password');
            $table->timestamp('withdrawal_pin_reset_required_at')->nullable();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('coin', 10);
            $table->enum('status', ['active', 'frozen'])->default('active');
            $table->decimal('available_atomic', 36, 0)->default(0);
            $table->decimal('reserved_atomic', 36, 0)->default(0);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'coin']);
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('coin', 10);
            $table->enum('type', ['deposit', 'withdrawal_hold', 'withdrawal', 'withdrawal_reversal', 'escrow_hold', 'escrow_release', 'dispute_release', 'exchange_debit', 'exchange_credit', 'exchange_fee', 'market_fee_credit', 'market_fee_hold', 'market_fee_sweep', 'admin_credit', 'admin_debit']);
            $table->decimal('available_delta_atomic', 36, 0)->default(0);
            $table->decimal('reserved_delta_atomic', 36, 0)->default(0);
            $table->string('reference_type', 60);
            $table->uuid('reference_id')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->uuid('created_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('coin', 10);
            $table->text('address');
            $table->string('derivation_reference', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->unique(['coin', 'address']);
        });

        Schema::create('wallet_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->uuid('deposit_address_id');
            $table->string('coin', 10);
            $table->string('transaction_hash', 128);
            $table->string('transaction_output', 128);
            $table->decimal('amount_atomic', 36, 0);
            $table->unsignedInteger('confirmations')->default(0);
            $table->enum('status', ['detected', 'confirming', 'credited', 'reorged'])->default('detected');
            $table->unsignedBigInteger('block_height')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->foreign('deposit_address_id')->references('id')->on('deposit_addresses')->onDelete('cascade');
            $table->unique(['coin', 'transaction_hash', 'transaction_output']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->string('coin', 10);
            $table->text('destination_address');
            $table->decimal('amount_atomic', 36, 0);
            $table->enum('status', ['pending_approval', 'approved', 'rejected', 'broadcasting', 'broadcast', 'failed', 'cancelled'])->default('pending_approval');
            $table->uuid('approved_by')->nullable();
            $table->text('transaction_hash')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('market_fee_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('coin', 10)->unique();
            $table->text('address');
            $table->uuid('wallet_id')->nullable()->unique();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('market_fee_wallets');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('wallet_deposits');
        Schema::dropIfExists('deposit_addresses');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallets');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_pin', 'withdrawal_pin_reset_required_at']);
        });
    }
}
