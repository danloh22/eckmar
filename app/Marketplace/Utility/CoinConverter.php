<?php


namespace App\Marketplace\Utility;
use Illuminate\Support\Facades\Cache;


class CoinConverter
{

    /**
     * Converting from one Symbol to another in amount
     *
     * @param string $fromSym
     * @param string $toSym
     * @param float $amount
     * @return float|int
     */
    public static function conversion(string $fromSym, string $toSym, float $amount)
    {

        $coinPrice = Cache::remember($fromSym . '_' . $toSym . '_price',
            config('coins.caching_price_interval'),
            function() use($fromSym, $toSym){
                // get from symb price
                $url = "https://min-api.cryptocompare.com/data/price?fsym=$fromSym&tsyms=$toSym";
                $response = @file_get_contents($url);
                $json = $response === false ? null : json_decode($response, true);
                if (!is_array($json) || !isset($json[$toSym]) || !is_numeric($json[$toSym]) || $json[$toSym] <= 0) {
                    throw new \RuntimeException('The current exchange rate is unavailable.');
                }
                $coin_price = $json[$toSym];

                return $coin_price;
            }
        );
        // calculate bitcoins and store
        if (!is_numeric($coinPrice) || $coinPrice <= 0) {
            throw new \RuntimeException('The cached exchange rate is invalid.');
        }
        return $amount * $coinPrice;
    }

}
