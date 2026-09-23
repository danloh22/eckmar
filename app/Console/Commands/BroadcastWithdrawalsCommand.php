<?php

namespace App\Console\Commands;

use App\Admin;
use App\Marketplace\Payment\Payment;
use App\Services\WalletLedgerService;
use App\WithdrawalRequest;
use Illuminate\Console\Command;

class BroadcastWithdrawalsCommand extends Command
{
    protected $signature = 'wallets:broadcast-withdrawals {--limit=20}';
    protected $description = 'Broadcast administrator-approved wallet withdrawals.';

    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        parent::__construct();
        $this->ledger = $ledger;
    }

    public function handle()
    {
        $withdrawals = WithdrawalRequest::where('status', 'approved')
            ->oldest('approved_at')->limit(max(1, (int) $this->option('limit')))->get();

        foreach ($withdrawals as $withdrawal) {
            $claimed = WithdrawalRequest::where('id', $withdrawal->id)
                ->where('status', 'approved')->update(['status' => 'broadcasting']);
            if (!$claimed) {
                continue;
            }

            $transactionHash = null;
            try {
                $coin = Payment::coinService($withdrawal->coin);
                $result = $coin->sendToAddress(
                    $withdrawal->destination_address,
                    $this->atomicToCoin($withdrawal->amount_atomic, config('coins.atomic_decimals.' . $withdrawal->coin))
                );
                $transactionHash = $this->transactionHash($result);

                // Persist the external side effect before local settlement. A crash after
                // broadcast must never make this request eligible for another send.
                $withdrawal->status = 'broadcast';
                $withdrawal->transaction_hash = $transactionHash;
                $withdrawal->save();

                $this->ledger->book($withdrawal->wallet, 'withdrawal', '0', '-' . $withdrawal->amount_atomic, 'withdrawal_request', $withdrawal->id, 'withdrawal-broadcast-' . $withdrawal->id, $withdrawal->approved_by, 'Blockchain transaction ' . $transactionHash, true);
                $withdrawal->wallet->user->notify('Your ' . strtoupper($withdrawal->coin) . ' withdrawal was broadcast. Transaction: ' . $transactionHash, 'profile.wallet');
                $this->info('Broadcast withdrawal ' . $withdrawal->id . '.');
            } catch (\Exception $exception) {
                report($exception);
                $withdrawal->status = $transactionHash ? 'broadcast' : 'failed';
                $withdrawal->admin_note = $exception->getMessage();
                $withdrawal->save();
                foreach (Admin::allUsers() as $admin) {
                    $admin->notify('Withdrawal ' . $withdrawal->id . ' failed and requires review.', 'admin.wallet.withdrawals');
                }
                $this->error('Withdrawal ' . $withdrawal->id . ' failed: ' . $exception->getMessage());
            }
        }
    }

    private function atomicToCoin(string $amount, int $decimals): float
    {
        $divisor = gmp_pow(10, $decimals);
        $whole = gmp_strval(gmp_div_q($amount, $divisor));
        $fraction = str_pad(gmp_strval(gmp_mod($amount, $divisor)), $decimals, '0', STR_PAD_LEFT);
        return (float) ($whole . '.' . $fraction);
    }

    private function transactionHash($result): string
    {
        if (is_string($result) && $result !== '') {
            return $result;
        }
        if (is_array($result) && !empty($result['tx_hash'])) {
            return $result['tx_hash'];
        }
        if (is_object($result) && !empty($result->tx_hash)) {
            return $result->tx_hash;
        }
        throw new \RuntimeException('Wallet RPC did not return a transaction hash.');
    }
}
