# Reconcile settlements and bank payouts

Resolve `Jojeringnax\AirwallexLaravel\Payments\Reconciliation` after the application authorizes access to the configured merchant's financial data. The service uses the Payments account login and the installed SDK. Each returned record includes the configured `environment` and `account_id`. Settlement details also have to prove their batch and merchant in the response. Other reads use the scoped account token and reject contradictory context if the provider supplies it.

| Method | Record and pagination |
| --- | --- |
| `settlements($currency, $fromSettledAt, $toSettledAt, $status, $page = 0, $pageSize = 100)` | Settlement batch summary. Supply both inclusive settlement-date bounds and `PENDING` or `SETTLED`. Continue with the returned integer `next_page` until it is null. |
| `settlement($settlementId)` | Current summary of the original batch. Retain status, amount, fees and the settlement time when supplied. |
| `settlementRecords($settlementId, $page = null, $pageSize = 50)` | Transactions and associated fee breakdowns. Pass the returned `next_page` bookmark with the same batch ID until it is null. |
| `walletActivity($currency, $page = '0', $fromPostedAt = null, $toPostedAt = null, $pageSize = 100)` | Cash wallet entries, including settlement credits, reserves, adjustments, FX and outgoing payout debits. Continue with the returned `next_page` bookmark and the same filters. |
| `bankPayout($transferId)` | Canonical LOCAL or SWIFT transfer, with status, payer and beneficiary currency and amount, fees, and reported FX, funding and dispatch references when supplied. |

A page contains `items` and `next_page`. Keep the original filters with the checkpoint. If a bookmark expires, restart the same bounded window and deduplicate by identity. Retry pending batches by their original ID, including a fresh read of their details once available. Settlement, activity and transfer reads are independent snapshots. Retain transaction and batch IDs, and update the application ledger explicitly if a reread changes the amount or status. Missing stable identifiers, contradictory ownership or a malformed continuation fail the affected read; previously processed pages are recoverable by rereading the same window.

Amounts, taxes, fees and FX rates remain exact SDK strings. Optional scalar fields preserve absence or null, and optional object or fee-breakdown fields appear when supplied with data. `direction` represents the sign of the settlement amount or detail net amount. Wallet activity uses the reported movement sign, with the documented direction reversal for `PAYMENT_RESERVE`, and preserves the original signed amount. Reserve entries can affect available funds without changing the total account balance. Payout direction represents an outgoing bank transfer instruction. Use the transfer and wallet states to decide whether the movement posted. The service omits shopper and bank profile details. [Airwallex reconciliation API](https://www.airwallex.com/docs/payments/manage-payments/reporting), [balance history bank-feed guidance](https://www.airwallex.com/docs/developer-tools/partner-connections/integration-guides/bank-feed)

## Follow the wallet entries

```php
use Jojeringnax\AirwallexLaravel\Payments\Reconciliation;

$reads = app(Reconciliation::class);

$batch = $reads->settlement($settlementId);
$records = $reads->settlementRecords($batch['settlement_id']);
$wallet = $reads->walletActivity($batch['currency'], fromPostedAt: $windowStart, toPostedAt: $windowEnd);

// After following the wallet pages, use the explicit source of a PAYOUT entry.
$transfer = $reads->bankPayout($payoutWalletEntry['source']);
```

The deterministic reconciliation fixture credits EUR 73.00 to the wallet. The settlement details contain EUR 100.00 gross with EUR 3.00 in fees and EUR 0.50 in fee tax, a EUR 20.00 refund with EUR 0.50 in fees, a EUR 5.00 reserve hold and a EUR 2.00 release. The four net records sum to EUR 73.00 to the cent. The fixture's settlement wallet entry has source type `PAYMENT_SETTLEMENT` and the explicit batch ID. Later wallet entries include an adjustment and an FX movement. A separate outgoing EUR 80.00 wallet entry has source type `PAYOUT`. Its exact source ID resolves an external bank transfer with its own fee, USD recipient amount and bank reference.

Join settlement records with their batch ID, and wallet payouts with the transfer ID when the wallet row supplies it. Missing links stay missing. A payout can draw on the combined wallet balance, so a settlement credit and a later payout debit do not indicate how much of that batch funded the payout. Application accounting owns the allocation and the durable identity keys `environment + account_id + record family + provider ID`. [Settlement Records API](https://www.airwallex.com/docs/api/payments/settlement_records), [Transfers API](https://www.airwallex.com/docs/api/payouts/transfers/retrieve)
