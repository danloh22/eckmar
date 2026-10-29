"""Static regression contracts for marketplace source that cannot run without legacy PHP."""

from pathlib import Path
import re
import unittest


class OfferPersistenceSourceContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.request_source = (
            cls.root / "app/Http/Requests/Product/NewOfferRequest.php"
        ).read_text(encoding="utf-8")
        cls.phpunit_source = (
            cls.root / "tests/Unit/ConvertedOfferPriceTest.php"
        ).read_text(encoding="utf-8")
        cls.controller_source = (
            cls.root / "app/Http/Controllers/VendorController.php"
        ).read_text(encoding="utf-8")

    def test_persisted_product_branch_uses_eloquent_exists_property(self):
        self.assertIn(
            "if ($editingProduct && $editingProduct->exists) {",
            self.request_source,
        )
        self.assertIsNone(
            re.search(r"\$editingProduct\s*->\s*exists\s*\(", self.request_source)
        )

    def test_phpunit_fixture_marks_product_as_persisted(self):
        self.assertIn("$product->exists = true;", self.phpunit_source)
        self.assertIsNone(
            re.search(r"shouldReceive\(['\"]exists['\"]\)", self.phpunit_source)
        )


    def test_offer_removal_uses_bound_product_model_state(self):
        start = self.controller_source.index("public function removeOffer")
        end = self.controller_source.index("public function addDeliveryShow", start)
        remove_offer_source = self.controller_source[start:end]

        self.assertIn("if ($product && $product->exists) {", remove_offer_source)
        self.assertIsNone(
            re.search(r"\$product\s*->\s*exists\s*\(", remove_offer_source)
        )

    def test_persisted_offer_rejects_duplicate_quantity_before_mutation(self):
        start = self.request_source.index(
            "if ($editingProduct && $editingProduct->exists) {"
        )
        end = self.request_source.index("// offer for new product", start)
        persisted_branch = self.request_source[start:end]

        duplicate_check = (
            "$lockedProduct->offers()->where('min_quantity', $minQuantity)->exists()"
        )
        self.assertIn(duplicate_check, persisted_branch)
        self.assertLess(
            persisted_branch.index(duplicate_check),
            persisted_branch.index("$newOffer = new Offer();"),
        )
        self.assertIn(
            "There is already offer like that! Please remove old offer to add new one.",
            persisted_branch,
        )


    def test_persisted_offer_mutations_share_product_first_lock_order(self):
        start = self.request_source.index(
            "if ($editingProduct && $editingProduct->exists) {"
        )
        end = self.request_source.index("// offer for new product", start)
        persisted_add = self.request_source[start:end]
        add_lock = (
            "Product::whereKey($editingProduct->getKey())\n"
            "                    ->lockForUpdate()"
        )
        self.assertIn("DB::transaction(function ()", persisted_add)
        self.assertIn(add_lock, persisted_add)
        self.assertLess(
            persisted_add.index(add_lock),
            persisted_add.index("$lockedProduct->offers()->where("),
        )
        self.assertIn("$newOffer -> product_id = $lockedProduct -> id;", persisted_add)

        remove_start = self.controller_source.index("public function removeOffer")
        remove_end = self.controller_source.index(
            "public function addDeliveryShow", remove_start
        )
        persisted_remove = self.controller_source[remove_start:remove_end]
        remove_lock = (
            "Product::whereKey($product->getKey())\n"
            "                        ->lockForUpdate()"
        )
        self.assertIn("DB::transaction(function ()", persisted_remove)
        self.assertIn(remove_lock, persisted_remove)
        self.assertLess(
            persisted_remove.index(remove_lock),
            persisted_remove.index("$lockedProduct->offers()->where("),
        )
        self.assertIn(
            "$lockedProduct->offers()->where('min_quantity', '!=', $quantity)",
            persisted_remove,
        )

        mysql_test = (
            self.root / "tests/Integration/MySqlWalletEscrowIntegrationTest.php"
        ).read_text(encoding="utf-8")
        offer_worker = (
            self.root / "tests/Integration/fixtures/mysql_offer_worker.php"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "testConcurrentPersistedOfferRequestsCreateOneActiveTier", mysql_test
        )
        self.assertIn("['created', 'duplicate']", mysql_test)
        self.assertIn("NewOfferRequest::create", offer_worker)
        self.assertIn("$request->persist(Product::findOrFail($productId));", offer_worker)

        removal_worker = (
            self.root / "tests/Integration/fixtures/mysql_offer_removal_worker.php"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "testConcurrentPersistedOfferRemovalsKeepOneActiveTier", mysql_test
        )
        self.assertIn("['kept', 'removed']", mysql_test)
        self.assertIn("$controller->removeOffer(", removal_worker)
        self.assertIn("Product::findOrFail($productId)", removal_worker)

    def test_persisted_offer_removal_validates_target_and_remaining_tier(self):
        start = self.controller_source.index("public function removeOffer")
        end = self.controller_source.index("public function addDeliveryShow", start)
        remove_offer_source = self.controller_source[start:end]

        target = "$offersToRemove = $lockedProduct->offers()->where('min_quantity', $quantity);"
        missing_guard = "if (!$offersToRemove->exists())"
        remaining_guard = (
            "if (!$lockedProduct->offers()->where('min_quantity', '!=', $quantity)->exists())"
        )
        mutation = "$offersToRemove->update(['deleted' => 1]);"

        for contract in (target, missing_guard, remaining_guard, mutation):
            self.assertIn(contract, remove_offer_source)
        self.assertLess(remove_offer_source.index(target), remove_offer_source.index(missing_guard))
        self.assertLess(
            remove_offer_source.index(missing_guard),
            remove_offer_source.index(remaining_guard),
        )
        self.assertLess(
            remove_offer_source.index(remaining_guard),
            remove_offer_source.index(mutation),
        )
        self.assertIn("There is no offer for this quantity!", remove_offer_source)
        self.assertIn("You must have at least one offer!", remove_offer_source)

    def test_offer_removal_validates_canonical_quantity_before_mutation(self):
        start = self.controller_source.index("public function removeOffer")
        end = self.controller_source.index("public function addDeliveryShow", start)
        remove_offer_source = self.controller_source[start:end]

        validation = "$validatedQuantity = filter_var($quantity, FILTER_VALIDATE_INT"
        assignment = "$quantity = $validatedQuantity;"
        persisted_query = "$offersToRemove = $lockedProduct->offers()->where('min_quantity', $quantity);"
        draft_mutation = "session() -> put('product_offers', $offers);"

        for contract in (validation, assignment, persisted_query, draft_mutation):
            self.assertIn(contract, remove_offer_source)
        self.assertIn("'min_range' => 1", remove_offer_source)
        self.assertIn("'max_range' => 2147483647", remove_offer_source)
        self.assertIn(
            "(string) $validatedQuantity !== (string) $quantity",
            remove_offer_source,
        )
        self.assertLess(remove_offer_source.index(validation), remove_offer_source.index(assignment))
        self.assertLess(remove_offer_source.index(assignment), remove_offer_source.index(persisted_query))
        self.assertLess(remove_offer_source.index(assignment), remove_offer_source.index(draft_mutation))

    def test_empty_checkout_is_rejected_before_transaction(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function persist(")
        persist_source = source[start:]

        items = "$items = Cart::getCart()->items();"
        empty_guard = "if (empty($items))"
        transaction = "DB::transaction("
        message = "Add at least one product before opening checkout."

        for contract in (items, empty_guard, transaction, message):
            self.assertIn(contract, persist_source)
        self.assertLess(persist_source.index(items), persist_source.index(empty_guard))
        self.assertLess(
            persist_source.index(empty_guard),
            persist_source.index(transaction),
        )

    def test_checkout_validates_stable_unique_identities_before_transaction(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function persist(")
        persist_source = source[start:]
        guard_call = "$this->assertStableCheckoutIdentities($items);"
        preflight_recovery = "$recovered = $this->recoverCommittedCheckout($items);"
        ledger_resolution = "$ledger = $ledger ?: app(WalletLedgerService::class);"
        transaction = "DB::transaction("

        self.assertIn(guard_call, persist_source)
        self.assertIn(preflight_recovery, persist_source)
        self.assertIn(ledger_resolution, persist_source)
        self.assertLess(persist_source.index(guard_call), persist_source.index(preflight_recovery))
        self.assertLess(persist_source.index(preflight_recovery), persist_source.index(ledger_resolution))
        self.assertLess(persist_source.index(guard_call), persist_source.index(ledger_resolution))
        self.assertLess(persist_source.index(ledger_resolution), persist_source.index(transaction))
        self.assertLess(persist_source.index(guard_call), persist_source.index(transaction))

        guard_start = source.index("protected function assertStableCheckoutIdentities(")
        guard_end = source.index("protected function recoverCommittedCheckout(", guard_start)
        guard = source[guard_start:guard_end]
        self.assertIn("$item->getAttribute('id')", guard)
        self.assertIn("!is_string($id)", guard)
        self.assertIn("trim($id) !== $id", guard)
        self.assertIn("isset($seen[$id])", guard)
        self.assertIn("$seen[$id] = true;", guard)
        self.assertIn("Checkout identity is missing or duplicated", guard)
        self.assertIn("$item->getAttribute('quantity')", guard)
        self.assertIn("!is_int($quantity)", guard)
        self.assertIn("$quantity < 1", guard)
        self.assertIn("Checkout quantity is invalid", guard)
        self.assertIn(
            "['buyer_id', 'vendor_id', 'offer_id', 'coin_name', 'type']",
            guard,
        )
        self.assertIn("$item->getAttribute($attribute)", guard)
        self.assertIn("$item->getAttribute('shipping_id')", guard)
        self.assertIn("$shippingId !== null", guard)
        self.assertIn("Checkout recovery identity is invalid", guard)

        checkout_tests = (
            self.root / "tests/Unit/CheckoutTransactionRetryTest.php"
        ).read_text(encoding="utf-8")
        self.assertIn("testDuplicatePurchaseIdsAreRejectedBeforeOpeningTransaction", checkout_tests)
        self.assertIn("testMissingOrNonStringPurchaseIdIsRejectedBeforeOpeningTransaction", checkout_tests)
        self.assertIn("testInvalidPurchaseQuantityIsRejectedBeforeOpeningTransaction", checkout_tests)
        self.assertIn(
            "testInvalidRecoveryIdentityIsRejectedBeforeLookupOrLedgerResolution",
            checkout_tests,
        )
        self.assertIn("$this->assertSame(0, $request->recoveryCalls);", checkout_tests)
        self.assertIn("invalidCheckoutQuantityValues", checkout_tests)
        self.assertIn("testSequentialRetryRecoversBeforeTransactionOrLedgerReservation", checkout_tests)
        self.assertIn("testInvalidCartDoesNotResolveLedgerService", checkout_tests)
        self.assertIn("testEmptyCartDoesNotResolveLedgerService", checkout_tests)
        self.assertIn("testLedgerResolutionFailureIsNormalizedBeforeTransaction", checkout_tests)
        self.assertIn("Ledger service must not be resolved for an invalid cart.", checkout_tests)
        self.assertIn("Ledger service must not be resolved for an empty cart.", checkout_tests)
        self.assertIn("testCheckoutRequestExceptionPreservesOriginalContext", checkout_tests)
        self.assertIn("$this->assertSame($expected, $exception);", checkout_tests)
        self.assertGreaterEqual(checkout_tests.count("DB::shouldReceive('transaction')->never()"), 8)

        self.assertIn("throw $requestException;", persist_source)
        self.assertNotIn(
            "throw new RequestException($requestException -> getMessage());",
            persist_source,
        )

    def test_profile_purchase_views_authorize_before_rendering(self):
        source = (
            self.root / "app/Http/Controllers/ProfileController.php"
        ).read_text(encoding="utf-8")
        contracts = (
            (
                "public function purchaseMessage(Purchase $purchase)",
                "public function purchase(Purchase $purchase)",
                "return view('profile.purchases.viewmessage'",
            ),
            (
                "public function purchase(Purchase $purchase)",
                "public function deliveredConfirm(Purchase $purchase)",
                "return view('profile.purchases.purchase'",
            ),
        )

        for start_marker, end_marker, view in contracts:
            with self.subTest(method=start_marker):
                start = source.index(start_marker)
                end = source.index(end_marker, start + len(start_marker))
                method_source = source[start:end]
                guard = "abort_unless($purchase->isAllowed(), 403);"

                self.assertIn(guard, method_source)
                self.assertIn(view, method_source)
                self.assertLess(method_source.index(guard), method_source.index(view))

    def test_profile_ticket_reads_and_replies_require_owner(self):
        source = (
            self.root / "app/Http/Controllers/ProfileController.php"
        ).read_text(encoding="utf-8")
        guard = (
            "abort_unless((string) $ticket->user_id === "
            "(string) auth()->id(), 403);"
        )

        tickets_start = source.index("public function tickets(Ticket $ticket = null)")
        tickets_end = source.index("public function newTicket(", tickets_start)
        tickets_source = source[tickets_start:tickets_end]
        self.assertIn(guard, tickets_source)
        self.assertIn("$ticket -> replies()", tickets_source)
        self.assertLess(tickets_source.index(guard), tickets_source.index("$ticket -> replies()"))

        reply_start = source.index("public function newTicketMessage(")
        reply_source = source[reply_start:]
        self.assertIn(guard, reply_source)
        self.assertIn("TicketReply::postReply($ticket", reply_source)
        self.assertLess(
            reply_source.index(guard),
            reply_source.index("TicketReply::postReply($ticket"),
        )

    def test_ticket_title_validation_matches_database_string_limit(self):
        request_source = (
            self.root / "app/Http/Requests/Profile/NewTicketRequest.php"
        ).read_text(encoding="utf-8")
        migration_source = (
            self.root
            / "database/migrations/2019_02_16_185709_create_tickets_table.php"
        ).read_text(encoding="utf-8")

        self.assertIn("'title' => 'required|string|max:255'", request_source)
        self.assertIn("$table -> string('title');", migration_source)

    def test_featured_removal_request_uses_products_permission_gate(self):
        source = (
            self.root
            / "app/Http/Requests/Admin/RemoveProductFromFeaturedReuqest.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function authorize()")
        end = source.index("public function rules()", start)
        authorize_source = source[start:end]

        self.assertIn("use Illuminate\\Support\\Facades\\Gate;", source)
        self.assertIn(
            "return Gate::allows('has-access', 'products');",
            authorize_source,
        )
        self.assertNotIn("isAdmin()", authorize_source)

    def test_dispute_notification_routing_uses_present_scalar_identifiers(self):
        listeners = (
            "ProductDisputedNotification.php",
            "ProductDisputeNewMessageNotification.php",
        )

        for listener in listeners:
            with self.subTest(listener=listener):
                source = (
                    self.root / "app/Listeners/Purchase" / listener
                ).read_text(encoding="utf-8")

                self.assertEqual(3, source.count("samePresentUserIdentifier("))
                self.assertIn("is_scalar($leftId) && is_scalar($rightId)", source)
                self.assertIn("(string) $leftId !== ''", source)
                self.assertIn("(string) $rightId !== ''", source)
                self.assertIn("(string) $leftId === (string) $rightId", source)
                self.assertNotRegex(source, r"\$event->initiator\s*==\s*\$event->")

    def test_admin_dispute_message_notifies_both_participants(self):
        listener = (
            self.root
            / "app/Listeners/Purchase/ProductDisputeNewMessageNotification.php"
        ).read_text(encoding="utf-8")

        self.assertIn("$initiatedByAdmin = !$initiatedByVendor && !$initiatedByBuyer && $event->initiator->isAdmin()", listener)
        self.assertIn("$initiatedByVendor || $initiatedByAdmin", listener)
        self.assertIn("$initiatedByBuyer || $initiatedByAdmin", listener)
        self.assertIn("testAdministratorDisputeMessageNotifiesBothParticipants", (
            self.root / "tests/Unit/ProductDisputeNewMessageNotificationTest.php"
        ).read_text(encoding="utf-8"))

    def test_dispute_message_delivery_identity_uses_persisted_message_uuid(self):
        dispute = (self.root / "app/Dispute.php").read_text(encoding="utf-8")
        controller = (
            self.root / "app/Http/Controllers/ProfileController.php"
        ).read_text(encoding="utf-8")
        event = (
            self.root / "app/Events/Purchase/ProductDisputeNewMessageSent.php"
        ).read_text(encoding="utf-8")
        listener = (
            self.root / "app/Listeners/Purchase/ProductDisputeNewMessageNotification.php"
        ).read_text(encoding="utf-8")
        unit_test = (
            self.root / "tests/Unit/ProductDisputeNewMessageNotificationTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("return $newMessage;", dispute)
        self.assertIn("$newMessage = $dispute -> newMessage", controller)
        self.assertIn("$newMessage->id", controller)
        self.assertIn("public $messageId;", event)
        self.assertIn("$messageId = null", event)
        self.assertIn("Uuid::uuid4()->toString()", event)
        self.assertIn("'dispute-message-buyer:' . $event->messageId", listener)
        self.assertIn("'dispute-message-vendor:' . $event->messageId", listener)
        self.assertIn("->notifyOnce(", listener)
        self.assertNotIn("->notifications()", listener)
        self.assertIn("testDifferentMessagesOnSameDisputeEachNotifyOnce", unit_test)

    def test_checkout_recovery_rejects_unrelated_database_errors(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        start = source.index("protected function isDuplicateIdentityFailure(")
        end = source.index("protected function sameCheckoutIdentity(", start)
        classifier = source[start:end]

        self.assertIn("$sqlState === '23000'", classifier)
        self.assertIn("$driverCode === '1062'", classifier)
        self.assertIn("for key 'purchases.primary'", classifier)
        self.assertIn("for key `purchases.primary`", classifier)
        self.assertIn("$sqlState === '23505'", classifier)
        self.assertIn("purchases_pkey", classifier)
        self.assertIn("$driverCode === '19'", classifier)
        self.assertIn("unique constraint failed: purchases.id", classifier)
        self.assertNotIn("strpos($message, 'duplicate entry')", classifier)
        self.assertNotIn("in_array($code, ['23000', '23505']", classifier)
        checkout_tests = (
            self.root / "tests/Unit/CheckoutTransactionRetryTest.php"
        ).read_text(encoding="utf-8")
        self.assertIn("'mysql unrelated unique key'", checkout_tests)
        self.assertIn("'postgres unrelated unique key'", checkout_tests)
        self.assertIn("'sqlite unrelated unique key'", checkout_tests)
        self.assertIn("'mysql foreign key'", checkout_tests)
        self.assertIn("'deadlock with duplicate-like text'", checkout_tests)
        self.assertIn("'misleading sqlite-like message'", checkout_tests)

    def test_checkout_ambiguous_commit_recovery_is_read_only_and_fail_closed(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        persist_start = source.index("public function persist(")
        persist_end = source.index("protected function clearCheckoutCart(", persist_start)
        persist = source[persist_start:persist_end]
        classifier_start = source.index("protected function isAmbiguousCommitFailure(")
        classifier_end = source.index("protected function sameCheckoutIdentity(", classifier_start)
        classifier = source[classifier_start:classifier_end]

        self.assertIn("!$this->isAmbiguousCommitFailure($e)", persist)
        self.assertIn("$recovered = $this->recoverCommittedCheckout($items);", persist)
        self.assertNotIn("DB::transaction(", classifier)
        self.assertIn("strpos($sqlState, '08') === 0", classifier)
        self.assertIn("$sqlState === 'HY000'", classifier)
        self.assertIn("['2006', '2013']", classifier)

        checkout_tests = (
            self.root / "tests/Unit/CheckoutTransactionRetryTest.php"
        ).read_text(encoding="utf-8")
        self.assertIn("testAmbiguousDisconnectRecoversCommittedPurchasesWithoutReplay", checkout_tests)
        self.assertIn("testAmbiguousDisconnectWithoutCommittedRowsFailsWithoutReplay", checkout_tests)
        self.assertIn("'mysql deadlock' => ['40001', 1213, false]", checkout_tests)
        self.assertIn("'mysql lock timeout' => ['HY000', 1205, false]", checkout_tests)

    def test_wallet_race_recovery_rejects_unrelated_database_errors(self):
        source = (
            self.root / "app/Services/WalletLedgerService.php"
        ).read_text(encoding="utf-8")
        start = source.index("public function walletFor(")
        end = source.index("public function reservePurchase(", start)
        wallet_for = source[start:end]

        guard = "if (!$this->isUniqueConstraintViolation($exception))"
        recovery = "$wallet = Wallet::where($identity)->lockForUpdate()->first();"
        self.assertIn(guard, wallet_for)
        self.assertIn(recovery, wallet_for)
        self.assertLess(wallet_for.index(guard), wallet_for.index(recovery))
        self.assertIn("$driverCode === '1062'", source)
        self.assertIn("'23505'", source)
        self.assertIn("$driverCode === '19'", source)
        self.assertIn("'unique constraint failed'", source)
        self.assertIn("'misleading sqlite-like message'", (
            self.root / "tests/Unit/WalletCreationRaceTest.php"
        ).read_text(encoding="utf-8"))

    def test_purchase_notification_replay_uses_deterministic_delivery_identity(self):
        trait = (self.root / "app/Traits/Notifiable.php").read_text(
            encoding="utf-8"
        )
        listener = (
            self.root / "app/Listeners/Purchase/ProductBoughtNotification.php"
        ).read_text(encoding="utf-8")
        mysql_test = (
            self.root / "tests/Integration/MySqlWalletEscrowIntegrationTest.php"
        ).read_text(encoding="utf-8")
        unit_test = (
            self.root / "tests/Unit/NotificationIdempotencyTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("Uuid::uuid5(", trait)
        self.assertIn("$this->notifications()->save($notification);", trait)
        self.assertIn("Notification::find($notificationId)", trait)
        self.assertIn("notifications_pkey", trait)
        self.assertIn("unique constraint failed: notifications.id", trait)
        self.assertIn("->notifyOnce(", listener)
        self.assertIn("'purchase-bought:' . $event->purchase->id", listener)
        self.assertIn("testConcurrentNotificationReplayPersistsOneDelivery", mysql_test)
        self.assertIn("mysql unrelated unique key", unit_test)

    def test_sent_and_delivered_notifications_use_atomic_delivery_identity(self):
        sent = (
            self.root / "app/Listeners/Purchase/ProductSentNotification.php"
        ).read_text(encoding="utf-8")
        delivered = (
            self.root / "app/Listeners/Purchase/ProductDeliveredNotification.php"
        ).read_text(encoding="utf-8")

        self.assertIn("'purchase-sent:' . $event->purchase->id", sent)
        self.assertIn("$event->buyer->notifyOnce(", sent)
        self.assertNotIn("->notifications()", sent)

        self.assertIn("'purchase-delivered:' . $event->purchase->id", delivered)
        self.assertIn("$event->vendor->user->notifyOnce(", delivered)
        self.assertNotIn("->notifications()", delivered)

    def test_canceled_notifications_use_atomic_per_recipient_delivery_identity(self):
        listener = (
            self.root / "app/Listeners/Purchase/PurchaseCanceledNotification.php"
        ).read_text(encoding="utf-8")

        self.assertIn("'purchase-canceled-vendor:' . $event->purchase->id", listener)
        self.assertIn("$event->purchase->vendor->user->notifyOnce(", listener)
        self.assertIn("'purchase-canceled-buyer:' . $event->purchase->id", listener)
        self.assertIn("$event->purchase->buyer->notifyOnce(", listener)
        self.assertNotIn("->notifications()", listener)


    def test_disputed_notifications_use_atomic_per_recipient_delivery_identity(self):
        listener = (
            self.root / "app/Listeners/Purchase/ProductDisputedNotification.php"
        ).read_text(encoding="utf-8")
        unit_test = (
            self.root / "tests/Unit/ProductDisputedNotificationTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn(
            "'dispute-opened-buyer:' . $event->purchase->id", listener
        )
        self.assertIn("$event->buyer->notifyOnce(", listener)
        self.assertIn(
            "'dispute-opened-vendor:' . $event->purchase->id", listener
        )
        self.assertIn("$event->vendor->user->notifyOnce(", listener)
        self.assertNotIn("->notifications()", listener)
        self.assertIn("testBuyerAndVendorUseSeparateDeliveryIdentities", unit_test)
        self.assertIn(
            "testRecoveryReplayAfterPostPersistFailureDoesNotDuplicateProductDisputedNotification",
            unit_test,
        )

class TicketClosedNotificationSourceContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.listener = (
            cls.root / "app/Listeners/SupportEventSubscriber.php"
        ).read_text(encoding="utf-8")
        cls.phpunit = (
            cls.root / "tests/Unit/SupportTicketClosedNotificationTest.php"
        ).read_text(encoding="utf-8")

    def test_ticket_close_uses_atomic_stable_delivery_identity(self):
        start = self.listener.index("public function onTicketClosed")
        end = self.listener.index("public function subscribe", start)
        method = self.listener[start:end]

        self.assertIn("$event->ticket->user->notifyOnce(", method)
        self.assertIn("'ticket-closed:' . $event->ticket->id", method)
        self.assertNotIn("->notifications()", method)
        self.assertIn("testRecoveryReplayDoesNotSendTicketClosedNotificationTwice", self.phpunit)
        self.assertIn("testDifferentTicketsUseDifferentDeliveryIdentities", self.phpunit)
        self.assertIn("testRecoveryReplayAfterPostPersistFailure", self.phpunit)
        self.assertIn("): bool", self.phpunit)


class TicketReplyNotificationSourceContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.listener = (
            cls.root / "app/Listeners/SupportEventSubscriber.php"
        ).read_text(encoding="utf-8")
        cls.phpunit = (
            cls.root / "tests/Unit/SupportTicketReplyNotificationTest.php"
        ).read_text(encoding="utf-8")

    def test_ticket_reply_uses_atomic_per_reply_delivery_identity(self):
        start = self.listener.index("public function onNewTicketMessage")
        end = self.listener.index("public function onTicketClosed", start)
        method = self.listener[start:end]

        self.assertIn("$event->ticketReply->ticket->user->notifyOnce(", method)
        self.assertIn("'ticket-reply:' . $event->ticketReply->id", method)
        self.assertNotIn("->notify(", method)
        self.assertIn("testRecoveryReplayDoesNotSendTicketReplyNotificationTwice", self.phpunit)
        self.assertIn("testDifferentRepliesOnSameTicketUseDifferentDeliveryIdentities", self.phpunit)
        self.assertIn("testRecoveryReplayAfterPostPersistFailure", self.phpunit)
        self.assertIn("): bool", self.phpunit)


class MessageNotificationSourceContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.listener = (
            cls.root / "app/Listeners/SendMessageNotifications.php"
        ).read_text(encoding="utf-8")
        cls.phpunit = (
            cls.root / "tests/Unit/SendMessageNotificationsTest.php"
        ).read_text(encoding="utf-8")

    def test_message_notification_uses_atomic_per_message_delivery_identity(self):
        self.assertIn("$event->message->getReceiver()->notifyOnce(", self.listener)
        self.assertIn(
            "'message-received:' . $event->message->id", self.listener
        )
        self.assertNotIn("->notify(", self.listener)
        self.assertNotIn("->notifications()", self.listener)
        self.assertIn(
            "testRecoveryReplayDoesNotSendMessageNotificationTwice", self.phpunit
        )
        self.assertIn(
            "testDifferentMessagesInSameConversationUseDifferentDeliveryIdentities",
            self.phpunit,
        )
        self.assertIn(
            "testRecoveryReplayAfterPostPersistFailure", self.phpunit
        )
        self.assertIn("): bool", self.phpunit)


    def test_experience_updates_are_atomic_and_fail_closed(self):
        source = (
            self.root / "app/Traits/Experience.php"
        ).read_text(encoding="utf-8")

        self.assertIn("$this->increment('experience', $value) !== 1", source)
        self.assertIn("$this->decrement('experience', $value) !== 1", source)
        self.assertEqual(2, source.count("$this->refresh();"))
        self.assertNotIn("$this->experience += $value", source)
        self.assertNotIn("$this->experience -= $value", source)
        self.assertNotIn("$this->save();", source)

        unit_test = (
            self.root / "tests/Unit/ExperienceAtomicUpdateTest.php"
        ).read_text(encoding="utf-8")
        self.assertIn("testGrantDelegatesToAtomicIncrementAndRefreshes", unit_test)
        self.assertIn("testTakeDelegatesToAtomicDecrementAndRefreshes", unit_test)
        self.assertIn("testFailedAtomicUpdateDoesNotRefreshStaleState", unit_test)

    def test_mysql_experience_regression_uses_two_stale_workers(self):
        mysql_test = (
            self.root / "tests/Integration/MySqlWalletEscrowIntegrationTest.php"
        ).read_text(encoding="utf-8")
        worker = (
            self.root / "tests/Integration/fixtures/mysql_experience_worker.php"
        ).read_text(encoding="utf-8")

        self.assertIn("testConcurrentExperienceGrantsPreserveBothDeltas", mysql_test)
        self.assertIn("testConcurrentExperienceGrantAndTakePreserveBothDeltas", mysql_test)
        self.assertIn("testExperienceWorkerRejectsUnknownOperationBeforeBarrier", mysql_test)
        self.assertIn("testExperienceWorkerRejectsNonCanonicalAmountsBeforeBarrier", mysql_test)
        self.assertEqual(4, mysql_test.count("$workers[] = $this->startExperienceWorker($vendorId"))
        self.assertIn("118,", mysql_test)
        self.assertIn("104,", mysql_test)
        self.assertIn("$vendor = Vendor::findOrFail($vendorId);", worker)
        self.assertLess(worker.index("Vendor::findOrFail"), worker.index("touch($readyFile)"))
        self.assertIn("in_array($operation, ['grant', 'take'], true)", worker)
        self.assertIn("FILTER_VALIDATE_INT", worker)
        self.assertIn("'min_range' => 1", worker)
        self.assertIn("'max_range' => 2147483647", worker)
        self.assertIn("(string) $amount !== $amountInput", worker)
        self.assertIn("exit(5);", worker)
        self.assertIn("exit(6);", worker)
        self.assertIn("$vendor->grantExperience($amount);", worker)
        self.assertIn("$vendor->takeExperience($amount);", worker)
        self.assertNotIn("wallet", worker.lower())

    def test_checkout_locks_product_before_reloading_active_offer(self):
        source = (
            self.root / "app/Traits/Purchasable.php"
        ).read_text(encoding="utf-8")
        start = source.index("protected function lockActiveOfferAndProduct()")
        end = source.index("private function markingAsSent()", start)
        resolver = source[start:end]

        reference = "$offerReference = Offer::where('id', $this->offer_id)"
        transaction_guard = "DB::connection()->transactionLevel() < 1"
        product_lock = "$product = Product::where('id', $offerReference->product_id)"
        active_offer = "$offer = Offer::where('id', $this->offer_id)"
        deleted_guard = "->where('deleted', '=', 0)"

        for contract in (transaction_guard, reference, product_lock, active_offer, deleted_guard):
            self.assertIn(contract, resolver)
        self.assertLess(resolver.index(transaction_guard), resolver.index(reference))
        self.assertIn(
            "Checkout offer resolution requires an active database transaction.",
            resolver,
        )
        self.assertNotIn("$offerReference = Offer::where('id', $this->offer_id)->lockForUpdate()", resolver)
        self.assertLess(resolver.index(reference), resolver.index(product_lock))
        self.assertLess(resolver.index(product_lock), resolver.index(active_offer))
        self.assertLess(resolver.index(active_offer), resolver.index(deleted_guard))
        self.assertIn("Selected offer is no longer available.", resolver)

        purchased_start = source.index("public function purchased()")
        resolver_start = source.index("protected function lockActiveOfferAndProduct()")
        purchased = source[purchased_start:resolver_start]
        self.assertIn("list($offer, $product) = $this->lockActiveOfferAndProduct();", purchased)
        self.assertLess(
            purchased.index("lockActiveOfferAndProduct"),
            purchased.index("app() -> makeWith"),
        )

    def test_checkout_offer_race_uses_production_resolver_without_payment(self):
        worker = (
            self.root / "tests/Integration/fixtures/mysql_checkout_offer_worker.php"
        ).read_text(encoding="utf-8")
        removal_worker = (
            self.root / "tests/Integration/fixtures/mysql_offer_removal_hold_worker.php"
        ).read_text(encoding="utf-8")
        mysql_test = (
            self.root / "tests/Integration/MySqlWalletEscrowIntegrationTest.php"
        ).read_text(encoding="utf-8")

        self.assertIn("use \\App\\Traits\\Purchasable;", worker)
        self.assertIn("return $this->lockActiveOfferAndProduct();", worker)
        self.assertIn("DB::transaction", worker)
        self.assertIn("ECKMAR_DISPOSABLE_MYSQL", worker)
        self.assertNotIn("Wallet", worker)
        self.assertNotIn("Payment", worker)
        self.assertIn("DB::listen", removal_worker)
        self.assertIn("QueryExecuted", removal_worker)
        self.assertIn("update `offers`", removal_worker)
        self.assertIn("$controller->removeOffer", removal_worker)
        self.assertIn("$mutationHeldFile", removal_worker)
        self.assertIn("$releaseFile", removal_worker)
        self.assertNotIn("Wallet", removal_worker)
        self.assertNotIn("Payment", removal_worker)
        self.assertIn("testCheckoutOfferResolutionAndRemovalUseOneBoundedLockOrder", mysql_test)
        self.assertIn("testCheckoutOfferResolverRequiresActiveTransaction", mysql_test)
        self.assertIn("startCheckoutOfferWorker", mysql_test)
        self.assertIn("startHeldOfferRemovalWorker", mysql_test)
        self.assertIn("mysql_offer_removal_hold_worker.php", mysql_test)
        self.assertIn("assertFileNotExists($checkoutDone", mysql_test)
        self.assertIn("$this->assertSame('rejected', $checkout['status']);", mysql_test)
        self.assertIn("$this->assertSame('removed', $removal['status']);", mysql_test)

if __name__ == "__main__":
    unittest.main()

