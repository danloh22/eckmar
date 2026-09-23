<?php

namespace App\Console\Commands;

use App\Admin;
use App\DepositAddress;
use App\Marketplace\Payment\Payment;
use App\Services\WalletLedgerService;
use App\WalletDeposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanWalletDepositsCommand extends Command
{
    protected $signature = 'wallets:scan-deposits';
    protected $description = 'Scan configured coin wallet RPCs for user deposits.';

    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        parent::__construct();
        $this->ledger = $ledger;
    }

    public function handle()
    {
        DepositAddress::where('active', true)->with('wallet')->chunk(100, function ($addresses) {
            foreach ($addresses as $address) {
                try {
                    $transfers = Payment::coinService($address->coin)->incomingTransfers($address->address);
                    foreach ($transfers as $transfer) {
                        $this->recordTransfer($address, $transfer);
                    }
                } catch (\Exception $exception) {
                    report($exception);
                    $this->error(strtoupper($address->coin) . ' scan failed for address ' . $address->id . ': ' . $exception->getMessage());
                }
            }
        });
    }

    private function recordTransfer(DepositAddress $address, array $transfer)
    {
        DB::transaction(function () use ($address, $transfer) {
            $deposit = WalletDeposit::firstOrCreate([
                'coin' => $address->coin,
                'transaction_hash' => $transfer['transaction_hash'],
                'transaction_output' => $transfer['transaction_output'],
            ], [
                'wallet_id' => $address->wallet_id,
                'deposit_address_id' => $address->id,
                'amount_atomic' => $transfer['amount_atomic'],
            ]);

            $deposit = WalletDeposit::where('id', $deposit->id)->lockForUpdate()->firstOrFail();
            if ($deposit->wallet_id !== $address->wallet_id || $deposit->deposit_address_id !== $address->id || gmp_cmp($deposit->amount_atomic, $transfer['amount_atomic']) !== 0) {
                throw new \RuntimeException('Deposit identity or amount changed during RPC reconciliation.');
            }
            $wallet = $deposit->wallet;
            $deposit->confirmations = $transfer['confirmations'];
            $deposit->block_height = $transfer['block_height'];
            $required = (int) config('coins.wallet_confirmations.' . $address->coin, 10);

            if ($deposit->status === 'credited' && $deposit->confirmations < $required) {
                $deposit->status = 'reorged';
                $wallet->status = 'frozen';
                $wallet->save();
                $wallet->user->notify('Your wallet was frozen because a confirmed deposit lost confirmations. Support will review it.', 'profile.wallet');
                foreach (Admin::allUsers() as $admin) {
                    $admin->notify('A credited ' . strtoupper($address->coin) . ' deposit lost confirmations; wallet ' . $address->wallet_id . ' was frozen.', 'admin.wallet.withdrawals');
                }
            } elseif ($deposit->status !== 'credited' && $deposit->confirmations >= $required) {
                $this->ledger->book($wallet, 'deposit', $deposit->amount_atomic, '0', 'wallet_deposit', $deposit->id, 'deposit-' . $address->coin . '-' . $deposit->transaction_hash . '-' . $deposit->transaction_output);
                $deposit->status = 'credited';
                $deposit->credited_at = now();
                $wallet->user->notify('Your ' . strtoupper($address->coin) . ' deposit is now available.', 'profile.wallet');
            } elseif ($deposit->status !== 'credited') {
                $deposit->status = $deposit->confirmations > 0 ? 'confirming' : 'detected';
            }
            $deposit->save();
        });
    }
}
