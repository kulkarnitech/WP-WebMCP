/**
 * WP WebMCP Layer - browser bridge.
 *
 * The current WebMCP draft exposes document.modelContext.registerTool(). A
 * legacy navigator.modelContext.provideContext() fallback is kept for early
 * previews, but the plugin never requires either API to render the site.
 */
(function () {
  "use strict";

  var MAX_RESULT_CHARS = 12000;

  function config() {
    return window.WP_WEBMCP || null;
  }

  function contextApi() {
    if (
      typeof document !== "undefined" &&
      document.modelContext &&
      typeof document.modelContext.registerTool === "function"
    ) {
      return { kind: "draft", context: document.modelContext };
    }

    if (
      window.navigator &&
      window.navigator.modelContext &&
      typeof window.navigator.modelContext.provideContext === "function"
    ) {
      return { kind: "legacy", context: window.navigator.modelContext };
    }

    return null;
  }

  function enabled(key) {
    var cfg = config();
    return !!(cfg && cfg.tools && Number(cfg.tools[key]) === 1);
  }

  function endpoint(path) {
    var cfg = config();
    var base = String((cfg && cfg.restUrl) || "").replace(/\/+$/, "");
    return base + "/" + String(path || "").replace(/^\/+/, "");
  }

  function safeJson(value) {
    try {
      return JSON.stringify(value);
    } catch (e) {
      return String(value);
    }
  }

  function result(value) {
    var text = typeof value === "string" ? value : safeJson(value);
    if (typeof text !== "string") text = String(text);
    if (text.length <= MAX_RESULT_CHARS) return text;
    return text.slice(0, MAX_RESULT_CHARS - 3) + "...";
  }

  function ensureActive(options) {
    if (options && options.signal && options.signal.aborted) {
      throw new Error("Tool execution was cancelled.");
    }
  }

  async function parseResponse(response) {
    var text = await response.text();
    var data;

    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      data = { message: text };
    }

    if (!response.ok) {
      var message = data && (data.message || data.error);
      throw new Error(message || "WebMCP request failed (HTTP " + response.status + ").");
    }

    return data;
  }

  async function apiRequest(method, path, input, options) {
    var cfg = config();
    var url = new URL(endpoint(path), window.location.href);

    var upperMethod = String(method || "GET").toUpperCase();
    var request = {
      method: upperMethod,
      headers: { "X-WP-Nonce": cfg.nonce },
      credentials: "same-origin",
      signal: options && options.signal,
    };

    if (upperMethod === "GET" || upperMethod === "HEAD") {
      Object.keys(input || {}).forEach(function (key) {
        if (input[key] === undefined || input[key] === null || input[key] === "") return;
        url.searchParams.set(key, String(input[key]));
      });
    } else {
      request.headers["Content-Type"] = "application/json";
      request.body = JSON.stringify(input || {});
    }

    var response = await fetch(url.toString(), request);

    return parseResponse(response);
  }

  async function apiGet(path, params, options) {
    return apiRequest("GET", path, params, options);
  }

  async function apiPost(path, body, options) {
    return apiRequest("POST", path, body, options);
  }

  async function confirmCartAdd(input, options) {
    ensureActive(options);

    if (typeof window.confirm !== "function") return false;

    var productId = Number(input.product_id || 0);
    var qty = Number(input.qty || 1);
    return window.confirm("Add product " + productId + " (quantity " + qty + ") to the cart?");
  }

  var handlers = {
    wp_search: async function (input, options) {
      ensureActive(options);
      var data = await apiGet("/search", { q: input.q, type: input.type }, options);
      return result(data.results || data);
    },

    wp_get_post: async function (input, options) {
      ensureActive(options);
      var data = await apiGet("/post", { id: Number(input.id || 0) }, options);
      return result(data);
    },

    woo_cart_view: async function (input, options) {
      ensureActive(options);
      var data = await apiGet("/cart/view", {}, options);
      return result(data);
    },

    woo_cart_add: async function (input, options) {
      if (!(await confirmCartAdd(input, options))) return "Cancelled by the user.";

      var productId = Number(input.product_id || 0);
      var qty = Math.max(1, Math.min(100, Number(input.qty || 1)));
      var data = await apiPost("/cart/add", { product_id: productId, qty: qty }, options);
      return result(data);
    },
  };

  function buildTools() {
    var cfg = config();
    var definitions = (cfg && cfg.definitions) || {};
    var tools = [];

    Object.keys(definitions).forEach(function (key) {
      if (!enabled(key)) return;

      var definition = definitions[key];
      var execute = handlers[key];

      // Integrations can register a REST-backed tool by supplying method and
      // path in the shared definition. Built-in tools keep their explicit
      // handlers for confirmation and response shaping.
      if (typeof execute !== "function" && definition.path) {
        execute = function (input, options) {
          ensureActive(options);
          return apiRequest(definition.method || "GET", definition.path, input, options).then(result);
        };
      }

      if (typeof execute !== "function") return;

      tools.push({
        name: definition.name,
        title: definition.title,
        description: definition.description,
        inputSchema: definition.inputSchema,
        annotations: definition.annotations,
        execute: function (input, options) {
          return execute(input || {}, options || {});
        },
      });
    });

    return tools;
  }

  async function registerTools(api, tools) {
    var cfg = config();

    if (cfg._registered) return;

    if (api.kind === "draft") {
      var controller = new AbortController();

      for (var i = 0; i < tools.length; i += 1) {
        await api.context.registerTool(tools[i], { signal: controller.signal });
      }

      cfg._abortController = controller;
      window.addEventListener(
        "pagehide",
        function () {
          controller.abort();
        },
        { once: true }
      );
    } else {
      // Early WebMCP previews used MCP content envelopes. Keep that adapter
      // isolated so the current draft API receives the plain serializable
      // result returned by each tool.
      var legacyTools = tools.map(function (tool) {
        return Object.assign({}, tool, {
          execute: async function (input, options) {
            var value = await tool.execute(input, options);
            return { content: [{ type: "text", text: result(value) }] };
          },
        });
      });
      await api.context.provideContext({ tools: legacyTools });
    }

    cfg._registered = true;
  }

  async function init() {
    var cfg = config();
    var api = contextApi();

    if (!cfg || !api || Number((cfg.tools || {}).enabled) === 0) return;

    var tools = buildTools();
    if (!tools.length) return;

    try {
      await registerTools(api, tools);
    } catch (error) {
      // WebMCP is progressive enhancement; never break the site if a browser
      // preview rejects a schema or changes the draft API.
      if (window.console && typeof window.console.warn === "function") {
        window.console.warn("WP WebMCP registration failed:", error);
      }
    }
  }

  document.addEventListener("DOMContentLoaded", init);
})();
