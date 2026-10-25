<?php

namespace App\Services;

use App\Exceptions\RequestException;
use App\Wallet;
use App\Purchase;
use App\User;
use App\WalletEscrowHold;
use App\WalletLedgerEntry;
use App\MarketFeeWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class WalletLedgerService
{
    public function walletFor($userId, string $coin): Wallet
    {
        $identity = ['user_id' => $userId, 'coin' => $coin];

        try {
            return Wallet::firstOrCreate($identity, ['status' => 'active']);
        } catch (QueryException $exception) {
            // firstOrCreate performs a read followed by an insert. Two first
            // requests for the same user/coin can therefore race at the unique
            // constraint. A locking read observes the winning committed row;
            // unrelated persistence errors still propagate unchanged.
            $wallet = Wallet::where($identity)->lockForUpdate()->first();
            if ($wallet !== null) {
                return $wallet;
            }

            throw $exception;
        }
    }

    public function reservePurchase(Purchase $purchase): WalletEscrowHold
    {
        return DB::transaction(function () use ($purchase) {
            $amount = $this->coinToAtomic($purchase->to_pay, $purchase->coin_name);
            $wallet = $this->walletFor($purchase->buyer_id, $purchase->coin_name);

            $this->book($wallet, 'escrow_hold', '-' . $amount, $amount, 'purchase', $purchase->id, 'purchase-hold-' . $purchase->id, $purchase->buyer_id);

            return WalletEscrowHold::firstOrCreate(['purchase_id' => $purchase->id], [
                'buyer_wallet_id' => $wallet->id,
                'coin' => $purchase->coin_name,
                'amount_atomic' => $amount,
                'status' => 'active',
            ]);
        });
    }

    public function releasePurchase(Purchase $purchase, User $recipient, string $type = 'escrow_release')
    {
        DB::transaction(function () use ($purchase, $recipient, $type) {
            $hold = WalletEscrowHold::where('purchase_id', $purchase->id)->lockForUpdate()->firstOrFail();
            if ($hold->status !== 'active') {
                throw new RequestException('Purchase escrow was already settled.');
            }

            $fee = gmp_strval(gmp_div_q(gmp_add(gmp_mul($hold->amount_atomic, (int) config('marketplace.market_fee_percent', 3)), 99), 100));
            $recipientAmount = gmp_strval(gmp_sub($hold->amount_atomic, $fee));
            $recipientWallet = $this->walletFor($recipient->id, $hold->coin);
            $marketWallet = $this->marketWalletFor($hold->coin);

            $this->book($hold->buyerWallet, $type, '0', '-' . $hold->amount_atomic, 'purchase', $purchase->id, 'purchase-settle-buyer-' . $purchase->id, null, null, true);
            $this->book($recipientWallet, $type, $recipientAmount, '0', 'purchase', $purchase->id, 'purchase-settle-recipient-' . $purchase->id, null, null, true);
            if (gmp_cmp($fee, '0') > 0) {
                $this->book($marketWallet, 'market_fee_credit', $fee, '0', 'purchase', $purchase->id, 'purchase-settle-fee-' . $purchase->id, null, null, true);
            }

            $hold->status = 'released';
            $hold->released_to = $recipient->id;
            $hold->settled_at = now();
            $hold->save();
        });
    }

    public function refundPurchase(Purchase $purchase)
    {
        DB::transaction(function () use ($purchase) {
            $hold = WalletEscrowHold::where('purchase_id', $purchase->id)->lockForUpdate()->firstOrFail();
            if ($hold->status !== 'active') {
                throw new RequestException('Purchase escrow was already settled.');
            }
            $this->book($hold->buyerWallet, 'escrow_release', $hold->amount_atomic, '-' . $hold->amount_atomic, 'purchase', $purchase->id, 'purchase-refund-' . $purchase->id, null, null, true);
            $hold->status = 'refunded';
            $hold->released_to = $purchase->buyer_id;
            $hold->settled_at = now();
            $hold->save();
        });
    }

    public function coinToAtomic($amount, string $coin): string
    {
        $decimals = (int) config('coins.atomic_decimals.' . $coin);
        if ($decimals < 0) {
            throw new RequestException('Coin precision is not configured correctly.');
        }

        if (is_int($amount) && $amount >= 0) {
            $normalized = (string) $amount;
        } elseif (is_float($amount) && is_finite($amount) && $amount >= 0) {
            // Floats have already lost their original decimal representation.
            // Keep the legacy scalar contract, while exact string inputs below
            // are validated before any precision can be discarded.
            $normalized = number_format($amount, $decimals, '.', '');
        } elseif (is_string($amount)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/', $amount)) {
            $normalized = $amount;
        } else {
            throw new RequestException('Amount must be a non-negative decimal value.');
        }

        $parts = explode('.', $normalized, 2);
        $suppliedFraction = $parts[1] ?? '';
        $discardedFraction = substr($suppliedFraction, $decimals);
        if ($discardedFraction !== '' && trim($discardedFraction, '0') !== '') {
            throw new RequestException('Amount exceeds the supported coin precision.');
        }

        $fraction = str_pad(substr($suppliedFraction, 0, $decimals), $decimals, '0');
        $atomic = ltrim($parts[0] . $fraction, '0');
        return $atomic === '' ? '0' : $atomic;
    }

    public function marketWalletFor(string $coin): Wallet
    {
        return DB::transaction(function () use ($coin) {
            $configuration = MarketFeeWallet::where('coin', $coin)->lockForUpdate()->first();
            if (!$configuration) {
                throw new RequestException('The administrator must configure the ' . strtoupper($coin) . ' market wallet first.');
            }
            if ($configuration->wallet_id) {
                return Wallet::findOrFail($configuration->wallet_id);
            }
            $wallet = Wallet::create(['user_id' => null, 'coin' => $coin, 'status' => 'active']);
            $configuration->wallet_id = $wallet->id;
            $configuration->save();
            return $wallet;
        });
    }

    public function book(Wallet $wallet, string $type, string $availableDelta, string $reservedDelta, string $referenceType, $referenceId, string $idempotencyKey, $createdBy = null, $reason = null, bool $allowFrozen = false): WalletLedgerEntry
    {
        return DB::transaction(function () use ($wallet, $type, $availableDelta, $reservedDelta, $referenceType, $referenceId, $idempotencyKey, $createdBy, $reason, $allowFrozen) {
            // Serialize duplicate requests for this wallet before reading the key.
            // The locking read avoids a stale snapshot after waiting on another
            // transaction, while the database unique constraint remains a backstop.
            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            $entry = WalletLedgerEntry::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($entry) {
                // A key identifies exactly one ledger operation, not merely a
                // request to touch any wallet. Reject conflicting replays.
                if ((string) $entry->wallet_id !== (string) $lockedWallet->id
                    || (string) $entry->type !== $type
                    || (string) $entry->available_delta_atomic !== $availableDelta
                    || (string) $entry->reserved_delta_atomic !== $reservedDelta
                    || (string) $entry->reference_type !== $referenceType
                    || (string) $entry->reference_id !== (string) $referenceId) {
                    throw new RequestException('Idempotency key was already used for a different ledger operation.');
                }
                return $entry;
            }
            if (!$allowFrozen && $lockedWallet->status !== 'active') {
                throw new RequestException('Wallet is frozen.');
            }
            $newAvailable = $this->add($lockedWallet->available_atomic, $availableDelta);
            $newReserved = $this->add($lockedWallet->reserved_atomic, $reservedDelta);
            if ($this->isNegative($newAvailable) || $this->isNegative($newReserved)) {
                throw new RequestException('Insufficient wallet balance.');
            }

            $lockedWallet->available_atomic = $newAvailable;
            $lockedWallet->reserved_atomic = $newReserved;
            $lockedWallet->save();

            return WalletLedgerEntry::create([
                'type' => $type,
                'wallet_id' => $lockedWallet->id,
                'coin' => $lockedWallet->coin,
                'available_delta_atomic' => $availableDelta,
                'reserved_delta_atomic' => $reservedDelta,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $createdBy,
                'reason' => $reason,
            ]);
        });
    }

    private function add($left, $right): string
    {
        return gmp_strval(gmp_add($left, $right));
    }

    private function isNegative(string $value): bool
    {
        return gmp_cmp($value, '0') < 0;
    }
}
