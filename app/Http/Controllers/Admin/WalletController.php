<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WalletLedgerService;
use App\WithdrawalRequest;
use App\MarketFeeWallet;
use App\MarketFeeSweep;
use App\WalletExchange;
use Illuminate\Http\Request;
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
            'withdrawals' => WithdrawalRequest::with('wallet.user')->whereIn('status', ['pending_approval', 'approved', 'broadcasting', 'failed'])->latest()->paginate(30),
        ]);
    }

    public function approve(WithdrawalRequest $withdrawal)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $approved = WithdrawalRequest::where('id', $withdrawal->id)
            ->where('status', 'pending_approval')
            ->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        abort_unless($approved === 1, 422);
        $withdrawal->refresh();
        $withdrawal->wallet->user->notify('Your ' . strtoupper($withdrawal->coin) . ' withdrawal has been approved and is queued for broadcast.', 'profile.wallet');

        return redirect()->route('admin.wallet.withdrawals')->with('success', 'Withdrawal approved.');
    }

    public function reject(WithdrawalRequest $withdrawal)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        DB::transaction(function () use ($withdrawal) {
            $rejected = WithdrawalRequest::where('id', $withdrawal->id)
                ->where('status', 'pending_approval')
                ->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            abort_unless($rejected === 1, 422);
            $this->ledger->book($withdrawal->wallet, 'withdrawal_reversal', $withdrawal->amount_atomic, '-' . $withdrawal->amount_atomic, 'withdrawal_request', $withdrawal->id, 'withdrawal-reversal-' . $withdrawal->id, auth()->id(), 'Rejected by administrator');
        });
        $withdrawal->wallet->user->notify('Your ' . strtoupper($withdrawal->coin) . ' withdrawal was rejected and the funds were returned to your wallet.', 'profile.wallet');

        return redirect()->route('admin.wallet.withdrawals')->with('success', 'Withdrawal rejected and funds returned.');
    }

    public function exchanges()
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasPermission('wallets'), 403);
        return view('admin.wallet.exchanges', [
            'exchanges' => WalletExchange::with(['user', 'sourceWallet', 'targetWallet'])->latest()->paginate(50),
            'feeWallets' => MarketFeeWallet::whereIn('coin', ['btc', 'xmr', 'ltc'])->get()->keyBy('coin'),
            'feeSweeps' => MarketFeeSweep::latest()->limit(50)->get(),
        ]);
    }

    public function updateFeeWallet(Request $request, string $coin)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless(in_array($coin, ['btc', 'xmr', 'ltc'], true), 404);
        $request->validate(['address' => 'required|string|max:255']);
        MarketFeeWallet::updateOrCreate(['coin' => $coin], [
            'address' => $request->address,
            'updated_by' => auth()->id(),
        ]);
        $this->ledger->marketWalletFor($coin);
        return redirect()->route('admin.wallet.exchanges')->with('success', strtoupper($coin) . ' fee wallet updated.');
    }

    public function adjustMarketLiquidity(Request $request, string $coin)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless(in_array($coin, ['btc', 'xmr', 'ltc'], true), 404);
        $request->validate([
            'action' => 'required|in:credit,debit',
            'amount' => 'required|regex:/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/',
            'reason' => 'required|string|min:10|max:500',
        ]);
        $wallet = $this->ledger->marketWalletFor($coin);
        $amount = $this->ledger->coinToAtomic($request->amount, $coin);
        abort_if(gmp_cmp($amount, '0') <= 0, 422);
        $delta = $request->action === 'debit' ? '-' . $amount : $amount;
        $this->ledger->book($wallet, $request->action === 'debit' ? 'admin_debit' : 'admin_credit', $delta, '0', 'market_liquidity_adjustment', null, 'market-liquidity-' . str_random(32), auth()->id(), $request->reason, true);
        return redirect()->route('admin.wallet.exchanges')->with('success', strtoupper($coin) . ' exchange liquidity adjusted.');
    }
}
