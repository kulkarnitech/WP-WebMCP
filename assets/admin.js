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
    if (!el) return;

    try {
      var ok = supported();
      setStatus(
        el,
        ok,
        ok
          ? "This browser exposes navigator.modelContext.provideContext()."
          : "This browser does not expose WebMCP APIs yet (plugin will no-op on frontend)."
      );
    } catch (e) {
      setStatus(el, false, "Detection error: " + (e && e.message ? e.message : String(e)));
    }
  });
})();
