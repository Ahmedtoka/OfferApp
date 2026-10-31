/* Pairly storefront: cart message. Plain script; also loads as a CommonJS module for tests. */
(function (root) {
  'use strict';

  /**
   * The nudge to show for the eligible units in the cart, or null.
   * Odd units mean one more eligible piece completes a pair; the offer is off when pct is not 1..100.
   * t: { free_next, discount_next } where discount_next carries a {percent} placeholder.
   */
  function nextStepMessage(eligibleUnits, pct, t) {
    var n = Number(eligibleUnits);
    var p = Number(pct);
    if (!t || !isFinite(n) || n < 1 || n % 2 !== 1) return null;
    if (!isFinite(p) || p < 1 || p > 100) return null;
    if (p === 100) return t.free_next || null;
    return t.discount_next ? String(t.discount_next).replace(/\{\{\s*percent\s*\}\}|\{percent\}/g, String(Math.round(p))) : null;
  }

  function init(doc) {
    var box = doc.getElementById('pairly-cart');
    if (!box || box.getAttribute('data-ready')) return;
    box.setAttribute('data-ready', '1');
    var t = { free_next: box.getAttribute('data-free'), discount_next: box.getAttribute('data-discount') };
    var pct = Number(box.getAttribute('data-percent'));
    var cartUrl = box.getAttribute('data-cart-url') || '/cart';
    var timer = 0;

    function render(units) {
      var text = nextStepMessage(units, pct, t);
      box.textContent = text || '';
      box.hidden = !text;
    }

    // The embed counts the cart's eligible units in Liquid on every page load. After an AJAX cart change the
    // same count is read back from a fresh copy of the cart page: no product data leaves the shop's domain.
    function refresh() {
      root.fetch(cartUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .then(function (html) {
          var el = html && new root.DOMParser().parseFromString(html, 'text/html').getElementById('pairly-cart');
          if (el) render(Number(el.getAttribute('data-units')));
        })
        .catch(function () {});
    }

    function schedule() {
      root.clearTimeout(timer);
      timer = root.setTimeout(refresh, 250);
    }

    render(Number(box.getAttribute('data-units')));

    // Narrow wrapper: only calls to the cart endpoints trigger a re-read.
    var original = root.fetch;
    if (typeof original === 'function') {
      root.fetch = function (input) {
        var p = original.apply(this, arguments);
        try {
          var url = typeof input === 'string' ? input : input && input.url;
          if (/\/cart\/(add|change|update|clear)(\.js)?(\?|$)/.test(url || '')) p.then(schedule, function () {});
        } catch (e) { /* never break the theme's own request */ }
        return p;
      };
    }
    ['cart:updated', 'cart:change', 'cart:refresh'].forEach(function (name) { doc.addEventListener(name, schedule); });
    root.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
  }

  var api = { nextStepMessage: nextStepMessage, init: init };
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    root.Pairly = api;
    if (root.document) {
      if (root.document.readyState === 'loading') {
        root.document.addEventListener('DOMContentLoaded', function () { init(root.document); });
      } else {
        init(root.document);
      }
    }
  }
})(typeof window !== 'undefined' ? window : globalThis);
