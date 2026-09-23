<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class WalletExchange extends Model
{
    use Uuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function sourceWallet() { return $this->belongsTo(Wallet::class, 'source_wallet_id'); }
    public function targetWallet() { return $this->belongsTo(Wallet::class, 'target_wallet_id'); }
    public function getSourceAmountDisplayAttribute() { return $this->formatAtomic($this->source_amount_atomic, $this->source_coin); }
    public function getTargetAmountDisplayAttribute() { return $this->formatAtomic($this->target_amount_atomic, $this->target_coin); }
    public function getFeeAmountDisplayAttribute() { return $this->formatAtomic($this->fee_amount_atomic, $this->target_coin); }
    private function formatAtomic($amount, $coin) {
        $decimals = (int) config('coins.atomic_decimals.' . $coin, 8);
        $value = str_pad((string) $amount, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($value, 0, -$decimals) . '.' . substr($value, -$decimals);
    }
}
