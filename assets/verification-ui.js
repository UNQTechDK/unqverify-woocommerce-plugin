/* global window, document */
(function () {
  "use strict";

  var MODAL_ID = "unq-age-modal";
  var TITLE_ID = "unq-age-modal-title";
  var BODY_ID = "unq-age-modal-body";
  var POPUP_NOTICE_ID = "unq-age-modal-popup-notice";
  var FOCUSABLE_SELECTOR =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
    'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function createElement(tagName, className, text) {
    var element = document.createElement(tagName);
    if (className) element.className = className;
    if (typeof text === "string") element.textContent = text;
    return element;
  }

  function createModal(options) {
    var i18n = options.i18n;
    var overlay = null;
    var verifyButton = null;
    var statusMessage = null;
    var busyAnnouncement = null;
    var icon = null;
    var previousFocus = null;
    var previousBodyOverflow = "";
    var inertElements = [];
    var busy = false;

    function buildMitIdButtonContent() {
      verifyButton.textContent = "";

      var logo = createElement("img", "unq-agev-mitid-button__logo");
      logo.src = options.logoUrl;
      logo.alt = "";
      logo.width = 732;
      logo.height = 198;
      logo.draggable = false;
      logo.setAttribute("aria-hidden", "true");

      var label = createElement(
        "span",
        "unq-agev-mitid-button__label",
        i18n.modalVerifyBtn
      );

      verifyButton.appendChild(logo);
      verifyButton.appendChild(label);
    }

    function setBackgroundInert() {
      inertElements = [];
      Array.prototype.forEach.call(document.body.children, function (element) {
        if (element === overlay || element.tagName === "SCRIPT") return;

        inertElements.push({
          element: element,
          hadInertAttribute: element.hasAttribute("inert"),
        });
        element.setAttribute("inert", "");
      });
    }

    function restoreBackground() {
      inertElements.forEach(function (entry) {
        if (!entry.element.isConnected || entry.hadInertAttribute) return;
        entry.element.removeAttribute("inert");
      });
      inertElements = [];
    }

    function getFocusableElements() {
      if (!overlay) return [];
      return Array.prototype.slice.call(
        overlay.querySelectorAll(FOCUSABLE_SELECTOR)
      );
    }

    function close(closeOptions) {
      var shouldRestoreFocus =
        !closeOptions || closeOptions.restoreFocus !== false;
      var focusTarget = previousFocus;
      var wasOpen = Boolean(overlay);

      if (overlay) overlay.remove();
      overlay = null;
      verifyButton = null;
      statusMessage = null;
      busyAnnouncement = null;
      icon = null;
      busy = false;

      if (wasOpen) {
        restoreBackground();
        document.body.style.overflow = previousBodyOverflow;
      }

      if (
        shouldRestoreFocus &&
        focusTarget &&
        focusTarget.isConnected &&
        typeof focusTarget.focus === "function"
      ) {
        focusTarget.focus();
      }
      previousFocus = null;
    }

    function setBusy(isBusy) {
      if (!verifyButton) return;
      busy = isBusy;
      verifyButton.setAttribute("aria-disabled", isBusy ? "true" : "false");
      verifyButton.setAttribute("aria-busy", isBusy ? "true" : "false");
      if (busyAnnouncement) {
        busyAnnouncement.textContent = isBusy ? i18n.verificationStarting : "";
      }
    }

    function setStatus(message, type) {
      if (!statusMessage || !verifyButton) return;

      statusMessage.className =
        "unq-agev-modal__status unq-agev-modal__status--" + type;
      statusMessage.setAttribute(
        "role",
        type === "error" ? "alert" : "status"
      );
      statusMessage.setAttribute(
        "aria-live",
        type === "error" ? "assertive" : "polite"
      );
      statusMessage.textContent = message;
      statusMessage.hidden = false;

      if (icon) icon.textContent = type === "error" ? "⚠️" : "↻";
      setBusy(false);
      verifyButton.focus();
    }

    function handleKeydown(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        close();
        return;
      }

      if (event.key !== "Tab") return;

      var focusableElements = getFocusableElements();
      if (!focusableElements.length) {
        event.preventDefault();
        return;
      }

      var first = focusableElements[0];
      var last = focusableElements[focusableElements.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    function build() {
      var dialog = createElement("div", "unq-agev-modal");
      dialog.setAttribute("role", "dialog");
      dialog.setAttribute("aria-modal", "true");
      dialog.setAttribute("aria-labelledby", TITLE_ID);

      var describedBy = [BODY_ID];
      if (options.mode !== "redirect") describedBy.push(POPUP_NOTICE_ID);
      dialog.setAttribute("aria-describedby", describedBy.join(" "));

      overlay = createElement("div", "unq-agev-overlay");
      overlay.id = MODAL_ID;
      overlay.addEventListener("keydown", handleKeydown);
      overlay.addEventListener("click", function (event) {
        if (event.target === overlay) close();
      });

      if (options.testMode) {
        var testBanner = createElement(
          "div",
          "unq-agev-modal__test-banner",
          i18n.testModeLabel
        );
        var testDescription = createElement(
          "span",
          "unq-agev-sr-only",
          ". " + i18n.testModeDescription
        );
        testBanner.appendChild(testDescription);
        testBanner.title = i18n.testModeDescription;
        dialog.appendChild(testBanner);
      }

      icon = createElement("div", "unq-agev-modal__icon", "🔒");
      icon.setAttribute("aria-hidden", "true");

      var title = createElement("h2", "unq-agev-modal__title", i18n.modalTitle);
      title.id = TITLE_ID;

      var body = createElement("p", "unq-agev-modal__body", i18n.modalBody);
      body.id = BODY_ID;

      dialog.appendChild(icon);
      dialog.appendChild(title);
      dialog.appendChild(body);

      if (options.mode !== "redirect") {
        var popupNotice = createElement(
          "p",
          "unq-agev-modal__popup-notice",
          i18n.popupNotice
        );
        popupNotice.id = POPUP_NOTICE_ID;
        dialog.appendChild(popupNotice);
      }

      statusMessage = createElement("p", "unq-agev-modal__status");
      statusMessage.setAttribute("aria-atomic", "true");
      statusMessage.hidden = true;

      busyAnnouncement = createElement("span", "unq-agev-sr-only");
      busyAnnouncement.setAttribute("role", "status");
      busyAnnouncement.setAttribute("aria-live", "polite");
      busyAnnouncement.setAttribute("aria-atomic", "true");

      verifyButton = createElement(
        "button",
        "unq-agev-modal__primary",
        i18n.modalVerifyBtn
      );
      verifyButton.type = "button";
      buildMitIdButtonContent();
      verifyButton.addEventListener("click", function () {
        if (busy) return;
        statusMessage.hidden = true;
        statusMessage.textContent = "";
        icon.textContent = "🔒";
        setBusy(true);
        try {
          options.onVerify();
        } catch (error) {
          console.error("[UNQVerify]", error);
          setStatus(i18n.error, "error");
        }
      });

      var cancelButton = createElement(
        "button",
        "unq-agev-modal__cancel",
        i18n.modalCancelBtn
      );
      cancelButton.type = "button";
      cancelButton.addEventListener("click", function () {
        close();
      });

      dialog.appendChild(statusMessage);
      dialog.appendChild(busyAnnouncement);
      dialog.appendChild(verifyButton);
      dialog.appendChild(cancelButton);
      overlay.appendChild(dialog);
    }

    function show(trigger) {
      close({ restoreFocus: false });
      previousFocus =
        trigger && typeof trigger.focus === "function"
          ? trigger
          : document.activeElement;
      previousBodyOverflow = document.body.style.overflow;

      build();
      document.body.appendChild(overlay);
      setBackgroundInert();
      document.body.style.overflow = "hidden";
      verifyButton.focus();
    }

    return {
      close: close,
      setBusy: setBusy,
      setStatus: setStatus,
      show: show,
    };
  }

  window.UNQAgeVerificationUI = {
    createModal: createModal,
  };
})();
