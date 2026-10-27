<?php


namespace App\Marketplace\Utility;
use Illuminate\Support\Facades\Cache;


class CoinConverter
{
    public static function marketRates(array $coins = ['BTC', 'XMR', 'LTC'], array $currencies = ['USD', 'EUR', 'GBP', 'AUD', 'CAD']): array
    {
        $cacheKey = 'market_footer_rates_' . md5(implode(',', $coins) . '|' . implode(',', $currencies));

        try {
            return Cache::remember($cacheKey, config('coins.rates.cache_minutes', 1), function () use ($coins, $currencies) {
                $url = config('coins.rates.url') . '?' . http_build_query([
                    'fsyms' => implode(',', $coins),
                    'tsyms' => implode(',', $currencies),
                ]);
                $headers = ['Accept: application/json'];
                if (config('coins.rates.api_key')) {
                    $headers[] = 'Authorization: Apikey ' . config('coins.rates.api_key');
                }
                $context = stream_context_create(['http' => [
                    'timeout' => 5,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers),
                ]]);
                $response = @file_get_contents($url, false, $context);
                $rates = $response === false ? null : json_decode($response, true);
                foreach ($coins as $coin) {
                    foreach ($currencies as $currency) {
                        if (!isset($rates[$coin][$currency]) || !is_numeric($rates[$coin][$currency]) || $rates[$coin][$currency] <= 0) {
                            throw new \RuntimeException('The current market rates are unavailable.');
                        }
                    }
                }
                $payload = ['rates' => $rates, 'updated_at' => gmdate('c')];
                Cache::put($cacheKey . '_last_good', $payload, 1440);
                return $payload;
            });
        } catch (\Exception $exception) {
            $lastGood = Cache::get($cacheKey . '_last_good');
            if ($lastGood) {
                $lastGood['stale'] = true;
                return $lastGood;
            }
            throw $exception;
        }
    }

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
