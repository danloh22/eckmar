<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WalletLedgerService;
use App\WithdrawalRequest;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        $this->middleware('admin_panel_access');
        $this->ledger = $ledger;
    }

    public function withdrawals()
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasPermission('withdrawals'), 403);

        return view('admin.wallet.withdrawals', [
            'withdrawals' => WithdrawalRequest::whereIn('status', ['pending_approval', 'approved', 'broadcasting', 'failed'])->latest()->paginate(30),
        ]);
    }

    public function approve(WithdrawalRequest $withdrawal)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($withdrawal->status === 'pending_approval', 422);

        $withdrawal->status = 'approved';
        $withdrawal->approved_by = auth()->id();
        $withdrawal->approved_at = now();
        $withdrawal->save();
        $withdrawal->wallet->user->notify('Your ' . strtoupper($withdrawal->coin) . ' withdrawal has been approved and is queued for broadcast.', 'profile.wallet');

        return redirect()->route('admin.wallet.withdrawals')->with('success', 'Withdrawal approved.');
    }

    public function reject(WithdrawalRequest $withdrawal)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless($withdrawal->status === 'pending_approval', 422);

        DB::transaction(function () use ($withdrawal) {
            $this->ledger->book($withdrawal->wallet, 'withdrawal_reversal', $withdrawal->amount_atomic, '-' . $withdrawal->amount_atomic, 'withdrawal_request', $withdrawal->id, 'withdrawal-reversal-' . $withdrawal->id, auth()->id(), 'Rejected by administrator');
            $withdrawal->status = 'rejected';
            $withdrawal->approved_by = auth()->id();
            $withdrawal->approved_at = now();
            $withdrawal->save();
        });
        $withdrawal->wallet->user->notify('Your ' . strtoupper($withdrawal->coin) . ' withdrawal was rejected and the funds were returned to your wallet.', 'profile.wallet');

        return redirect()->route('admin.wallet.withdrawals')->with('success', 'Withdrawal rejected and funds returned.');
    }
}
