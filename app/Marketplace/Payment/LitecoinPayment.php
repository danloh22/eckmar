<?php

namespace App\Marketplace\Payment;

class LitecoinPayment extends BitcoinPayment
{
    protected function rpcConfigKey(): string
    {
        return 'litecoin';
    }

    function coinLabel(): string
    {
        return 'ltc';
    }
}
