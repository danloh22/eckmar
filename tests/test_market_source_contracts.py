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
        persisted_query = "$offersToRemove = $product->offers()->where('min_quantity', $quantity);"
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

    def test_checkout_recovery_rejects_unrelated_database_errors(self):
        source = (
            self.root / "app/Http/Requests/Cart/MakePurchasesRequest.php"
        ).read_text(encoding="utf-8")
        start = source.index("protected function isDuplicateIdentityFailure(")
        end = source.index("protected function sameCheckoutIdentity(", start)
        classifier = source[start:end]

        self.assertIn("$sqlState === '23000' && $driverCode === '1062'", classifier)
        self.assertIn("$sqlState === '23505'", classifier)
        self.assertIn("$driverCode === '19'", classifier)
        self.assertIn("'unique constraint failed'", classifier)
        self.assertNotIn("strpos($message, 'duplicate entry')", classifier)
        self.assertNotIn("in_array($code, ['23000', '23505']", classifier)
        checkout_tests = (
            self.root / "tests/Unit/CheckoutTransactionRetryTest.php"
        ).read_text(encoding="utf-8")
        self.assertIn("'mysql foreign key'", checkout_tests)
        self.assertIn("'deadlock with duplicate-like text'", checkout_tests)
        self.assertIn("'misleading sqlite-like message'", checkout_tests)

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

if __name__ == "__main__":
    unittest.main()
