<?php

namespace App;

use App\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;

class MarketFeeWallet extends Model
{
    use Uuids;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
