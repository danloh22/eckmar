<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class MarketFeeSweep extends Model
{
    use Uuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    public function wallet() { return $this->belongsTo(Wallet::class); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
    public function getAmountDisplayAttribute() {
        $decimals = (int) config('coins.atomic_decimals.' . $this->coin, 8);
        $value = str_pad((string) $this->amount_atomic, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($value, 0, -$decimals) . '.' . substr($value, -$decimals);
    }
}
