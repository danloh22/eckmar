<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class WalletEscrowHold extends Model
{
    use Uuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function buyerWallet()
    {
        return $this->belongsTo(Wallet::class, 'buyer_wallet_id');
    }
}
