Azula WhatsApp Automation v0.2.6

0.2.6:
- Fixes confirmation routing: Same Day first; otherwise final payment method cod always uses Normal, even when Processing/is_paid(); otherwise is_paid() uses Paid, falling back to Normal.
- Protects failed online payment attempts followed by final COD. Same Day detection and all queue, retry, poll, reply, admin, manual resend, and HPOS behavior are unchanged.

0.2.5:
- Adds Normal Orders, Same Day Delivery, and Paid Orders confirmation editors in the existing Message templates card. Follow-up templates remain shared.
- Selects the confirmation at send time for both automatic and manual sends: Same Day first, then WC_Order::is_paid(), otherwise Normal.
- Same Day detection reads stored order shipping items: semantic same_day/same-day method IDs (including instance suffixes), then a case-insensitive Same Day Delivery title phrase. Numeric instance IDs alone are not assumed to identify Same Day; generic methods rely on the stored title.
- Preserves saved initial_template as Normal; new Same Day and Paid defaults are available immediately on upgrade.
- Queue, expiry, retries, poll choices, replies, and HPOS order access are unchanged.

Core flow
1. Eligible WooCommerce orders are queued for WhatsApp confirmation.
2. The confirmation poll is sent through the Azula VPS bridge.
3. Customer selects Yes, Confirm or Order Rescheduled.
4. Latest poll choice is saved to the WooCommerce order and the matching reply is sent.

Reliability changes in v0.2.2
- Automatic confirmation jobs expire after 30 minutes. Old orders cannot suddenly receive a confirmation hours or days later from a late WooCommerce status/payment hook.
- Failed automatic sends retry in the background up to 5 attempts.
- Retry state is visible in order meta: queued, sending, retrying, awaiting_choice, confirmed, rescheduled, no_whatsapp, send_failed, expired.
- Successful send is only recorded after the bridge returns success.
- Duplicate automatic hooks do not queue duplicate jobs while an order is already queued/retrying.
- Manual "Send Azula WhatsApp confirmation" remains available and intentionally bypasses the 30-minute automatic expiry.
- Permanent No WhatsApp / No LID errors are not retried.

Notes
- Requires WooCommerce.
- Uses WooCommerce Action Scheduler when available and falls back to WP-Cron.
- Does not automatically change the WooCommerce order status.

0.2.4:
- Treats WPPConnect INCHAT / ISLOGGED / QRREADSUCCESS as connected states.
- Adds WordPress admin controls for reconnect and linking-code requests.
- Reconnect/link-code controls require matching /reconnect and /link-code endpoints on the VPS bridge.
