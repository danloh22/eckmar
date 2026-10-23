<?php

namespace Tests\Unit;

use App\Exceptions\RequestException;
use App\Services\WalletLedgerService;
use App\Wallet;
use App\WalletExchange;
use App\WithdrawalRequest;
use App\MarketFeeSweep;
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

    public function testExactAmountsMayIncludeOnlyZeroesBeyondCoinPrecision()
    {
        $service = new WalletLedgerService();

        $this->assertSame('123000000', $service->coinToAtomic('1.230000000', 'btc'));
    }

    /**
     * @dataProvider lossyAmountProvider
     */
    public function testCoinAmountsRejectNonZeroDigitsBeyondSupportedPrecision($amount, $coin)
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Amount exceeds the supported coin precision.');

        (new WalletLedgerService())->coinToAtomic($amount, $coin);
    }

    public function lossyAmountProvider()
    {
        return [
            ['1.000000009', 'btc'],
            ['0.0000000000001', 'xmr'],
            ['2.123456789', 'ltc'],
        ];
    }

    /**
     * @dataProvider invalidAmountProvider
     */
    public function testCoinAmountsRejectInvalidDecimalRepresentations($amount)
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Amount must be a non-negative decimal value.');

        (new WalletLedgerService())->coinToAtomic($amount, 'btc');
    }

    public function invalidAmountProvider()
    {
        return [
            ['-1'],
            [-1],
            ['1e-8'],
            [' 1.0'],
            [INF],
            [NAN],
        ];
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

    public function testWithdrawalAmountsUseTheCorrectCurrencyPrecision()
    {
        $bitcoin = new WithdrawalRequest(['coin' => 'btc', 'amount_atomic' => '123456789']);
        $monero = new WithdrawalRequest(['coin' => 'xmr', 'amount_atomic' => '1000000000001']);

        $this->assertSame('1.23456789', $bitcoin->amount_display);
        $this->assertSame('1.000000000001', $monero->amount_display);
    }

    public function testFeeSweepAmountsUseTheCorrectCurrencyPrecision()
    {
        $sweep = new MarketFeeSweep(['coin' => 'ltc', 'amount_atomic' => '250000001']);

        $this->assertSame('2.50000001', $sweep->amount_display);
    }
}
