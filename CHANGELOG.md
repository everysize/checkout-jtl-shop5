# Changelog

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
