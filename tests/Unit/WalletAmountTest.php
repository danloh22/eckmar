<?php

namespace Tests\Unit;

use App\Services\WalletLedgerService;
use App\Wallet;
use App\WalletExchange;
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

    public function testExchangeAmountsUseTheCorrectCurrencyPrecision()
    {
        $exchange = new WalletExchange();
        $exchange->source_coin = 'xmr';
        $exchange->target_coin = 'ltc';
        $exchange->source_amount_atomic = '1000000000000';
        $exchange->target_amount_atomic = '123456789';
        $exchange->fee_amount_atomic = '370370';

        $this->assertSame('1.000000000000', $exchange->source_amount_display);
        $this->assertSame('1.23456789', $exchange->target_amount_display);
        $this->assertSame('0.00370370', $exchange->fee_amount_display);
    }
}
