<?php

namespace App\Services;

use App\Exceptions\RequestException;
use App\Wallet;
use App\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;

class WalletLedgerService
{
    public function walletFor($userId, string $coin): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $userId, 'coin' => $coin], ['status' => 'active']);
    }

    public function book(Wallet $wallet, string $type, string $availableDelta, string $reservedDelta, string $referenceType, $referenceId, string $idempotencyKey, $createdBy = null, $reason = null): WalletLedgerEntry
    {
        return DB::transaction(function () use ($wallet, $type, $availableDelta, $reservedDelta, $referenceType, $referenceId, $idempotencyKey, $createdBy, $reason) {
            $entry = WalletLedgerEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($entry) {
                return $entry;
            }

            $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            if ($lockedWallet->status !== 'active') {
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
