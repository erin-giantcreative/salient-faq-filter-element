/**
 * FAQ Filter element script
 *
 * Goals:
 * - AODA-friendly accordion behavior (ARIA + keyboard)
 * - AJAX category switching without page reload
 * - Fast switching with client-side caching
 *
 * Notes:
 * - Server also caches HTML per term (transients/object cache).
 * - We only replace the accordion HTML + update the title.
 * - Schema is not updated on filter change (per your requirement).
 */

(function () {
  if (!window.SFFE_FAQ_FILTER || !window.SFFE_FAQ_FILTER.ajaxUrl) return;

  /**
   * Simple in-memory cache for AJAX responses.
   * Keyed by instanceId + term value.
   */
  const responseCache = new Map();

  /**
   * Initialize all instances on the page.
   */
  function initAll() {
    const instances = document.querySelectorAll(".sffe-faq-filter");
    instances.forEach(initInstance);
  }

  /**
   * Initialize a single FAQ Filter instance.
   * @param {HTMLElement} root
   */
  function initInstance(root) {
    const select = root.querySelector(".sffe-faq-filter__select");
    const results = root.querySelector("[data-results]");
    const titleEl = root.querySelector(".sffe-faq-filter__title");
    const statusEl = root.querySelector(".sffe-faq-filter__status");

    if (!select || !results || !titleEl) return;

    // Accordion behavior for initial server-rendered markup.
    bindAccordion(results);

    select.addEventListener("change", async function () {
      const term = select.value;
      const label = select.options[select.selectedIndex]?.textContent?.trim() || "All";

      // Update title immediately (fast feedback).
      titleEl.textContent = label;

      // Load FAQs without refresh.
      await fetchAndSwap(root, results, statusEl, term);

      // Re-bind accordion after replacing markup.
      bindAccordion(results);
    });
  }

  /**
   * Fetch markup for a given term and swap into results container.
   *
   * @param {HTMLElement} root
   * @param {HTMLElement} results
   * @param {HTMLElement|null} statusEl
   * @param {string} term
   */
  async function fetchAndSwap(root, results, statusEl, term) {
    const instanceId = root.id || "sffe-unknown";
    const cacheKey = `${instanceId}::${term}`;

    // Client cache first (instant).
    if (responseCache.has(cacheKey)) {
      results.innerHTML = responseCache.get(cacheKey);
      return;
    }

    setStatus(statusEl, "Loading…");

    try {
      const formData = new FormData();
      formData.append("action", "sffe_get_faqs");
      formData.append("nonce", window.SFFE_FAQ_FILTER.nonce);
      formData.append("term", term);
      formData.append("instanceId", instanceId);

      const res = await fetch(window.SFFE_FAQ_FILTER.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: formData,
      });

      const data = await res.json();

      if (!data || !data.success || !data.data || typeof data.data.html !== "string") {
        throw new Error("Bad AJAX response");
      }

      // Cache the HTML for fast re-switching.
      responseCache.set(cacheKey, data.data.html);

      // Swap.
      results.innerHTML = data.data.html;

      setStatus(statusEl, "");
    } catch (e) {
      // Keep it readable for screen readers too (status area is aria-live).
      setStatus(statusEl, "Could not load FAQs. Please try again.");
      // Do not wipe old results on failure.
      console.error(e);
    }
  }

  /**
   * Set aria-live status text.
   * @param {HTMLElement|null} el
   * @param {string} text
   */
  function setStatus(el, text) {
    if (!el) return;
    el.textContent = text;
  }

  /**
   * Bind AODA-friendly accordion behavior within a container.
   *
   * Markup expectations:
   * - Each FAQ toggle is a <button> with aria-expanded + aria-controls
   * - Each panel has [hidden] when collapsed
   *
   * Keyboard:
   * - Enter/Space handled automatically by button
   *
   * @param {HTMLElement} container
   */
  function bindAccordion(container) {
    const toggles = container.querySelectorAll(".sffe-accordion__toggle");
    toggles.forEach((btn) => {
      // Avoid double-binding if the same markup remains.
      if (btn.__sffeBound) return;
      btn.__sffeBound = true;

      btn.addEventListener("click", () => {
        toggleAccordionItem(btn);
      });
    });
  }

  /**
   * Toggle one accordion item.
   * @param {HTMLButtonElement} btn
   */
  function toggleAccordionItem(btn) {
    const expanded = btn.getAttribute("aria-expanded") === "true";
    const panelId = btn.getAttribute("aria-controls");
    if (!panelId) return;

    const panel = document.getElementById(panelId);
    if (!panel) return;

    // Collapse
    if (expanded) {
      btn.setAttribute("aria-expanded", "false");
      panel.hidden = true;
      return;
    }

    // Expand
    btn.setAttribute("aria-expanded", "true");
    panel.hidden = false;
  }

  // Init on DOM ready.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();