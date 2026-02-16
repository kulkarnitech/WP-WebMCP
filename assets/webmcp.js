/**
 * WP WebMCP Layer - Frontend tool registration
 * - Registers WebMCP tools only when the browser supports the WebMCP API
 * - Calls WordPress REST endpoints (secured with X-WP-Nonce)
 * - Respects admin feature toggles passed via WP_WEBMCP.tools
 */
(function () {
  "use strict";

  function supported() {
    return (
      typeof window !== "undefined" &&
      window.navigator &&
      window.navigator.modelContext &&
      typeof window.navigator.modelContext.provideContext === "function"
    );
  }

  function hasToggle(key, fallback) {
    try {
      if (!window.WP_WEBMCP || !WP_WEBMCP.tools) return !!fallback;
      // WP localizes numeric 0/1; normalize to boolean
      return !!Number(WP_WEBMCP.tools[key] ?? fallback);
    } catch (e) {
      return !!fallback;
    }
  }

  async function apiGet(path, params) {
    var url = new URL(WP_WEBMCP.restUrl + path);
    if (params && typeof params === "object") {
      Object.keys(params).forEach(function (k) {
        if (params[k] === undefined || params[k] === null) return;
        url.searchParams.set(k, String(params[k]));
      });
    }

    var res = await fetch(url.toString(), {
      method: "GET",
      headers: {
        "X-WP-Nonce": WP_WEBMCP.nonce,
      },
      credentials: "same-origin",
    });

    if (!res.ok) {
      throw new Error("HTTP " + res.status);
    }
    return res.json();
  }

  async function apiPost(path, body) {
    var res = await fetch(WP_WEBMCP.restUrl + path, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": WP_WEBMCP.nonce,
      },
      credentials: "same-origin",
      body: JSON.stringify(body || {}),
    });

    if (!res.ok) {
      throw new Error("HTTP " + res.status);
    }
    return res.json();
  }

  function asText(text) {
    return {
      content: [
        {
          type: "text",
          text: String(text),
        },
      ],
    };
  }

  function safeJson(obj) {
    try {
      return JSON.stringify(obj, null, 2);
    } catch (e) {
      return String(obj);
    }
  }

  async function confirmAction(agent, message) {
    // If agent supports user interaction requests, use that.
    if (agent && typeof agent.requestUserInteraction === "function") {
      return await agent.requestUserInteraction(function () {
        return Promise.resolve(window.confirm(message));
      });
    }
    // Fallback: just confirm in the browser
    return window.confirm(message);
  }

  function buildTools() {
    var tools = [];

    // -----------------------------
    // Tool: wp_search
    // -----------------------------
    if (hasToggle("wp_search", 1)) {
      tools.push({
        name: "wp_search",
        description:
          "Search site content. Returns posts/pages and (if WooCommerce is enabled) products. Paywalled items are flagged.",
        inputSchema: {
          type: "object",
          properties: {
            q: { type: "string", description: "Search query text" },
            type: {
              type: "string",
              description: "Optional: post|page|product",
            },
          },
          required: ["q"],
        },
        execute: async function (input, agent) {
          var q = (input && input.q) ? String(input.q) : "";
          var type = input && input.type ? String(input.type) : undefined;

          var data = await apiGet("/search", { q: q, type: type });
          return asText(safeJson(data.results || data));
        },
      });
    }

    // -----------------------------
    // Tool: wp_get_post
    // -----------------------------
    if (hasToggle("wp_get_post", 1)) {
      tools.push({
        name: "wp_get_post",
        description:
          "Fetch a WordPress post/page by ID. If Paid Memberships Pro paywalls the content, returns only the title and a paywall notice.",
        inputSchema: {
          type: "object",
          properties: {
            id: { type: "number", description: "WordPress post ID" },
          },
          required: ["id"],
        },
        execute: async function (input, agent) {
          var id = input && input.id ? Number(input.id) : 0;
          if (!id || Number.isNaN(id)) {
            throw new Error("Invalid id");
          }

          var data = await apiGet("/post", { id: id });

          if (data && data.paywalled) {
            var msg =
              (data.title ? data.title : "Paywalled content") +
              "\n\n[PAYWALLED] " +
              (data.message || "This content is behind a membership paywall.") +
              "\n" +
              (data.url || "");
            return asText(msg);
          }

          var out =
            (data.title ? data.title : "") +
            "\n\n" +
            (data.content ? data.content : "") +
            (data.url ? "\n\n" + data.url : "");

          return asText(out);
        },
      });
    }

    // -----------------------------
    // WooCommerce tools (cart)
    // -----------------------------
    if (WP_WEBMCP.hasWoo && hasToggle("woo_cart", 1)) {
      tools.push(
        {
          name: "woo_cart_view",
          description: "View the current WooCommerce cart contents.",
          inputSchema: { type: "object", properties: {}, required: [] },
          execute: async function () {
            var data = await apiGet("/cart/view", {});
            return asText(safeJson(data.items || data));
          },
        },
        {
          name: "woo_cart_add",
          description:
            "Add a product to the WooCommerce cart. Requires user confirmation.",
          inputSchema: {
            type: "object",
            properties: {
              product_id: { type: "number", description: "Woo product ID" },
              qty: { type: "number", description: "Quantity (default 1)" },
            },
            required: ["product_id"],
          },
          execute: async function (input, agent) {
            var product_id =
              input && input.product_id ? Number(input.product_id) : 0;
            var qty = input && input.qty ? Number(input.qty) : 1;

            if (!product_id || Number.isNaN(product_id)) {
              throw new Error("Invalid product_id");
            }
            if (!qty || Number.isNaN(qty) || qty < 1) qty = 1;

            var ok = await confirmAction(
              agent,
              "Add product " + product_id + " (qty " + qty + ") to cart?"
            );
            if (!ok) return asText("Cancelled by user.");

            var data = await apiPost("/cart/add", {
              product_id: product_id,
              qty: qty,
            });

            return asText(data.message || "Added to cart.");
          },
        }
      );
    }

    return tools;
  }

  async function init() {
    if (!window.WP_WEBMCP || !WP_WEBMCP.restUrl) return;
    if (!supported()) return;

    // master toggle is enforced in PHP enqueue, but keep a guard anyway
    if (WP_WEBMCP.tools && Number(WP_WEBMCP.tools.enabled) === 0) return;

    var tools = buildTools();
    if (!tools.length) return;

    // Register the tool bundle via WebMCP
    try {
      await window.navigator.modelContext.provideContext({ tools: tools });
    } catch (e) {
      // Fail silently; don’t break site if WebMCP API changes
      // Optionally log in dev:
      // console.warn("WebMCP provideContext failed:", e);
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
