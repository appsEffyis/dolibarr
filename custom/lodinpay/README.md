# LodinPay - Dolibarr Payment Module

Integrates instant payment links into Dolibarr invoices.
Customers pay online via 20+ European banks.

## Requirements
- Dolibarr >= 17.0, PHP >= 7.4, cURL enabled
- LodinPay merchant account (https://www.lodinpay.io)

## Installation
1. Extract to htdocs/custom/lodinpay/
2. Enable module in Configuration > Modules
3. Enter Client ID and Secret in setup page

## How it works
1. Validate invoice -> LodinPay generates payment link automatically
2. QR code appears on invoice card
3. Generate PDF -> professional payment banner included
4. Customer scans QR or clicks button -> pays online
5. Click Sync -> invoice marked as Paid
