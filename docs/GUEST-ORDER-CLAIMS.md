# Guest order license claims

Applies to unshipped release candidate 0.3.0. Live acceptance evidence is recorded under F25, F27, and F32.

## Customer instructions

1. Sign in to the WordPress/WooCommerce account that should own the licenses. Its account email must match the guest order's billing email.
2. Open **My Account → Licenses**.
3. Under **Claim a guest order**, enter the guest order number and its billing email, then submit **Email claim code**.
4. The page always gives the same response. If the order is eligible, the store sends a one-time code only to the current WooCommerce billing email.
5. Enter the order number and emailed code in the second form. The code is single-use and expires after 30 minutes by default.

Do not send the code to support, place it in a URL, or paste it into logs. Knowing an order number or email address is not enough to see or claim a license. A successful claim links the WooCommerce order and every associated Dreamax license to the signed-in account.

## Merchant instructions

- Confirm HTTPS and Dreamax encryption readiness before enabling customer claims. The documented HTTP exception is only for an explicitly enabled loopback development request.
- Ensure WordPress cron runs so expired claim hashes are cleared promptly.
- Review claim outcomes under **License Manager → Activity**. Audit metadata contains order/count/outcome facts, never the code or a full license key.
- Use **License Manager → Order tools → Guest claim ownership** for exceptional ownership changes:
  - **Release to guest** clears the account link while preserving the order/license history.
  - **Override account owner** requires an existing target WordPress customer ID and links the order plus all its Dreamax licenses to that account.
- Both actions require `dreamax_lm_manage_licenses`, a nonce, explicit confirmation, and invalidate every outstanding code for the order.
- If the billing email, order key, or customer owner changes while a code is outstanding, Dreamax invalidates that code. The customer must request another one.

The claim lifetime filter is `dreamax_lm_guest_claim_lifetime`. Values below five minutes or above the hard 24-hour maximum are rejected. The default is 1,800 seconds.

## Required live acceptance evidence

Run the F25 matrix against supported WordPress and WooCommerce versions with classic order storage and HPOS, real InnoDB transactions, captured mail, concurrent workers, browser sessions, roles/nonces, HTTPS/proxy cases, and WordPress privacy tools. Source/unit evidence alone is not a release PASS.
