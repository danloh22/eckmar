<?php


namespace App\Marketplace\Payment;


use App\Purchase;
use App\Services\WalletLedgerService;
use App\User;
use App\Marketplace\Utility\FeeCalculator;
use Illuminate\Support\Facades\Log;

class Escrow extends Payment
{

    /**
     * Procedure when the purchase is created
     *
     * @throws \Exception
     */
    function purchased()
    {
        $this->purchase->address = 'wallet:' . $this->purchase->buyer_id;
    }

    /**
     * Empty procedure for sent
     */
    function sent()
    {
    }

    /**
     * Release funds to the vendor
     */
    function delivered()
    {
        if (!$this->isInternalWalletEscrow()) {
            $this->sendLegacyEscrow($this->purchase->vendor->user->coinAddress($this->coinLabel())->address);
            return;
        }
        app(WalletLedgerService::class)->releasePurchase($this->purchase, $this->purchase->vendor->user);
    }

    /**
     * Resolve by sending funds to passed address
     *
     * @param array $parameters
     */
    function resolved(array $parameters)
    {
        if (!array_key_exists('winner_id', $parameters))
            throw new \Exception('There is no dispute winner defined!');

        $winner = User::findOrFail($parameters['winner_id']);
        if (!$this->isInternalWalletEscrow()) {
            $this->sendLegacyEscrow($winner->coinAddress($this->coinLabel())->address);
            return;
        }
        app(WalletLedgerService::class)->releasePurchase($this->purchase, $winner, 'dispute_release');

    }

    /**
     * Returns balance of the purchase's address
     *
     * @return float
     * @throws \Exception
     */
    function balance(): float
    {
        if (!$this->isInternalWalletEscrow()) {
            return $this->coin->getBalance(['account' => $this->purchase->id, 'address' => $this->purchase->address]);
        }
        return optional($this->purchase->walletEscrowHold)->status === 'active' ? (float) $this->purchase->to_pay : 0.0;
    }

    /**
     * Convert to amount of coin
     *
     * @param $usd
     * @return float
     */
    function usdToCoin($usd): float
    {
        return $this -> coin ->usdToCoin($usd);
    }

    /**
     * Return Coin's label
     *
     * @return string
     */
    function coinLabel(): string
    {
        return $this -> coin -> coinLabel();
    }

    /**
     * Procedure when the purchase is canceled
     *
     * @throws \Exception
     */
    public function canceled()
    {
        if (!$this->isInternalWalletEscrow()) {
            if (($balance = $this->balance()) > 0) {
                $this->sendLegacyEscrow($this->purchase->buyer->coinAddress($this->coinLabel())->address, $balance);
            }
            return;
        }
        app(WalletLedgerService::class)->refundPurchase($this->purchase);

    }

    private function isInternalWalletEscrow(): bool
    {
        return strpos((string) $this->purchase->address, 'wallet:') === 0;
    }

    private function sendLegacyEscrow(string $recipient, $amount = null)
    {
        $calculator = new FeeCalculator($amount === null ? $this->purchase->to_pay : $amount);
        $receivers = [$recipient => $calculator->getBase()];
        $marketAddresses = config('coins.market_addresses.' . $this->coinLabel());
        if (!empty($marketAddresses)) {
            $receivers[$marketAddresses[array_rand($marketAddresses)]] = $calculator->getFee();
        }
        $this->coin->sendToMany($receivers);
    }


}
