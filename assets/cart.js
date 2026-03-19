/* global UNQCart, jQuery */
(function ($) {
  "use strict";

  var MSG_ID = "unq-age-cart-notice";
  var MODAL_ID = "unq-age-modal";

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

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
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

  // -------------------------------------------------------------------------
  // Inline notice below the checkout button.
  // -------------------------------------------------------------------------
  function showNotice(message, type) {
    removeNotice();

    var checkoutBtn = getCheckoutButton();
    if (!checkoutBtn) return;

    var notice = document.createElement("p");
    notice.id = MSG_ID;
    notice.style.cssText =
      "margin-top:8px;font-size:13px;padding:8px 12px;border-radius:4px;";

    if (type === "error") {
      notice.style.background = "#fef2f2";
      notice.style.color = "#991b1b";
      notice.style.border = "1px solid #fca5a5";
    } else {
      notice.style.background = "#fffbeb";
      notice.style.color = "#92400e";
      notice.style.border = "1px solid #fcd34d";
    }

    notice.textContent = message;

    var container = checkoutBtn.closest(
      ".cart_totals, .wc-proceed-to-checkout, .wc-block-cart__submit-container",
    );
    if (container) {
      container.appendChild(notice);
    } else if (checkoutBtn.parentNode) {
      checkoutBtn.parentNode.insertBefore(notice, checkoutBtn.nextSibling);
    }
  }

  function removeNotice() {
    var n = document.getElementById(MSG_ID);
    if (n) n.remove();
  }

  // -------------------------------------------------------------------------
  // Age gate modal — shown when the customer clicks "Proceed to checkout"
  // before verifying. The "Verify with MitID" button inside the modal is the
  // synchronous user gesture that calls window.open(), satisfying popup
  // blockers even though the checkout button click was one level up.
  // -------------------------------------------------------------------------
  function buildModal() {
    var overlay = document.createElement("div");
    overlay.id = MODAL_ID;
    overlay.style.cssText =
      "position:fixed;inset:0;z-index:999999;display:flex;align-items:center;" +
      "justify-content:center;background:rgba(0,0,0,0.55);padding:16px;";

    var card = document.createElement("div");
    card.style.cssText =
      "background:#fff;border-radius:12px;max-width:420px;width:100%;" +
      "padding:32px 28px;box-shadow:0 20px 60px rgba(0,0,0,0.3);text-align:center;" +
      "font-family:inherit;";

    var icon = document.createElement("div");
    icon.style.cssText = "font-size:40px;margin-bottom:16px;line-height:1;";
    icon.textContent = "🔒";

    var title = document.createElement("h2");
    title.style.cssText =
      "margin:0 0 12px;font-size:20px;font-weight:700;color:#111;line-height:1.3;";
    title.textContent = UNQCart.i18n.modalTitle;

    var body = document.createElement("p");
    body.style.cssText =
      "margin:0 0 24px;font-size:14px;color:#555;line-height:1.65;";
    body.textContent = UNQCart.i18n.modalBody;

    var verifyBtn = document.createElement("button");
    verifyBtn.type = "button";
    verifyBtn.style.cssText =
      "display:block;width:100%;padding:13px 16px;margin-bottom:10px;" +
      "background:#1d4ed8;color:#fff;border:none;border-radius:8px;" +
      "font-size:15px;font-weight:600;cursor:pointer;letter-spacing:0.01em;";
    verifyBtn.textContent = UNQCart.i18n.modalVerifyBtn;

    var cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.style.cssText =
      "background:none;border:none;color:#6b7280;font-size:14px;" +
      "cursor:pointer;padding:6px;text-decoration:underline;";
    cancelBtn.textContent = UNQCart.i18n.modalCancelBtn;

    // Status message area — hidden initially, shown on denied/error/cancel.
    var statusMsg = document.createElement("p");
    statusMsg.style.cssText =
      "display:none;margin:0 0 16px;font-size:13px;padding:10px 14px;" +
      "border-radius:6px;text-align:left;line-height:1.5;";

    card.appendChild(icon);
    card.appendChild(title);
    card.appendChild(body);
    card.appendChild(statusMsg);
    card.appendChild(verifyBtn);
    card.appendChild(cancelBtn);
    overlay.appendChild(card);

    return {
      overlay: overlay,
      verifyBtn: verifyBtn,
      cancelBtn: cancelBtn,
      statusMsg: statusMsg,
      icon: icon,
    };
  }

  // Keep a reference to the active modal parts so outcome handlers can update it.
  var _modalParts = null;

  // Show a status message inside the currently-open modal.
  // type: "error" | "warning" | "success"
  function setModalStatus(message, type) {
    if (!_modalParts) return;
    var s = _modalParts.statusMsg;
    if (type === "error") {
      s.style.background = "#fef2f2";
      s.style.color = "#991b1b";
      s.style.border = "1px solid #fca5a5";
    } else {
      s.style.background = "#fffbeb";
      s.style.color = "#92400e";
      s.style.border = "1px solid #fcd34d";
    }
    s.textContent = message;
    s.style.display = "block";
    // Update the verify button label so the user can retry.
    _modalParts.verifyBtn.textContent = UNQCart.i18n.modalVerifyBtn;
    _modalParts.verifyBtn.disabled = false;
    _modalParts.verifyBtn.style.opacity = "1";
    _modalParts.icon.textContent = type === "error" ? "⚠️" : "🔄";
    _modalParts.verifyBtn.focus();
  }

  function showModal() {
    closeModal();
    _modalParts = buildModal();
    var parts = _modalParts;

    // "Verify with MitID" click — THIS is the trusted gesture for window.open().
    parts.verifyBtn.addEventListener("click", function () {
      // Reset status and disable button while flow is in progress.
      parts.statusMsg.style.display = "none";
      parts.icon.textContent = "🔒";
      parts.verifyBtn.disabled = true;
      parts.verifyBtn.style.opacity = "0.6";

      var preOpenedPopup = null;
      if (UNQCart.mode !== "redirect") {
        preOpenedPopup = window.open(
          "about:blank",
          "unqverify-popup",
          "width=520,height=700,resizable=yes,scrollbars=yes",
        );
        if (!preOpenedPopup || preOpenedPopup.closed) {
          setModalStatus(UNQCart.i18n.popupBlocked, "warning");
          return;
        }
      }

      runVerification(preOpenedPopup);
    });

    parts.cancelBtn.addEventListener("click", closeModal);

    // Click on the dim overlay (outside the card) also closes.
    parts.overlay.addEventListener("click", function (e) {
      if (e.target === parts.overlay) closeModal();
    });

    // Escape key closes.
    function onKeydown(e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", onKeydown);
      }
    }
    document.addEventListener("keydown", onKeydown);

    document.body.appendChild(parts.overlay);

    // Move focus to verify button for keyboard users.
    parts.verifyBtn.focus();
  }

  function closeModal() {
    _modalParts = null;
    var el = document.getElementById(MODAL_ID);
    if (el) el.remove();
  }

  // -------------------------------------------------------------------------
  // Shared outcome handlers — called by the BroadcastChannel listener OR by
  // sdk.init() callbacks (whichever fires first). _verificationDone ensures
  // only the first invocation takes effect.
  // -------------------------------------------------------------------------
  function handleVerified() {
    if (_verificationDone) return;
    _verificationDone = true;
    closeModal();
    removeNotice();
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
      "warning",
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
        showNotice(UNQCart.i18n.error, "warning");
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
    showModal();
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
    }

    btn.addEventListener("click", onCheckoutClick, true);
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
})(jQuery);
