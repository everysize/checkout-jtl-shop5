# Changelog

## 1.1.0

* Import report: before an order is written the plugin reads the shop's
  current gross price and the stock of each ordered article. A deviation is
  a warning in the log (the run stays successful) and everysize is told once
  per item. The order is always imported at the price paid at everysize; a
  failed report never stops the import and is not repeated
* Refunds made safe (rules version 2): a refund call waits up to 90 s for PayPal. The call is stored
  (key, amount, time; one per order) before it is sent. Only a proven refusal (422 PAYPAL_REFUND_FAILED or
  REFUND_AMOUNT_INVALID, 400 ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS) ends it; every other answer
  (no answer, 3xx, 401, 403, 404, 429, 5xx, ...) keeps it open as "result unclear", a failed token request
  before the POST changes nothing. While it is open nothing else is refunded, not even automatically. The
  repeat is an explicit action after 150 s and reads the PayPal list first (a matching unknown refund closes
  the entry without sending); "mark as cleared" ends it without sending. The form sends the refund sum it saw
  (stale tab: reload) and refunds only when the list is readable; the confirmation names the amount. The entry
  is reserved first, then the list is read and its refundIds are stored in it (an old foreign refund is never
  adopted); a 2xx without refundId is unclear; a refused repeat keeps the entry open; "mark as cleared" is a
  conditional delete with a protocol entry
* A tracking code entered by hand, a delivery note alone, a failed shipping report (now remembered and
  made up) and the order's own tracking number count as shipped everywhere: shipping report, cancellation as
  return (RETOUR) instead of decline, and no automatic refund
* A cancellation of an order that was already revoked at everysize (the partner revoked) is reported once in the protocol - there is no automatic refund then. Submitting a refused form again says "refused, no money moved" instead of "already triggered"
* Automatic refund only when nothing was shipped and everysize lists no other refund (COMPLETED or PENDING,
  also one made directly in PayPal); an unreadable list skips the run. It uses the same stored entry as the
  form and sends at most once (an open entry is only checked against the list, never sent again by a run; the
  setting and the conditions are checked again right before the send); only a proven refusal is final, old open
  entries are an error in every run (counted from creation). A cancellation the query excludes is reported once
  in the protocol. Switching the option off and on again restarts its start date

## 1.0.2

* Order detail: refund dates in the shop's time zone instead of the
  (US) time PayPal returns
* Automatic refund in the reports with its refund ID and "full amount"
  instead of the ID in the reason/amount column

## 1.0.1

* Order detail: shipping, tracking codes and cancellations are booked in
  JTL-Wawi as usual (notice at the top); manual tracking code and return
  are collapsed at the end as "correction if needed"
* Manual return only offers items whose return has not been reported yet
* Amounts as "1.234,50 €" instead of "1234,50 EUR"; spacing of form fields

## 1.0.0

* Imports everysize orders as JTL orders (customer, addresses, cart items
  with unrounded net prices, shipping item, incoming payment, stock, link —
  in one transaction)
* Order numbers from the JTL counter (createOrderNo semantics), reported to
  everysize right away
* Reports: shipping with tracking code and carrier, tracking codes added
  later, cancellation `MERCHANT_DECLINE`, return `RETOUR`, automatic full
  refund after a cancellation before shipping (can be switched off), manual
  refund from the order tab (idempotency key)
* Admin tabs: settings with connection test, orders with detail (items,
  reports, tracking code, return, refund) and sync button, log with filter
* CLI `everysize_checkout:sync` (+ `order:import`/`order:export`), JTL cron
  job with 5-minute throttle, webcron endpoint with token
* Tax by tax class and delivery country, currency and shipping method checks
  before the import, SKU matching that never picks a parent item
