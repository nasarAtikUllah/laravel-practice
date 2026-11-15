Week 4: Order Management and Payment Flow
Analysis, design, business logic and implementation roadmap for Week 4 of the Performance Improvement Plan. Prepared for review and approval before any code is written.
1. What Week 4 asks for
The PIP's Week 4 is Order Management and Payment Flow, end-to-end, with automated tests. The deliverables are feature completion reports, UAT checklist reports and delivery review notes. The "Self-Review Against UAT Checklist" focus area means the analysis, tests and checklist matter as much as the code.
The Week 2 modules were built with this week in mind. Both services already take a reference_type and reference_id, and nothing calls them yet:

Week 4 is mostly orchestration: an order is the "reference" these services were waiting for. The risk is in getting the sequence and the failure cases right, not in building more CRUD.
Note: composer.json shows Laravel ^13.17. The earlier API review doc says Laravel 12 and needs correcting.
2. Business logic
Problem. Today a customer can browse and see prices, but can't buy. The business needs a record of what was bought, at what price, by whom, how it was paid, and where it is in fulfilment, without overselling stock or misusing coupons.
Users. Customers place and cancel their own orders. Staff and admins confirm, ship and deliver them. The payment gateway talks to the system through a webhook.
Example. A customer buys 2 × Pan (1,200) and 1 × Cup (300) with coupon SAVE10 (10%):
Subtotal is 2,700, discount is 270, total is 2,430.
The discount is split across lines: Pan 240, Cup 30. The coupon service already calculates this split, and it is needed later for refunds.
Two payment paths.
Cash on delivery: stock is sold and the coupon is redeemed immediately. The order waits as awaiting_confirmation. If staff don't confirm within 24 hours, it auto-cancels and stock is restored.
Pay now: stock and coupon are only held (15 minutes). If the webhook reports success, the holds become a sale. If not, the scheduler releases them.
Business rules.
Prices always come from the database, never from the client.
Only published products with an active brand and collection can be bought.
A customer sees only their own orders.
Status changes follow fixed rules, and every change is logged.
Item names and prices are copied into the order, so later catalog edits or deletions don't change history.
3. System design
Order status flow (proposed)

shipped, delivered and cancelled can't be cancelled or reversed in Week 4. Returns belong to a separate flow (restoreFromReturn already exists). For COD, the payment is marked collected at delivered.
New tables
Money is decimal(12,2), matching the existing columns.
orders: order number, user, status, payment method, subtotal, discount, total, coupon code snapshot, shipping fields, timestamps, cancel reason, expires_at, and an idempotency key (unique per user).
order_items: order, variant, plus a snapshot of product name, variant title, SKU, unit price, quantity, line total and line discount.
payments: order, provider, amount, status, unique provider transaction ID, raw payload, paid_at, failure reason.
order_status_histories: order, from, to, changed by, note. This is an audit trail like the stock ledger.
Code components
Models: Order, OrderItem, Payment, OrderStatusHistory, with factories.
Enums: OrderStatus (with an allowedNext() method, so transition rules live in one place), PaymentMethod, PaymentStatus.
CartPricer: the cart-pricing code is currently private inside CouponService. Extracting it lets orders and coupons share one implementation instead of duplicating it. The 633 existing tests protect that refactor.
OrderService: place, cancel, confirm, ship and deliver. It is the only place that coordinates inventory, coupons and order state.
PaymentService plus a PaymentGateway interface: with a fake gateway for tests and demos, so a real provider can be swapped in later.
OrderPolicy and a manage-orders Gate: the Gate follows the inventory and coupon pattern.
Form requests, resources, exceptions for the usual API layer, and two scheduled commands (see task 9).
Endpoints (paths are a proposal)

Checkout data flow
All steps run inside one DB::transaction:
Validate the request, then price the cart from the database.
Create the order and its items.
Lock and process variants in a fixed order (by ID), so concurrent checkouts can't deadlock. COD uses sell, and online uses reserve.
Apply the coupon (redeem or reserve). The discount saved on the order must equal the redemption's discount.
If any step throws (for example InsufficientStockException), the whole transaction rolls back and nothing is half-saved.
Payment webhook flow
Verify the HMAC signature with hash_equals.
Find the payment by transaction ID. If it is already processed, return 200 and do nothing (retries are normal).
Check that the amount matches the order total.
In a transaction with row locks: commit the stock reservations, commit the coupon use, mark the payment succeeded, and move the order to confirmed.
Late payment is the trickiest case: the webhook arrives after the 15-minute hold expired. reserve() can re-activate an expired hold if stock is still available, so that is tried first. If stock is gone, the payment is recorded as succeeded and the order is flagged as needing a refund, so a customer is never left charged with no record.
4. Edge cases to cover with tests
Two customers buying the last unit at the same moment
A double-clicked checkout, covered by the idempotency key
A cancel racing a payment webhook
A webhook delivered twice, with a wrong amount, or with a bad signature
A coupon whose usage limit was reached while payment was pending
A variant deleted or unpublished after the order was placed
Another user's order ID in the URL (a customer must get 404 or 403)
inventory:reconcile and coupons:reconcile still report no problems after every flow
5. Implementation roadmap

Suggested pace over five days: day 1 covers tasks 0 to 2, day 2 covers 3 and 4, day 3 covers 5 and 6, day 4 covers 7 and 8, and day 5 covers 9 and 10.
For each task: explain the design first, then write the code, then run the tests. Every task ends with a verification step, and every feature gets Pest tests plus UAT cases.
6. Questions to settle before coding
The PIP asks for these to be raised before development, not during QA. The default answer is given for each, so they can be approved quickly or changed.

Next step: approve the defaults (or change any numbers above), and implementation starts with tasks 0 to 2.