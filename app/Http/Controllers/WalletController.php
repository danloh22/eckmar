<?php

namespace App\Http\Controllers;

use App\DepositAddress;
use App\Admin;
use App\Marketplace\Payment\Payment;
use App\Services\WalletLedgerService;
use App\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WalletController extends Controller
{
    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        $this->middleware(['auth', 'verify_2fa']);
        $this->ledger = $ledger;
    }

    public function index()
    {
        $wallets = [];
        foreach (['btc', 'xmr', 'ltc'] as $coin) {
            $wallet = $this->ledger->walletFor(auth()->id(), $coin);
            $wallets[$coin] = [
                'wallet' => $wallet,
                'address' => DepositAddress::where('wallet_id', $wallet->id)->where('active', true)->latest()->first(),
                'deposits' => $wallet->deposits()->latest()->limit(10)->get(),
            ];
        }

        return view('profile.wallet', compact('wallets'));
    }

    public function createDepositAddress(Request $request, string $coin)
    {
        abort_unless(in_array($coin, ['btc', 'xmr', 'ltc'], true), 404);
        $wallet = $this->ledger->walletFor(auth()->id(), $coin);

        try {
            $service = Payment::coinService($coin);
            $parameters = $coin === 'btc' || $coin === 'ltc'
                ? ['btc_user' => 'wallet-' . $wallet->id]
                : [];
            $address = $service->generateAddress($parameters);

            DepositAddress::firstOrCreate(
                ['coin' => $coin, 'address' => $address],
                ['wallet_id' => $wallet->id, 'derivation_reference' => $wallet->id, 'active' => true]
            );
        } catch (\Exception $exception) {
            report($exception);
            return redirect()->route('profile.wallet')
                ->with('errormessage', 'A new deposit address could not be created. Please try again later.');
        }

        return redirect()->route('profile.wallet')->with('success', 'A new ' . strtoupper($coin) . ' deposit address has been created.');
    }

    public function requestWithdrawal(Request $request, string $coin)
    {
        abort_unless(in_array($coin, ['btc', 'xmr', 'ltc'], true), 404);
        $request->validate([
            'amount_atomic' => 'required|regex:/^[1-9][0-9]*$/',
            'destination_address' => 'required|string|max:255',
            'pin' => 'required|digits:6',
        ]);

        $user = auth()->user();
        if (!$user->withdrawal_pin || !Hash::check($request->pin, $user->withdrawal_pin)) {
            return redirect()->back()->withInput()->with('errormessage', 'The withdrawal PIN is invalid.');
        }

        try {
            DB::transaction(function () use ($coin, $request, $user) {
                $wallet = $this->ledger->walletFor($user->id, $coin);
                $withdrawal = WithdrawalRequest::create([
                    'wallet_id' => $wallet->id,
                    'coin' => $coin,
                    'destination_address' => $request->destination_address,
                    'amount_atomic' => $request->amount_atomic,
                ]);
                $this->ledger->book($wallet, 'withdrawal_hold', '-' . $request->amount_atomic, $request->amount_atomic, 'withdrawal_request', $withdrawal->id, 'withdrawal-hold-' . $withdrawal->id, $user->id);
            });
        } catch (\Exception $exception) {
            report($exception);
            return redirect()->back()->withInput()->with('errormessage', 'The withdrawal request could not be created.');
        }

        foreach (Admin::allUsers() as $admin) {
            $admin->notify('A new ' . strtoupper($coin) . ' withdrawal request requires approval.', 'admin.wallet.withdrawals');
        }

        return redirect()->route('profile.wallet')->with('success', 'Withdrawal request submitted for administrator approval.');
    }

    public function setWithdrawalPin(Request $request)
    {
        $request->validate(['mnemonic' => 'required', 'pin' => 'required|digits:6|confirmed']);
        $user = auth()->user();
        if (!Hash::check(hash('sha256', $request->mnemonic), $user->mnemonic)) {
            return redirect()->back()->with('errormessage', 'The mnemonic is invalid.');
        }

        $user->withdrawal_pin = Hash::make($request->pin);
        $user->withdrawal_pin_reset_required_at = null;
        $user->save();

        return redirect()->route('profile.wallet')->with('success', 'Withdrawal PIN saved.');
    }
}
