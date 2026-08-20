# Deployment and security checklist

1. Serve the site over HTTPS and keep WordPress same-origin cookies enabled. The
   browser bridge uses the WordPress REST nonce (`X-WP-Nonce`) and does not send
   credentials to an external relay.
2. Review every tool in **Settings → WP WebMCP Layer**. Read tools can be public,
   while cart and community mutations should stay capability-gated and require
   browser confirmation.
3. Keep the default request-size, rate-limit, schema-depth, and idempotency
   bounds unless the site has measured reasons to change them. If a reverse
   proxy is in front of WordPress, configure its trusted client-IP handling; the
   plugin deliberately does not trust arbitrary `X-Forwarded-For` values.
4. Send a restrictive Permissions Policy from the web server when your browser
   implementation supports it, for example `Permissions-Policy: tools=(self)`.
   Preserve any existing policy directives required by the theme or host.
5. Do not cache `/wp-json/webmcp/v1/manifest`, `/discovery`, `/nonce`, or
   stateful cart routes publicly. The plugin marks discovery responses private
   and varies them by cookie.
6. Treat tool output as untrusted content. The adapters strip HTML and cap text
   lengths before returning it to an agent.

The blue connector/token UI from webmcp.dev is intentionally deferred. It can be
added later as an optional, explicitly configured integration without changing
the same-origin REST and nonce boundary described here.
