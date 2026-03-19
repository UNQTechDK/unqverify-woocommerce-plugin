/* global UNQCheckout, jQuery */
(function ($) {
  "use strict";

  // Guard: if jQuery was deferred/async by a performance plugin it may not be
  // available at IIFE invocation time. Bail early to avoid a ReferenceError.
  if (!$) {
    console.warn("[UNQVerify] jQuery not available — age gate UI skipped.");
    return;
  }

  var BANNER_ID = "unq-age-checkout-banner";
  var MODAL_ID = "unq-age-modal";

  // Cached promise — ensures the SDK <script> is injected only once.
  var _sdkPromise = null;

  // Guard flag — sdk.init() must only be called once.
  var _sdkInitialized = false;

  // Guard flag — prevents double-firing when BroadcastChannel and SDK
  // callbacks both deliver the same result.
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
      script.src = UNQCheckout.sdkUrl;
      if (UNQCheckout.sdkIntegrity) {
        script.integrity = UNQCheckout.sdkIntegrity;
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
          new Error("Failed to load UNQVerify SDK from: " + UNQCheckout.sdkUrl),
        );
      };
      document.head.appendChild(script);
    });
    return _sdkPromise;
  }

  // -------------------------------------------------------------------------
  // Find the checkout form — try multiple selectors for theme compatibility.
  // -------------------------------------------------------------------------
  function getCheckoutForm() {
    return (
      document.querySelector("form.checkout") ||
      document.querySelector('form[name="checkout"]') ||
      document.querySelector("#order_review")
    );
  }

  // -------------------------------------------------------------------------
  // Banner helpers — idempotent so safe to call on each updated_checkout.
  // -------------------------------------------------------------------------
  function getOrCreateBanner(form) {
    var existing = document.getElementById(BANNER_ID);
    if (existing) return existing;

    var banner = document.createElement("div");
    banner.id = BANNER_ID;
    banner.style.cssText =
      "padding:12px 16px;margin-bottom:16px;border-radius:4px;" +
      "font-size:14px;display:flex;align-items:center;gap:10px;";
    form.insertBefore(banner, form.firstChild);
    return banner;
  }

  function showVerifiedBanner(form) {
    var banner = getOrCreateBanner(form);
    banner.style.background = "#f0fdf4";
    banner.style.border = "1px solid #86efac";
    banner.style.color = "#166534";
    banner.innerHTML =
      "<span>✓</span><span>" + escHtml(UNQCheckout.i18n.verified) + "</span>";
  }

  function showMessageBanner(form, message, type) {
    var banner = getOrCreateBanner(form);
    var isError = type === "error";
    banner.style.background = isError ? "#fef2f2" : "#fffbeb";
    banner.style.border = isError ? "1px solid #fca5a5" : "1px solid #fcd34d";
    banner.style.color = isError ? "#991b1b" : "#92400e";

    var button = document.createElement("button");
    button.type = "button";
    button.textContent = UNQCheckout.i18n.verifyPrompt;
    button.style.cssText =
      "margin-left:auto;padding:6px 12px;border:none;border-radius:4px;" +
      "background:#1d4ed8;color:#fff;cursor:pointer;font-size:13px;";
    button.addEventListener("click", showModal);

    banner.innerHTML = "<span>" + escHtml(message) + "</span>";
    banner.appendChild(button);
  }

  function removeVerifyButton() {
    var banner = document.getElementById(BANNER_ID);
    if (banner) banner.remove();
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // -------------------------------------------------------------------------
  // Age gate modal — shown when the user clicks "Verify" in the checkout
  // banner. The "Verify with MitID" button is the trusted user gesture that
  // pre-opens the popup inside triggerVerification().
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
    title.textContent = UNQCheckout.i18n.modalTitle;

    var body = document.createElement("p");
    body.style.cssText =
      "margin:0 0 24px;font-size:14px;color:#555;line-height:1.65;";
    body.textContent = UNQCheckout.i18n.modalBody;

    var verifyBtn = document.createElement("button");
    verifyBtn.type = "button";
    verifyBtn.style.cssText =
      "display:block;width:100%;padding:13px 16px;margin-bottom:10px;" +
      "background:#1d4ed8;color:#fff;border:none;border-radius:8px;" +
      "font-size:15px;font-weight:600;cursor:pointer;letter-spacing:0.01em;";
    verifyBtn.textContent = UNQCheckout.i18n.modalVerifyBtn;

    var cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.style.cssText =
      "background:none;border:none;color:#6b7280;font-size:14px;" +
      "cursor:pointer;padding:6px;text-decoration:underline;";
    cancelBtn.textContent = UNQCheckout.i18n.modalCancelBtn;

    // Status message area — hidden initially, shown on denied/error/cancel.
    var statusMsg = document.createElement("p");
    statusMsg.style.cssText =
      "display:none;margin:0 0 16px;font-size:13px;padding:10px 14px;" +
      "border-radius:6px;text-align:left;line-height:1.5;";

    // Test-mode banner — full-width strip at the top of the card.
    if (UNQCheckout.testMode) {
      var testBanner = document.createElement("div");
      testBanner.textContent = "\uD83E\uDDEA TEST MODE";
      testBanner.style.cssText =
        "background:#fef9c3;color:#854d0e;border-bottom:1px solid #fde047;" +
        "font-size:11px;font-weight:700;letter-spacing:0.06em;text-align:center;" +
        "padding:7px 28px;border-radius:12px 12px 0 0;" +
        "margin:-32px -28px 24px -28px;cursor:default;";
      testBanner.title =
        "Test mode is active. Switch to Production in WooCommerce \u2192 Settings \u2192 UNQVerify to go live.";
      card.appendChild(testBanner);
    }

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
    _modalParts.verifyBtn.textContent = UNQCheckout.i18n.modalVerifyBtn;
    _modalParts.verifyBtn.disabled = false;
    _modalParts.verifyBtn.style.opacity = "1";
    _modalParts.icon.textContent = type === "error" ? "⚠️" : "🔄";
    _modalParts.verifyBtn.focus();
  }

  function showModal() {
    closeModal();
    _modalParts = buildModal();
    var parts = _modalParts;

    // "Verify with MitID" click — the trusted gesture; triggerVerification()
    // calls window.open() synchronously at its start, so popup blockers pass.
    parts.verifyBtn.addEventListener("click", function () {
      parts.statusMsg.style.display = "none";
      parts.icon.textContent = "🔒";
      parts.verifyBtn.disabled = true;
      parts.verifyBtn.style.opacity = "0.6";
      triggerVerification();
    });

    parts.cancelBtn.addEventListener("click", closeModal);

    parts.overlay.addEventListener("click", function (e) {
      if (e.target === parts.overlay) closeModal();
    });

    function onKeydown(e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", onKeydown);
      }
    }
    document.addEventListener("keydown", onKeydown);

    document.body.appendChild(parts.overlay);
    parts.verifyBtn.focus();
  }

  function closeModal() {
    _modalParts = null;
    var el = document.getElementById(MODAL_ID);
    if (el) el.remove();
  }

  // -------------------------------------------------------------------------
  // Trigger the MitID verification flow.
  // The popup is pre-opened synchronously inside the button-click handler
  // (a trusted user-gesture context) so popup blockers do not suppress it.
  // BroadcastChannel is used to receive the result from the callback page —
  // COOP-safe because it works even when window.opener is null.
  // -------------------------------------------------------------------------
  function triggerVerification() {
    var form = getCheckoutForm();
    if (!form) return;

    _verificationDone = false;

    // Pre-open popup synchronously (popup mode only).
    var preOpenedPopup = null;
    if (UNQCheckout.mode !== "redirect") {
      preOpenedPopup = window.open(
        "about:blank",
        "unqverify-popup",
        "width=520,height=700,resizable=yes,scrollbars=yes",
      );
      if (!preOpenedPopup || preOpenedPopup.closed) {
        showMessageBanner(form, UNQCheckout.i18n.popupBlocked, "warning");
        return;
      }
    }

    // Subscribe to BroadcastChannel posted by the callback page (COOP-safe).
    var bc = null;
    if (preOpenedPopup && window.BroadcastChannel) {
      bc = new BroadcastChannel("unqverify");
      bc.onmessage = function (evt) {
        bc.close();
        var f = getCheckoutForm() || form;
        var type = evt.data && evt.data.type;
        if (type === "UNQVERIFY_VERIFIED") {
          if (_verificationDone) return;
          _verificationDone = true;
          closeModal();
          showVerifiedBanner(f);
        } else if (type === "UNQVERIFY_DENIED") {
          if (_verificationDone) return;
          _verificationDone = true;
          setModalStatus(UNQCheckout.i18n.denied, "error");
        } else {
          if (_verificationDone) return;
          _verificationDone = true;
          setModalStatus(UNQCheckout.i18n.error, "warning");
        }
      };
    }

    loadSdk()
      .then(function (sdk) {
        if (!_sdkInitialized) {
          sdk.init({
            publicKey: UNQCheckout.publicKey,
            ageToVerify: parseInt(UNQCheckout.ageToVerify, 10),
            redirectUri: UNQCheckout.redirectUri,

            onVerified: function () {
              if (_verificationDone) return;
              _verificationDone = true;
              closeModal();
              showVerifiedBanner(getCheckoutForm() || form);
            },

            onDenied: function () {
              if (_verificationDone) return;
              _verificationDone = true;
              setModalStatus(UNQCheckout.i18n.denied, "error");
            },

            onCancelled: function () {
              if (_verificationDone) return;
              _verificationDone = true;
              setModalStatus(UNQCheckout.i18n.cancelled, "warning");
            },

            onError: function (outcome) {
              if (_verificationDone) return;
              _verificationDone = true;
              setModalStatus(
                outcome && outcome.code === "POPUP_BLOCKED"
                  ? UNQCheckout.i18n.popupBlocked
                  : UNQCheckout.i18n.error,
                "warning",
              );
            },
          });
          _sdkInitialized = true;
        }

        if (UNQCheckout.mode === "redirect") {
          sdk.startVerificationWithRedirect();
        } else {
          sdk.startVerificationWithPopup(preOpenedPopup);
        }
      })
      ["catch"](function (err) {
        if (bc) bc.close();
        console.error("[UNQVerify]", err);
        var f = getCheckoutForm();
        if (f) showMessageBanner(f, UNQCheckout.i18n.error, "warning");
      });
  }

  // -------------------------------------------------------------------------
  // Main init — idempotent, safe to call multiple times.
  // -------------------------------------------------------------------------
  function init() {
    var form = getCheckoutForm();
    if (!form) return;

    // If the SDK is already loaded and the user is already verified, just
    // show the confirmed state — no button needed.
    if (window.UnqVerify && window.UnqVerify.isVerified()) {
      showVerifiedBanner(form);
      return;
    }

    // Don't duplicate the banner if it already exists from a previous cycle.
    if (document.getElementById(BANNER_ID)) return;

    // Pre-load the SDK silently so the popup opens faster on button click.
    showMessageBanner(form, UNQCheckout.i18n.verifyPrompt, "warning");
    loadSdk()["catch"](function (err) {
      console.warn("[UNQVerify] SDK pre-load failed:", err);
    });
  }

  // -------------------------------------------------------------------------
  // WooCommerce AJAX re-renders the entire checkout form — re-run init.
  // -------------------------------------------------------------------------
  $(document.body).on("updated_checkout", init);

  // Initial run.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})(typeof jQuery !== "undefined" ? jQuery : null);
