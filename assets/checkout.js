/* global UNQCheckout, UNQAgeVerificationUI, jQuery */
(function ($) {
  "use strict";

  // Guard: if jQuery was deferred/async by a performance plugin it may not be
  // available at IIFE invocation time. Bail early to avoid a ReferenceError.
  if (!$) {
    console.warn("[UNQVerify] jQuery not available — age gate UI skipped.");
    return;
  }

  var BANNER_ID = "unq-age-checkout-banner";

  // Cached promise — ensures the SDK <script> is injected only once.
  var _sdkPromise = null;

  // Guard flag — sdk.init() must only be called once.
  var _sdkInitialized = false;

  // Guard flag — prevents double-firing when BroadcastChannel and SDK
  // callbacks both deliver the same result.
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
    banner.className = "unq-agev-banner";
    banner.setAttribute("aria-atomic", "true");
    form.insertBefore(banner, form.firstChild);
    return banner;
  }

  function showVerifiedBanner(form) {
    var banner = getOrCreateBanner(form);
    banner.className = "unq-agev-banner unq-agev-banner--verified";
    banner.setAttribute("role", "status");
    banner.setAttribute("aria-live", "polite");
    banner.tabIndex = -1;
    banner.textContent = "";

    var icon = document.createElement("span");
    icon.setAttribute("aria-hidden", "true");
    icon.textContent = "✓";

    var message = document.createElement("span");
    message.className = "unq-agev-banner__message";
    message.textContent = UNQCheckout.i18n.verified;

    banner.appendChild(icon);
    banner.appendChild(message);
    return banner;
  }

  function showMessageBanner(form, message, type) {
    var banner = getOrCreateBanner(form);
    var isError = type === "error";
    banner.className =
      "unq-agev-banner unq-agev-banner--" + (isError ? "error" : "warning");
    banner.setAttribute("role", isError ? "alert" : "status");
    banner.setAttribute("aria-live", isError ? "assertive" : "polite");
    banner.removeAttribute("tabindex");

    var button = document.createElement("button");
    button.type = "button";
    button.textContent = UNQCheckout.i18n.verifyPrompt;
    button.className = "unq-agev-banner__button";
    button.setAttribute("aria-haspopup", "dialog");
    button.setAttribute("aria-controls", "unq-age-modal");
    button.addEventListener("click", showModal);

    banner.textContent = "";
    var messageElement = document.createElement("span");
    messageElement.className = "unq-agev-banner__message";
    messageElement.textContent = message;
    banner.appendChild(messageElement);
    banner.appendChild(button);
  }

  function getModal() {
    if (_modal) return _modal;
    if (!window.UNQAgeVerificationUI) {
      console.error("[UNQVerify] Verification UI failed to load.");
      return null;
    }

    _modal = window.UNQAgeVerificationUI.createModal({
      i18n: UNQCheckout.i18n,
      logoUrl: UNQCheckout.mitIdLogoUrl,
      mode: UNQCheckout.mode,
      testMode: UNQCheckout.testMode,
      onVerify: triggerVerification,
    });
    return _modal;
  }

  function showModal(event) {
    var modal = getModal();
    if (modal) modal.show(event && event.currentTarget);
  }

  function closeModal(options) {
    if (_modal) _modal.close(options);
  }

  function setModalStatus(message, type) {
    if (_modal) _modal.setStatus(message, type);
  }

  function completeVerification(form) {
    closeModal({ restoreFocus: false });
    var banner = showVerifiedBanner(form);
    banner.focus();
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
    if (!form) {
      setModalStatus(UNQCheckout.i18n.error, "error");
      return;
    }

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
        setModalStatus(UNQCheckout.i18n.popupBlocked, "error");
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
          completeVerification(f);
        } else if (type === "UNQVERIFY_DENIED") {
          if (_verificationDone) return;
          _verificationDone = true;
          setModalStatus(UNQCheckout.i18n.denied, "error");
        } else {
          if (_verificationDone) return;
          _verificationDone = true;
          setModalStatus(UNQCheckout.i18n.error, "error");
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
              completeVerification(getCheckoutForm() || form);
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
                "error",
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
        setModalStatus(UNQCheckout.i18n.error, "error");
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
