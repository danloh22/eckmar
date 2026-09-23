<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use Uuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['user_id', 'coin', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function deposits()
    {
        return $this->hasMany(WalletDeposit::class);
    }

    public function depositAddresses()
    {
        return $this->hasMany(DepositAddress::class);
    }

    public function getAvailableDisplayAttribute(): string
    {
        return $this->formatAtomic($this->available_atomic);
    }

    public function getReservedDisplayAttribute(): string
    {
        return $this->formatAtomic($this->reserved_atomic);
    }

    private function formatAtomic($amount): string
    {
        $decimals = (int) config('coins.atomic_decimals.' . $this->coin, 8);
        $value = str_pad((string) $amount, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($value, 0, -$decimals) . '.' . substr($value, -$decimals);
    }
}
