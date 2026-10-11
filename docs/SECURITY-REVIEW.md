# Security and reliability review — pre-publication

## Source hygiene

This portfolio package contains only PHP/JS source, Docker configuration, example environment variables, and documentation. It intentionally excludes live `.env` files, credentials, WhatsApp tokens, browser profiles, persistent message queues, customer exports, and server backups. The WordPress UI's example bridge hostname has been changed to `bridge.example.com`. Legacy production Docker volume names were replaced with standalone sample volume names.

## Known issues requiring remediation before production reuse

1. **Customer privacy:** Bridge logs may include phone numbers, chat IDs, and message details. Persistent state may contain customer messages. Reduce/redact logs, define retention and access controls.
2. **Diagnostic routes:** Authenticated debug/recent-chat endpoints expose sensitive operational information. Disable or tightly restrict in production.
3. **Authentication:** Bearer API-key comparison and key management need hardening; use HTTPS/private networking and rotation. Do not expose the bridge directly to the internet.
4. **Delivery consistency:** Duplicate order hooks and non-atomic state transitions may cause duplicate sends; network failures and ambiguous responses need stronger handling.
5. **Reply attribution:** Matching replies to orders based on phone numbers can be ambiguous, especially with multiple orders.
6. **Chromium container:** Review sandbox and container privileges, resource limits and dependencies.
7. **Tests:** No automated integration/security tests are included in the provided source.
8. **WhatsApp Web automation:** This relies on WPPConnect/WhatsApp Web, not the official WhatsApp Business Platform API; review platform policies and operational risks.

## Publication checklist

- [x] No production `.env`, WhatsApp token/session directories, state files or logs included
- [x] Remove example production bridge hostname and legacy volume names
- [x] Include `.gitignore` and `.env.example`
- [ ] Full independent secrets scan with a dedicated tool and manual code review
- [ ] Run automated syntax, security and integration tests in an isolated environment
- [ ] Confirm public release and code ownership/licensing

**Never upload original VPS backups or WordPress database exports.** If any actual credential was previously exposed, rotate it rather than merely deleting the value from Git history.
