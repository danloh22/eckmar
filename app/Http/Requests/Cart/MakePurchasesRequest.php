<?php

namespace App\Http\Requests\Cart;

use App\Exceptions\RequestException;
use App\Marketplace\Cart;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\WalletLedgerService;
use Illuminate\Validation\Rule;
use App\Events\Purchase\NewPurchase;

class MakePurchasesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
//            'cointype' => ['required', Rule::in(array_keys(config('coins.coin_list')))],
        ];
    }

    public function persist(WalletLedgerService $ledger = null)
    {
        $ledger = $ledger ?: app(WalletLedgerService::class);
        $purchases = [];
        try{
            $items = Cart::getCart()->items();
            ksort($items);
            // Keep session models untouched on rollback and later manual retries.
            // Clone preserves the cart purchase ID; fresh relations prevent shared
            // cached model instances from being changed by checkout processing.
            $items = array_map(function ($item) {
                $purchase = clone $item;
                $purchase->setRelations([]);
                return $purchase;
            }, $items);
            // Do not automatically retry this closure: rollback does not reset the
            // captured Eloquent models' exists/attributes or the purchases list.
            // Reusing them after a deadlock could update an insert that rolled back.
            DB::transaction(function () use ($items, $ledger, &$purchases) {
                foreach ($items as $item) {
                    $item->purchased();
                    $item->save();
                    $ledger->reservePurchase($item);
                    $purchases[] = $item;
                }
            });
            // Clear cart after commiting
            Cart::getCart() -> clearCart();
        }
        catch(RequestException $requestException){
            Log::error($requestException -> getMessage());
            throw new RequestException($requestException -> getMessage());
        }
        catch (\Exception $e){
            Log::error($e -> getMessage());
            throw new RequestException('Error happened! Try again later!');
        }

        foreach ($purchases as $purchase) {
            try {
                event(new NewPurchase($purchase));
            } catch (\Exception $exception) {
                report($exception);
            }
        }
    }
}
