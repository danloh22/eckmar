<?php

namespace App\Console\Commands;

use App\MarketFeeSweep;
use App\MarketFeeWallet;
use App\Admin;
use App\WalletLedgerEntry;
use App\Marketplace\Payment\Payment;
use App\Services\WalletLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SweepMarketFeesCommand extends Command
{
    protected $signature = 'wallets:sweep-market-fees';
    protected $description = 'Send accumulated marketplace and exchange fees to configured administrator addresses.';
    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        parent::__construct();
        $this->ledger = $ledger;
    }

    public function handle()
    {
        foreach (MarketFeeSweep::where('status', 'pending')->whereNull('resolution')->get() as $pending) {
            $this->broadcast($pending);
        }

        foreach (MarketFeeWallet::whereIn('coin', ['btc', 'xmr', 'ltc'])->get() as $destination) {
            $sweep = DB::transaction(function () use ($destination) {
                $wallet = $this->ledger->marketWalletFor($destination->coin);
                $wallet = $wallet->newQuery()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();
                $earned = '0';
                foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->whereIn('type', ['exchange_fee', 'market_fee_credit'])->pluck('available_delta_atomic') as $amount) {
                    $earned = gmp_strval(gmp_add($earned, $amount));
                }
                $scheduled = '0';
                foreach (MarketFeeSweep::where('wallet_id', $wallet->id)->where(function ($query) {
                    $query->whereNull('resolution')->orWhere('resolution', '!=', 'refunded');
                })->pluck('amount_atomic') as $amount) {
                    $scheduled = gmp_strval(gmp_add($scheduled, $amount));
                }
                $unswept = gmp_strval(gmp_sub($earned, $scheduled));
                $amount = gmp_cmp($unswept, $wallet->available_atomic) < 0 ? $unswept : $wallet->available_atomic;
                if (gmp_cmp($amount, '0') <= 0) return null;
                $sweep = MarketFeeSweep::create(['wallet_id' => $wallet->id, 'coin' => $destination->coin, 'destination_address' => $destination->address, 'amount_atomic' => $amount]);
                $this->ledger->book($wallet, 'market_fee_hold', '-' . $amount, $amount, 'market_fee_sweep', $sweep->id, 'market-fee-hold-' . $sweep->id, null, null, true);
                return $sweep;
            });
            if ($sweep) $this->broadcast($sweep);
        }
    }

    private function broadcast(MarketFeeSweep $sweep)
    {
        $sweep->status = 'broadcasting';
        $sweep->save();
        $hash = null;
        try {
            $decimals = (int) config('coins.atomic_decimals.' . $sweep->coin);
            $divisor = gmp_pow(10, $decimals);
            $amount = (float) (gmp_strval(gmp_div_q($sweep->amount_atomic, $divisor)) . '.' . str_pad(gmp_strval(gmp_mod($sweep->amount_atomic, $divisor)), $decimals, '0', STR_PAD_LEFT));
            $result = Payment::coinService($sweep->coin)->sendToAddress($sweep->destination_address, $amount);
            $hash = $this->transactionHash($result);
            if (!$hash) throw new \RuntimeException('Wallet RPC did not return a transaction hash.');
            $sweep->status = 'broadcast'; $sweep->transaction_hash = $hash; $sweep->save();
            $this->ledger->book($sweep->wallet, 'market_fee_sweep', '0', '-' . $sweep->amount_atomic, 'market_fee_sweep', $sweep->id, 'market-fee-broadcast-' . $sweep->id, null, 'Blockchain transaction ' . $hash, true);
        } catch (\Exception $exception) {
            report($exception);
            $sweep->status = $hash ? 'broadcast' : 'failed'; $sweep->error = $exception->getMessage(); $sweep->save();
            foreach (Admin::allUsers() as $admin) {
                $admin->notify('A ' . strtoupper($sweep->coin) . ' market-fee transfer failed and requires review.', 'admin.wallet.exchanges');
            }
        }
    }

    private function transactionHash($result)
    {
        if (is_string($result) && $result !== '') return $result;
        if (is_array($result) && !empty($result['tx_hash'])) return $result['tx_hash'];
        if (is_object($result) && !empty($result->tx_hash)) return $result->tx_hash;
        return null;
    }
}
