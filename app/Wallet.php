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
}
