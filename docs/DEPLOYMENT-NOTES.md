# Deployment notes (reference only)

1. Set up a separate test environment; do not use a live store or production WhatsApp session.
2. Configure a private `AZULA_API_KEY` value for the bridge; never commit `.env`.
3. Install Docker and review `whatsapp-bridge/docker-compose.yml` before running it. The example volumes are independent from the original server.
4. Put the bridge behind a TLS-enabled reverse proxy or private network; the example port binds to localhost.
5. Install the PHP plugin on a WooCommerce test site and configure the bridge URL/key in its settings.
6. Test connectivity, a test order, send/retry behavior, polls, reply attribution, and recovery before production use.

**This is not a validated installation procedure.** It intentionally omits live domains, IPs, passwords, and customer data.
