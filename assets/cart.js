/* global UNQCart, UNQAgeVerificationUI, jQuery */
(function ($) {
  "use strict";

  // Guard: if jQuery was deferred/async by a performance plugin it may not be
  // available at IIFE invocation time. Bail early to avoid a ReferenceError.
  if (!$) {
    console.warn("[UNQVerify] jQuery not available — age gate UI skipped.");
    return;
  }

  // Single promise shared across all loadSdk() calls so the script is never
  // injected more than once, even when called before the first load finishes.
  var _sdkPromise = null;

  // Guard flag — sdk.init() must only be called once.
  var _sdkInitialized = false;

  // Track which button element currently has our click listener so we never
  // double-bind and can cleanly remove it when the DOM node is replaced.
  var _boundButton = null;

  // MutationObserver instance — watches for React-rendered block cart nodes.
  var _observer = null;

  // Guard flag — prevents double-firing when both BroadcastChannel and the
  // SDK's internal postMessage deliver the result. Only the first one wins.
  var _verificationDone = false;

  // Shared accessible modal controller from verification-ui.js.
  var _modal = null;

  // -------------------------------------------------------------------------
  // SDK loader — cached so the <script> tag is injected only once.
  // -------------------------------------------------------------------------
  function loadSdk() {
    if (window.UnqVerify) {
      return Promise.resolve(window.UnqVerify);
    }
    if (_sdkPromise) {
      return _sdkPromise;
    }
    _sdkPromise = new Promise(function (resolve, reject) {
      var script = document.createElement("script");
      script.src = UNQCart.sdkUrl;
      if (UNQCart.sdkIntegrity) {
        script.integrity = UNQCart.sdkIntegrity;
        script.crossOrigin = "anonymous";
      }
      script.async = true;
      script.onload = function () {
        if (window.UnqVerify) {
          resolve(window.UnqVerify);
        } else {
          reject(
            new Error(
              "UnqVerify SDK loaded but window.UnqVerify is undefined.",
            ),
          );
        }
      };
      script.onerror = function () {
        reject(
          new Error("Failed to load UNQVerify SDK from: " + UNQCart.sdkUrl),
        );
      };
      document.head.appendChild(script);
    });
    return _sdkPromise;
  }

  // -------------------------------------------------------------------------
  // Find the "Proceed to Checkout" button.
  // Selectors cover:
  //   - Classic shortcode cart:  a.checkout-button  /  .wc-proceed-to-checkout a
  //   - WooCommerce Block Cart:  a.wc-block-cart__submit-button  (React-rendered)
  // -------------------------------------------------------------------------
  function getCheckoutButton() {
    return (
      document.querySelector("a.checkout-button") ||
      document.querySelector("a.wc-block-cart__submit-button") ||
      document.querySelector(".wc-proceed-to-checkout a") ||
      document.querySelector(".wc-block-cart__submit-container a")
    );
  }

  function getModal() {
    if (_modal) return _modal;
    if (!window.UNQAgeVerificationUI) {
      console.error("[UNQVerify] Verification UI failed to load.");
      return null;
    }

    _modal = window.UNQAgeVerificationUI.createModal({
      i18n: UNQCart.i18n,
      logoUrl: UNQCart.mitIdLogoUrl,
      mode: UNQCart.mode,
      testMode: UNQCart.testMode,
      onVerify: beginVerification,
    });
    return _modal;
  }

  function showModal(trigger) {
    var modal = getModal();
    if (modal) modal.show(trigger);
  }

  function closeModal(options) {
    if (_modal) _modal.close(options);
  }

  function setModalStatus(message, type) {
    if (_modal) _modal.setStatus(message, type);
  }

  // Called directly by the modal button's trusted click event so popup
  // blockers allow the synchronous window.open() call.
  function beginVerification() {
    var preOpenedPopup = null;
    if (UNQCart.mode !== "redirect") {
      preOpenedPopup = window.open(
        "about:blank",
        "unqverify-popup",
        "width=520,height=700,resizable=yes,scrollbars=yes",
      );
      if (!preOpenedPopup || preOpenedPopup.closed) {
        setModalStatus(UNQCart.i18n.popupBlocked, "error");
        return;
      }
    }

    runVerification(preOpenedPopup);
  }

  // -------------------------------------------------------------------------
  // Shared outcome handlers — called by the BroadcastChannel listener OR by
  // sdk.init() callbacks (whichever fires first). _verificationDone ensures
  // only the first invocation takes effect.
  // -------------------------------------------------------------------------
  function handleVerified() {
    if (_verificationDone) return;
    _verificationDone = true;
    closeModal({ restoreFocus: false });
    window.location.href = UNQCart.checkoutUrl;
  }

  function handleDenied() {
    if (_verificationDone) return;
    _verificationDone = true;
    setModalStatus(UNQCart.i18n.denied, "error");
  }

  function handleCancelled() {
    if (_verificationDone) return;
    _verificationDone = true;
    setModalStatus(UNQCart.i18n.cancelled, "warning");
  }

  function handleError(code) {
    if (_verificationDone) return;
    _verificationDone = true;
    setModalStatus(
      code === "POPUP_BLOCKED" ? UNQCart.i18n.popupBlocked : UNQCart.i18n.error,
      "error",
    );
  }

  // -------------------------------------------------------------------------
  // Run the MitID verification flow.
  // preOpenedPopup — a Window opened synchronously in the click handler, or
  //   null for redirect mode. Passing a pre-opened window satisfies popup
  //   blockers (the open() call was in a trusted user-gesture context).
  // sdk.init() is called at most once — subsequent clicks reuse registered
  // callbacks. Calling init() again would overwrite the SDK's internal
  // postMessage listener.
  // -------------------------------------------------------------------------
  function runVerification(preOpenedPopup) {
    _verificationDone = false;

    // Subscribe to BroadcastChannel posted by the callback page.
    // This is the COOP-safe path — works even when MitID's
    // Cross-Origin-Opener-Policy headers sever window.opener.
    var bc = null;
    if (preOpenedPopup && window.BroadcastChannel) {
      bc = new BroadcastChannel("unqverify");
      bc.onmessage = function (evt) {
        bc.close();
        var type = evt.data && evt.data.type;
        if (type === "UNQVERIFY_VERIFIED") {
          handleVerified();
        } else if (type === "UNQVERIFY_DENIED") {
          handleDenied();
        } else {
          handleError(evt.data && evt.data.code);
        }
      };
    }

    loadSdk()
      .then(function (sdk) {
        if (!_sdkInitialized) {
          sdk.init({
            publicKey: UNQCart.publicKey,
            ageToVerify: parseInt(UNQCart.ageToVerify, 10),
            redirectUri: UNQCart.redirectUri,
            onVerified: handleVerified,
            onDenied: handleDenied,
            onCancelled: handleCancelled,
            onError: function (outcome) {
              handleError(outcome && outcome.code);
            },
          });
          _sdkInitialized = true;
        }

        if (UNQCart.mode === "redirect") {
          sdk.startVerificationWithRedirect();
        } else {
          sdk.startVerificationWithPopup(preOpenedPopup);
        }
      })
      ["catch"](function (err) {
        if (bc) bc.close();
        console.error("[UNQVerify]", err);
        setModalStatus(UNQCart.i18n.error, "error");
      });
  }

  // -------------------------------------------------------------------------
  // Named capture-phase click handler.
  // capture=true fires BEFORE React's synthetic event system and WooCommerce's
  // handlers, so preventDefault() reliably blocks navigation.
  // The popup is pre-opened here — synchronously inside the trusted user-
  // gesture context — so popup blockers do not suppress it.
  // -------------------------------------------------------------------------
  function onCheckoutClick(e) {
    if (window.UnqVerify && window.UnqVerify.isVerified()) {
      // Already verified — pass through, WooCommerce navigates to checkout.
      return;
    }

    // Prevent default navigation immediately — cannot defer to async code.
    e.preventDefault();
    e.stopPropagation();

    // The age gate modal handles the rest. window.open() happens inside the
    // modal's "Verify" button click (a fresh user gesture) so popup blockers
    // are satisfied even though navigating the checkout button was one step up.
    showModal(e.currentTarget);
  }

  // -------------------------------------------------------------------------
  // Bind the interceptor to the checkout button.
  // Tracks _boundButton so we never double-bind and can cleanly transfer the
  // listener when the block cart replaces the DOM node.
  // Returns true if a button was found and bound.
  // -------------------------------------------------------------------------
  function bindCheckoutButton() {
    var btn = getCheckoutButton();
    if (!btn) return false;

    // Same DOM node — already bound.
    if (btn === _boundButton) return true;

    // Different node (React replaced it) — remove listener from the old one.
    if (_boundButton) {
      _boundButton.removeEventListener("click", onCheckoutClick, true);
      _boundButton.removeAttribute("aria-haspopup");
      _boundButton.removeAttribute("aria-controls");
    }

    btn.addEventListener("click", onCheckoutClick, true);
    btn.setAttribute("aria-haspopup", "dialog");
    btn.setAttribute("aria-controls", "unq-age-modal");
    _boundButton = btn;
    return true;
  }

  // -------------------------------------------------------------------------
  // MutationObserver — the primary mechanism for WooCommerce Block Cart.
  //
  // The block cart renders its button via React well after DOMContentLoaded.
  // No jQuery cart events fire. MutationObserver catches every DOM insertion
  // and calls bindCheckoutButton() until it succeeds.
  // -------------------------------------------------------------------------
  function startObserver() {
    if (_observer || !window.MutationObserver) return;

    _observer = new MutationObserver(function () {
      // Try to bind on every DOM mutation.
      if (!bindCheckoutButton()) return;

      // Button is bound. If the user is already verified, remove our
      // interceptor and stop watching — we're done.
      if (window.UnqVerify && window.UnqVerify.isVerified()) {
        if (_boundButton) {
          _boundButton.removeEventListener("click", onCheckoutClick, true);
          _boundButton.removeAttribute("aria-haspopup");
          _boundButton.removeAttribute("aria-controls");
          _boundButton = null;
        }
        _observer.disconnect();
        _observer = null;
      }
    });

    _observer.observe(document.body, { childList: true, subtree: true });
  }

  // -------------------------------------------------------------------------
  // Main init.
  // 1.  Try to bind the button immediately (classic cart button already in DOM).
  // 2.  Start MutationObserver so we catch the block cart button when React
  //     adds it — this is the path that fixes the "blink" on block cart.
  // 3.  Load the SDK in the background so the popup opens instantly on click.
  // -------------------------------------------------------------------------
  function init() {
    // 1 — Try immediate bind (classic cart or server-side rendered themes).
    bindCheckoutButton();

    // 2 — Watch for async-rendered button (WooCommerce Block Cart / React).
    startObserver();

    // 3 — Pre-load SDK. On success, check if already verified and clean up.
    loadSdk()
      .then(function (sdk) {
        if (sdk.isVerified()) {
          if (_boundButton) {
            _boundButton.removeEventListener("click", onCheckoutClick, true);
            _boundButton.removeAttribute("aria-haspopup");
            _boundButton.removeAttribute("aria-controls");
            _boundButton = null;
          }
          if (_observer) {
            _observer.disconnect();
            _observer = null;
          }
        }
      })
      ["catch"](function (err) {
        console.warn("[UNQVerify] SDK pre-load failed:", err);
        // Interceptor stays in place — runVerification() will retry loadSdk().
      });
  }

  // Classic cart AJAX re-render (keep for shortcode cart themes).
  $(document.body).on(
    "updated_cart_totals wc_fragments_refreshed",
    function () {
      // Re-bind in case the button DOM node was replaced.
      bindCheckoutButton();
    },
  );

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(typeof jQuery !== "undefined" ? jQuery : null);
