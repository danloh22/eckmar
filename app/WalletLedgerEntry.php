<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    use Uuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
