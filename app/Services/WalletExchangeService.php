<?php

namespace App\Services;

use App\Exceptions\RequestException;
use App\Marketplace\Utility\CoinConverter;
use App\User;
use App\WalletExchange;
use Illuminate\Support\Facades\DB;

class WalletExchangeService
{
    private $ledger;

    public function __construct(WalletLedgerService $ledger)
    {
        $this->ledger = $ledger;
    }

    public function exchange(User $user, string $sourceCoin, string $targetCoin, string $sourceAmount): WalletExchange
    {
        if ($sourceCoin === $targetCoin || !in_array($sourceCoin, ['btc', 'xmr', 'ltc'], true) || !in_array($targetCoin, ['btc', 'xmr', 'ltc'], true)) {
            throw new RequestException('Select two different supported currencies.');
        }

        $sourceAtomic = $this->ledger->coinToAtomic($sourceAmount, $sourceCoin);
        if (gmp_cmp($sourceAtomic, '0') <= 0) {
            throw new RequestException('Exchange amount must be greater than zero.');
        }

        $sourceDecimals = (int) config('coins.atomic_decimals.' . $sourceCoin);
        $sourceCoinAmount = (float) $this->atomicToCoin($sourceAtomic, $sourceDecimals);
        $rate = CoinConverter::conversion(strtoupper($sourceCoin), strtoupper($targetCoin), 1.0);
        $grossTargetAtomic = $this->ledger->coinToAtomic($sourceCoinAmount * $rate, $targetCoin);
        $feePermille = (int) round((float) config('marketplace.exchange_fee_percent', 0.3) * 10);
        $feeAtomic = gmp_strval(gmp_div_q(gmp_add(gmp_mul($grossTargetAtomic, $feePermille), 999), 1000));
        $targetAtomic = gmp_strval(gmp_sub($grossTargetAtomic, $feeAtomic));
        if (gmp_cmp($targetAtomic, '0') <= 0) {
            throw new RequestException('Exchange amount is too small after fees.');
        }

        return DB::transaction(function () use ($user, $sourceCoin, $targetCoin, $sourceAtomic, $grossTargetAtomic, $targetAtomic, $feeAtomic, $rate) {
            $sourceWallet = $this->ledger->walletFor($user->id, $sourceCoin);
            $targetWallet = $this->ledger->walletFor($user->id, $targetCoin);
            $sourceMarketWallet = $this->ledger->marketWalletFor($sourceCoin);
            $targetMarketWallet = $this->ledger->marketWalletFor($targetCoin);
            if (gmp_cmp($sourceWallet->available_atomic, $sourceAtomic) < 0) {
                throw new RequestException('Insufficient source-wallet balance.');
            }
            if (gmp_cmp($targetMarketWallet->available_atomic, $grossTargetAtomic) < 0) {
                throw new RequestException('The requested target currency currently has insufficient exchange liquidity.');
            }
            $exchange = WalletExchange::create([
                'user_id' => $user->id,
                'source_wallet_id' => $sourceWallet->id,
                'target_wallet_id' => $targetWallet->id,
                'source_coin' => $sourceCoin,
                'target_coin' => $targetCoin,
                'source_amount_atomic' => $sourceAtomic,
                'target_amount_atomic' => $targetAtomic,
                'fee_amount_atomic' => $feeAtomic,
                'rate' => $rate,
            ]);

            $this->ledger->book($sourceWallet, 'exchange_debit', '-' . $sourceAtomic, '0', 'wallet_exchange', $exchange->id, 'exchange-source-' . $exchange->id, $user->id);
            $this->ledger->book($sourceMarketWallet, 'exchange_credit', $sourceAtomic, '0', 'wallet_exchange', $exchange->id, 'exchange-reserve-source-' . $exchange->id, $user->id, null, true);
            $this->ledger->book($targetMarketWallet, 'exchange_debit', '-' . $grossTargetAtomic, '0', 'wallet_exchange', $exchange->id, 'exchange-reserve-target-' . $exchange->id, $user->id, null, true);
            $this->ledger->book($targetWallet, 'exchange_credit', $targetAtomic, '0', 'wallet_exchange', $exchange->id, 'exchange-target-' . $exchange->id, $user->id);
            $this->ledger->book($targetMarketWallet, 'exchange_fee', $feeAtomic, '0', 'wallet_exchange', $exchange->id, 'exchange-fee-' . $exchange->id, $user->id, null, true);
            return $exchange;
        });
    }

    private function atomicToCoin(string $amount, int $decimals): string
    {
        $value = str_pad($amount, $decimals + 1, '0', STR_PAD_LEFT);
        return substr($value, 0, -$decimals) . '.' . substr($value, -$decimals);
    }
}
