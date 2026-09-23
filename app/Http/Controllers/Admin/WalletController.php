<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WalletLedgerService;
use App\WithdrawalRequest;
use App\MarketFeeWallet;
use App\MarketFeeSweep;
use App\WalletExchange;
use App\Wallet;
use App\Exceptions\RequestException;
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

    public function wallets(Request $request)
    {
        abort_unless(auth()->user()->isAdmin() || auth()->user()->hasPermission('wallets'), 403);
        $wallets = Wallet::with(['user', 'depositAddresses'])->whereNotNull('user_id');
        if ($request->filled('coin') && in_array($request->coin, ['btc', 'xmr', 'ltc'], true)) {
            $wallets->where('coin', $request->coin);
        }
        if ($request->filled('username')) {
            $wallets->whereHas('user', function ($query) use ($request) {
                $query->where('username', 'like', '%' . $request->username . '%');
            });
        }
        return view('admin.wallet.index', ['wallets' => $wallets->latest()->paginate(50)]);
    }

    public function setWalletStatus(Wallet $wallet, string $status)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_unless(in_array($status, ['active', 'frozen'], true), 422);
        abort_if($wallet->user_id === null, 422);
        $wallet->status = $status;
        $wallet->save();
        $wallet->user->notify('Your ' . strtoupper($wallet->coin) . ' wallet was ' . ($status === 'frozen' ? 'frozen' : 'unfrozen') . ' by an administrator.', 'profile.wallet');
        return redirect()->back()->with('success', 'Wallet status updated.');
    }

    public function adjustWallet(Request $request, Wallet $wallet)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        abort_if($wallet->user_id === null, 422);
        $decimals = (int) config('coins.atomic_decimals.' . $wallet->coin);
        $request->validate([
            'action' => 'required|in:credit,debit',
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,' . $decimals . '})?$/'],
            'reason' => 'required|string|min:10|max:500',
        ]);
        $amount = $this->ledger->coinToAtomic($request->amount, $wallet->coin);
        abort_if(gmp_cmp($amount, '0') <= 0, 422);
        $delta = $request->action === 'debit' ? '-' . $amount : $amount;
        try {
            $this->ledger->book($wallet, $request->action === 'debit' ? 'admin_debit' : 'admin_credit', $delta, '0', 'admin_wallet_adjustment', null, 'admin-wallet-' . str_random(32), auth()->id(), $request->reason, true);
        } catch (RequestException $exception) {
            return redirect()->back()->withInput()->with('errormessage', $exception->getMessage());
        }
        $wallet->user->notify('An administrator ' . ($request->action === 'debit' ? 'debited' : 'credited') . ' your ' . strtoupper($wallet->coin) . ' wallet. Reason: ' . $request->reason, 'profile.wallet');
        return redirect()->back()->with('success', 'Wallet balance adjusted.');
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

    public function resolveFailedWithdrawal(Request $request, WithdrawalRequest $withdrawal)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $request->validate([
            'resolution' => 'required|in:retry,refund',
            'reason' => 'required|string|min:10|max:500',
        ]);

        DB::transaction(function () use ($request, $withdrawal) {
            $locked = WithdrawalRequest::where('id', $withdrawal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'failed', 422);

            if ($request->resolution === 'retry') {
                $locked->status = 'approved';
                $locked->approved_by = auth()->id();
                $locked->approved_at = now();
                $locked->admin_note = 'Retry authorized: ' . $request->reason;
                $locked->save();
                return;
            }

            $this->ledger->book(
                $locked->wallet,
                'withdrawal_reversal',
                $locked->amount_atomic,
                '-' . $locked->amount_atomic,
                'withdrawal_request',
                $locked->id,
                'withdrawal-reversal-' . $locked->id,
                auth()->id(),
                'Failed withdrawal refunded: ' . $request->reason,
                true
            );
            $locked->status = 'cancelled';
            $locked->approved_by = auth()->id();
            $locked->approved_at = now();
            $locked->admin_note = 'Funds returned: ' . $request->reason;
            $locked->save();
        });

        $withdrawal->refresh();
        $message = $request->resolution === 'retry'
            ? 'Your failed withdrawal was reviewed and queued for another broadcast attempt.'
            : 'Your failed withdrawal was reviewed and the reserved funds were returned to your wallet.';
        $withdrawal->wallet->user->notify($message, 'profile.wallet');

        return redirect()->route('admin.wallet.withdrawals')->with('success', 'Failed withdrawal resolution recorded.');
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
        $decimals = (int) config('coins.atomic_decimals.' . $coin);
        $request->validate([
            'action' => 'required|in:credit,debit',
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,' . $decimals . '})?$/'],
            'reason' => 'required|string|min:10|max:500',
        ]);
        $wallet = $this->ledger->marketWalletFor($coin);
        $amount = $this->ledger->coinToAtomic($request->amount, $coin);
        abort_if(gmp_cmp($amount, '0') <= 0, 422);
        $delta = $request->action === 'debit' ? '-' . $amount : $amount;
        $this->ledger->book($wallet, $request->action === 'debit' ? 'admin_debit' : 'admin_credit', $delta, '0', 'market_liquidity_adjustment', null, 'market-liquidity-' . str_random(32), auth()->id(), $request->reason, true);
        return redirect()->route('admin.wallet.exchanges')->with('success', strtoupper($coin) . ' exchange liquidity adjusted.');
    }

    public function resolveFailedFeeSweep(Request $request, MarketFeeSweep $sweep)
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $request->validate([
            'resolution' => 'required|in:retry,refund',
            'reason' => 'required|string|min:10|max:500',
        ]);

        DB::transaction(function () use ($request, $sweep) {
            $locked = MarketFeeSweep::where('id', $sweep->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'failed' && $locked->resolution === null, 422);

            if ($request->resolution === 'retry') {
                $locked->status = 'pending';
                $locked->error = null;
                $locked->resolution_note = 'Retry authorized: ' . $request->reason;
            } else {
                $this->ledger->book($locked->wallet, 'market_fee_sweep', $locked->amount_atomic, '-' . $locked->amount_atomic, 'market_fee_sweep', $locked->id, 'market-fee-refund-' . $locked->id, auth()->id(), 'Failed fee sweep returned: ' . $request->reason, true);
                $locked->resolution = 'refunded';
                $locked->resolution_note = 'Reserved fees returned: ' . $request->reason;
            }
            $locked->resolved_by = auth()->id();
            $locked->resolved_at = now();
            $locked->save();
        });

        return redirect()->route('admin.wallet.exchanges')->with('success', 'Failed fee transfer resolution recorded.');
    }
}
