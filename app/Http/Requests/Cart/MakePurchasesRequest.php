<?php

namespace App\Http\Requests\Cart;

use App\Exceptions\RequestException;
use App\Marketplace\Cart;
use App\Purchase;
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
        $items = [];
        $transactionCompleted = false;
        try{
            $items = Cart::getCart()->items();
            if (empty($items)) {
                throw new RequestException('Add at least one product before opening checkout.');
            }
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
            $transactionCompleted = true;
            // Clear cart after commiting
            $this->clearCheckoutCart();
        }
        catch(RequestException $requestException){
            Log::error($requestException -> getMessage());
            throw new RequestException($requestException -> getMessage());
        }
        catch (\Exception $e){
            Log::error($e -> getMessage());

            // Recover only after the transaction returned successfully, or
            // after a duplicate-key signal that can represent a prior request
            // committing the same stable purchase UUIDs. Deadlocks and other
            // definite pre-commit failures retain the normal error path.
            if (!$transactionCompleted
                && !$this->isDuplicateIdentityFailure($e)
                && !$this->isAmbiguousCommitFailure($e)
            ) {
                throw new RequestException('Error happened! Try again later!');
            }

            try {
                $recovered = $this->recoverCommittedCheckout($items);
            } catch (RequestException $recoveryException) {
                Log::error($recoveryException->getMessage());
                throw $recoveryException;
            } catch (\Exception $recoveryException) {
                Log::error($recoveryException->getMessage());
                throw new RequestException('Error happened! Try again later!');
            }

            if ($recovered === null) {
                throw new RequestException('Error happened! Try again later!');
            }

            $purchases = $recovered;
            $this->clearCheckoutCart();
        }

        foreach ($purchases as $purchase) {
            try {
                event(new NewPurchase($purchase));
            } catch (\Exception $exception) {
                report($exception);
            }
        }
    }

    /**
     * Clear the request cart through one overridable boundary.
     *
     * Session stores can fail immediately after the database commit. Keeping
     * this boundary explicit lets recovery re-check the committed purchase IDs
     * without rerunning reservations or inventory mutations.
     */
    protected function clearCheckoutCart()
    {
        Cart::getCart()->clearCart();
    }

    /**
     * Resolve an ambiguous checkout result from the stable purchase UUIDs.
     *
     * No row is a normal pre-commit failure. A partial or conflicting match is
     * unsafe and must not be retried automatically because that could reserve
     * funds or decrement inventory twice.
     *
     * @param array $items
     * @return array|null
     * @throws RequestException
     */
    protected function recoverCommittedCheckout(array $items)
    {
        if (empty($items)) {
            return null;
        }

        $committed = [];
        foreach ($items as $item) {
            $purchase = $this->findCommittedPurchase($item->id);
            if ($purchase === null) {
                continue;
            }

            if (!$this->sameCheckoutIdentity($item, $purchase)) {
                throw new RequestException('Checkout status is inconsistent. Please contact support.');
            }

            $committed[] = $purchase;
        }

        if (empty($committed)) {
            return null;
        }

        if (count($committed) !== count($items)) {
            throw new RequestException('Checkout status is inconsistent. Please contact support.');
        }

        return $committed;
    }

    protected function findCommittedPurchase($id)
    {
        return Purchase::find($id);
    }

    protected function isDuplicateIdentityFailure(\Exception $exception)
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $errorInfo = property_exists($current, 'errorInfo') && is_array($current->errorInfo)
                ? $current->errorInfo
                : [];
            $sqlState = isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $current->getCode();
            $driverCode = isset($errorInfo[1]) ? (string) $errorInfo[1] : '';
            $message = strtolower($current->getMessage());

            if (($sqlState === '23000' && $driverCode === '1062')
                || $sqlState === '23505'
                || ($sqlState === '23000'
                    && $driverCode === '19'
                    && strpos($message, 'unique constraint failed') !== false)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the database may have disconnected while COMMIT was in flight.
     *
     * Recovery remains read-only: it never replays the transaction. A disconnect can
     * also happen before commit, in which case no matching rows are found and checkout
     * fails normally. Partial or conflicting rows still fail closed.
     */
    protected function isAmbiguousCommitFailure(\Exception $exception)
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $errorInfo = property_exists($current, 'errorInfo') && is_array($current->errorInfo)
                ? $current->errorInfo
                : [];
            $sqlState = isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $current->getCode();
            $driverCode = isset($errorInfo[1]) ? (string) $errorInfo[1] : '';

            if (strpos($sqlState, '08') === 0
                || ($sqlState === 'HY000' && in_array($driverCode, ['2006', '2013'], true))
            ) {
                return true;
            }
        }

        return false;
    }

    protected function sameCheckoutIdentity(Purchase $requested, Purchase $committed)
    {
        foreach (['buyer_id', 'vendor_id', 'offer_id', 'shipping_id', 'coin_name', 'type'] as $attribute) {
            if ((string) $requested->getAttribute($attribute) !== (string) $committed->getAttribute($attribute)) {
                return false;
            }
        }

        return (int) $requested->getAttribute('quantity') === (int) $committed->getAttribute('quantity');
    }
}
