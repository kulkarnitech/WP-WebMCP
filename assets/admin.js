(function () {
  "use strict";

  function supported() {
    return !!(
      typeof document !== "undefined" &&
      document.modelContext &&
      typeof document.modelContext.registerTool === "function"
    ) || !!(
      typeof window !== "undefined" &&
      window.navigator &&
      window.navigator.modelContext &&
      (typeof window.navigator.modelContext.registerTool === "function" ||
        typeof window.navigator.modelContext.provideContext === "function")
    );
  }

  function setStatus(el, ok, detail) {
    if (!el) return;
    el.textContent = ok ? "Supported" : "Not supported";
    el.style.fontWeight = "600";
    el.style.color = ok ? "#16a34a" : "#dc2626";
    if (detail) {
      var small = el.parentNode.querySelector(".wp-webmcp-support-detail");
      if (small) small.textContent = detail;
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var el = document.getElementById("wp-webmcp-browser-support");
    if (el) {
      try {
        var ok = supported();
        setStatus(
          el,
          ok,
          ok
            ? "This browser exposes a WebMCP registration API."
            : "This browser does not expose WebMCP APIs yet (plugin will no-op on frontend)."
        );
      } catch (e) {
        setStatus(el, false, "Detection error: " + (e && e.message ? e.message : String(e)));
      }
    }

    var config = window.WP_WEBMCP_ADMIN || {};
    Array.prototype.forEach.call(document.querySelectorAll(".wp-webmcp-run-example"), function (button) {
      button.addEventListener("click", function () {
        var output = button.parentNode.querySelector(".wp-webmcp-example-output");
        if (output) output.textContent = "Loading…";
        fetch(button.getAttribute("data-endpoint"), {
          method: "GET",
          credentials: "same-origin",
          headers: { "X-WP-Nonce": config.nonce || "" }
        }).then(function (response) {
          return response.json().then(function (body) { return { status: response.status, body: body }; });
        }).then(function (result) {
          if (output) output.textContent = JSON.stringify(result, null, 2);
        }).catch(function (error) {
          if (output) output.textContent = String(error && error.message ? error.message : error);
        });
      });
    });
  });
})();
