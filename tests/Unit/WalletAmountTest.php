<?php

namespace Tests\Unit;

use App\Services\WalletLedgerService;
use App\Wallet;
use Tests\TestCase;

class WalletAmountTest extends TestCase
{
    public function testCoinAmountsAreConvertedToAtomicUnitsWithoutDroppingPrecision()
    {
        $service = new WalletLedgerService();

        $this->assertSame('123456789', $service->coinToAtomic('1.23456789', 'btc'));
        $this->assertSame('1', $service->coinToAtomic('0.000000000001', 'xmr'));
        $this->assertSame('100000000', $service->coinToAtomic('1', 'ltc'));
    }

    public function testWalletBalancesAreFormattedUsingCoinPrecision()
    {
        $wallet = new Wallet();
        $wallet->coin = 'xmr';
        $wallet->available_atomic = '1234567890123';
        $wallet->reserved_atomic = '1';

        $this->assertSame('1.234567890123', $wallet->available_display);
        $this->assertSame('0.000000000001', $wallet->reserved_display);
    }
}
