/**
 * Ainbae Receipt Upload for WooCommerce — Public JS (v2.0.0)
 *
 * Handles:
 *   1. Drag-and-drop UX on the order detail / thank-you page (existing).
 *   2. Checkout receipt modal (Feature 2 — new in v2.0.0).
 */
(function ($) {
  "use strict";

  /* ─── 1. ORDER-PAGE DRAG-AND-DROP ───────────────────────────────────── */
  document.addEventListener("DOMContentLoaded", function () {
    var input = document.getElementById("ainbae_bacs_receipt_file");
    var zone = document.getElementById("ainbae-bacs-dropzone");
    var label = document.getElementById("ainbae-bacs-file-name");

    if (input && zone && label) {
      input.addEventListener("change", function () {
        if (this.files && this.files[0]) {
          label.textContent = this.files[0].name;
          label.className = "ainbae-bacs-file-chosen";
        }
      });

      zone.addEventListener("dragover", function (e) {
        e.preventDefault();
        zone.classList.add("ainbae-bacs-drag-over");
      });

      zone.addEventListener("dragleave", function () {
        zone.classList.remove("ainbae-bacs-drag-over");
      });

      zone.addEventListener("drop", function (e) {
        e.preventDefault();
        zone.classList.remove("ainbae-bacs-drag-over");
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
          input.files = e.dataTransfer.files;
          label.textContent = e.dataTransfer.files[0].name;
          label.className = "ainbae-bacs-file-chosen";
        }
      });
    }

    /* ─── 2. CHECKOUT UPLOAD MODAL ───────────────────────────────────── */
    // Only run when the feature is enabled (JS config injected by PHP).
    if (
      typeof ainbaeBacsCheckout === "undefined" ||
      !ainbaeBacsCheckout.enabled
    ) {
      return;
    }

    var cfg = ainbaeBacsCheckout;
    var MAX_SIZE = 5 * 1024 * 1024;
    var ALLOWED_TYPES = ["image/jpeg", "image/png", "application/pdf"];
    var ALLOWED_EXTS = /\.(jpe?g|png|pdf)$/i;
    var uploading = false;   // prevent double-submit
    var tokenReady = false;  // set to true after AJAX upload succeeds

    // Check if we already have a token cookie on load (preserves state on refresh/errors)
    var match = document.cookie.match(/(^|;)\s*ainbae_bacs_checkout_token\s*=\s*([^;]+)/);
    if (match && match[2]) {
      tokenReady = true;
    }

    // ── Build modal HTML ──────────────────────────────────────────────
    var c = cfg.colors;
    var cssVars =
      "--bacs-card-bg:" + c.card_bg + ";" +
      "--bacs-card-border:" + c.card_border + ";" +
      "--bacs-card-radius:" + c.card_radius + "px;" +
      "--bacs-heading:" + c.heading + ";" +
      "--bacs-subtitle:" + c.subtitle + ";" +
      "--bacs-dropzone-bg:" + c.dropzone_bg + ";" +
      "--bacs-dropzone-border:" + c.dropzone_border + ";" +
      "--bacs-icon:" + c.icon + ";" +
      "--bacs-btn-from:" + c.btn_from + ";" +
      "--bacs-btn-to:" + c.btn_to + ";" +
      "--bacs-btn-text:" + c.btn_text + ";" +
      "--bacs-hint:" + c.hint + ";";

    var $overlay = $('<div id="ainbae-bacs-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ainbae-bacs-modal-title" tabindex="-1">' +
      '<div class="ainbae-bacs-modal" style="' + cssVars + '">' +
        '<button type="button" class="ainbae-bacs-modal-close" aria-label="Close">&times;</button>' +
        '<h3 id="ainbae-bacs-modal-title">' + h(cfg.heading) + "</h3>" +
        '<p class="ainbae-bacs-subtitle">' + h(cfg.subtitle) + "</p>" +
        '<div class="ainbae-bacs-dropzone" id="ainbae-bacs-modal-dropzone">' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<polyline points="16 16 12 12 8 16"></polyline>' +
            '<line x1="12" y1="12" x2="12" y2="21"></line>' +
            '<path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>' +
          "</svg>" +
          '<input type="file" id="ainbae-bacs-modal-file" accept=".jpg,.jpeg,.png,.pdf">' +
          '<div class="ainbae-bacs-dropzone-label">' + h(cfg.dropzone) + '<span id="ainbae-bacs-modal-filename"></span></div>' +
        "</div>" +
        '<div id="ainbae-bacs-modal-progress" class="ainbae-bacs-progress" style="display:none;">' +
          '<div class="ainbae-bacs-progress-bar" id="ainbae-bacs-modal-bar"></div>' +
        "</div>" +
        '<div id="ainbae-bacs-modal-error" class="ainbae-bacs-modal-error" role="alert" style="display:none;"></div>' +
        '<button type="button" id="ainbae-bacs-modal-submit" class="ainbae-bacs-btn-upload">' + h(cfg.upload_btn) + "</button>" +
        '<p class="ainbae-bacs-upload-hint">' + h(cfg.hint) + "</p>" +
      "</div>" +
    "</div>");

    $("body").append($overlay);

    // Hidden token input.
    // If output by PHP, retrieve it; otherwise, append it dynamically as a fallback.
    var $tokenInput = $('#ainbae-bacs-token-input');
    if (!$tokenInput.length) {
      $tokenInput = $('<input type="hidden" name="ainbae_bacs_checkout_token" id="ainbae-bacs-token-input" value="">');
      $("form.checkout, form#order_review").first().append($tokenInput);
    }

    // ── Helpers ───────────────────────────────────────────────────────
    function h(s) {
      return String(s || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function showError(msg) {
      $("#ainbae-bacs-modal-error").text(msg).show();
    }

    function clearError() {
      $("#ainbae-bacs-modal-error").hide().text("");
    }

    function openModal() {
      $overlay.fadeIn(200);
      $("#ainbae-bacs-modal-submit").prop("disabled", false).text(cfg.upload_btn);
      $("#ainbae-bacs-modal-progress").hide();
      $("#ainbae-bacs-modal-bar").css("width", "0%");
      clearError();
      $overlay.focus();
      trapFocus($overlay[0]);
    }

    function closeModal() {
      $overlay.fadeOut(200);
    }

    // ── Focus trap ────────────────────────────────────────────────────
    function trapFocus(el) {
      var focusable = el.querySelectorAll(
        'button, input, textarea, select, a[href], [tabindex]:not([tabindex="-1"])'
      );
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      $(el).off("keydown.ainbaeFocus").on("keydown.ainbaeFocus", function (e) {
        if (e.key !== "Tab") return;
        if (e.shiftKey) {
          if (document.activeElement === first) {
            e.preventDefault();
            last.focus();
          }
        } else {
          if (document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }
      });
    }

    // ── Close behaviours ──────────────────────────────────────────────
    $(".ainbae-bacs-modal-close", $overlay).on("click", closeModal);
    $overlay.on("click", function (e) {
      if ($(e.target).is($overlay)) closeModal();
    });
    $(document).on("keydown.ainbaeModal", function (e) {
      if (e.key === "Escape" && $overlay.is(":visible")) closeModal();
    });

    // ── Dropzone in modal ─────────────────────────────────────────────
    var $modalZone = $("#ainbae-bacs-modal-dropzone");
    var $modalFile = $("#ainbae-bacs-modal-file");
    var $modalFilename = $("#ainbae-bacs-modal-filename");

    $modalZone.on("dragover", function (e) {
      e.preventDefault();
      $modalZone.addClass("ainbae-bacs-drag-over");
    });
    $modalZone.on("dragleave", function () {
      $modalZone.removeClass("ainbae-bacs-drag-over");
    });
    $modalZone.on("drop", function (e) {
      e.preventDefault();
      $modalZone.removeClass("ainbae-bacs-drag-over");
      var dt = e.originalEvent.dataTransfer;
      if (dt && dt.files && dt.files[0]) {
        // DataTransfer.files cannot be directly assigned in all browsers,
        // so use a DataTransfer object where supported.
        try {
          var transfer = new DataTransfer();
          transfer.items.add(dt.files[0]);
          $modalFile[0].files = transfer.files;
        } catch (err) {
          // Fallback: store reference for upload; file input UI won't update.
        }
        $modalFilename.text(dt.files[0].name).addClass("ainbae-bacs-file-chosen");
        clearError();
      }
    });
    $modalFile.on("change", function () {
      if (this.files && this.files[0]) {
        $modalFilename.text(this.files[0].name).addClass("ainbae-bacs-file-chosen");
        clearError();
      }
    });

    // ── Upload ────────────────────────────────────────────────────────
    $("#ainbae-bacs-modal-submit").on("click", function () {
      if (uploading) return;
      clearError();

      var file = $modalFile[0].files[0];
      if (!file) {
        showError(cfg.err_upload);
        return;
      }
      if (!ALLOWED_TYPES.includes(file.type) && !ALLOWED_EXTS.test(file.name)) {
        showError(cfg.err_type);
        return;
      }
      if (file.size > MAX_SIZE) {
        showError(cfg.err_size);
        return;
      }

      uploading = true;
      var $btn = $(this);
      $btn.prop("disabled", true).text(cfg.uploading);
      $("#ainbae-bacs-modal-progress").show();

      var formData = new FormData();
      formData.append("action", "ainbae_bacs_checkout_upload");
      formData.append("nonce", cfg.nonce);
      formData.append("ainbae_bacs_checkout_receipt", file);

      $.ajax({
        url: cfg.ajax_url,
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        xhr: function () {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener("progress", function (e) {
            if (e.lengthComputable) {
              var pct = Math.round((e.loaded / e.total) * 100);
              $("#ainbae-bacs-modal-bar").css("width", pct + "%");
            }
          });
          return xhr;
        },
        success: function (res) {
          uploading = false;
          if (res && res.success && res.data && res.data.token) {
            // Update token input value if present
            $tokenInput = $('#ainbae-bacs-token-input');
            if ($tokenInput.length) {
              $tokenInput.val(res.data.token);
            }

            // Set cookie so backend can access it in REST/blocks checkout.
            document.cookie = "ainbae_bacs_checkout_token=" + res.data.token + "; path=/; max-age=3600; SameSite=Lax";

            tokenReady = true;
            $btn.text(cfg.success);

            setTimeout(function () {
              closeModal();
              // Refresh nonce for a potential retry.
              $.post(cfg.ajax_url, { action: "ainbae_bacs_get_checkout_nonce" }, function (r) {
                if (r && r.success && r.data) cfg.nonce = r.data.nonce;
              });

              // Programmatically click the Place Order button to trigger submission naturally.
              var btn = document.querySelector('#place_order, .wc-block-components-checkout-place-order-button, [name="woocommerce_checkout_place_order"]');
              if (btn) {
                btn.click();
              } else {
                var form = $("form.checkout, form#order_review").first()[0];
                if (form) form.submit();
              }
            }, 600);
          } else {
            var msg = (res && res.data && res.data.message) ? res.data.message : cfg.err_upload;
            showError(msg);
            $btn.prop("disabled", false).text(cfg.upload_btn);
            $("#ainbae-bacs-modal-progress").hide();
            $("#ainbae-bacs-modal-bar").css("width", "0%");
          }
        },
        error: function () {
          uploading = false;
          showError(cfg.err_upload);
          var $b = $("#ainbae-bacs-modal-submit");
          $b.prop("disabled", false).text(cfg.upload_btn);
          $("#ainbae-bacs-modal-progress").hide();
          $("#ainbae-bacs-modal-bar").css("width", "0%");
        },
      });
    });

    // ── Intercept WooCommerce checkout submission ──────────────────────
    // WooCommerce fires checkout_place_order when the customer clicks
    // "Place Order". Returning false from this handler blocks submission.
    //
    // We also need to handle the generic form submit event to catch themes
    // that bypass WC's event system (e.g. block-based checkout or custom
    // "Place Order" buttons).

    function isBacsSelected() {
      // 1. Classic checkout radio check
      var paymentMethod = $(
        "input[name='payment_method']:checked, input[name='payment_method'][type='hidden']"
      ).val();
      if (paymentMethod === "bacs") {
        return true;
      }
      
      // 2. Block/custom checkout radio button check
      if ($("input[type='radio'][value='bacs']:checked").length > 0) {
        return true;
      }
      if ($("input[type='radio'][id*='bacs']:checked").length > 0) {
        return true;
      }
      
      // 3. Block checkout active state class/attribute check
      if ($(".wc-block-components-radio-control-item.is-selected [value='bacs']").length > 0) {
        return true;
      }
      if ($(".wc-block-checkout__payment-method.is-selected [value='bacs']").length > 0) {
        return true;
      }
      if ($(".wc-block-checkout__payment-method--bacs.is-selected").length > 0) {
        return true;
      }
      if ($("[data-value='bacs']").length && ($("[data-value='bacs']").hasClass("is-selected") || $("[data-value='bacs']").find("input:checked").length)) {
        return true;
      }
      
      return false;
    }

    function shouldInterceptCheckout() {
      return isBacsSelected() && !tokenReady;
    }

    // ── Capturing event listener to intercept the Place Order click ──────
    // Using capturing phase (true) allows us to catch the click event before
    // React/WooCommerce block event delegation handlers receive it.
    window.addEventListener(
      "click",
      function (e) {
        if (!shouldInterceptCheckout()) {
          return;
        }

        var target = e.target;
        if (!target) {
          return;
        }

        var btn = null;
        if (typeof target.closest === "function") {
          btn = target.closest(
            '#place_order, .wc-block-components-checkout-place-order-button, [name="woocommerce_checkout_place_order"]'
          );
        }

        if (btn) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();
          openModal();
        }
      },
      true // capturing phase!
    );

    // Classic checkout event (WooCommerce built-in fallback)
    $(document.body).on("checkout_place_order", function () {
      if (!shouldInterceptCheckout()) return true;
      openModal();
      return false;
    });

    // Also intercept the form submit directly (catches edge cases)
    $(document).on("submit", "form.checkout, form#order_review", function (e) {
      if (!shouldInterceptCheckout()) return true;
      e.preventDefault();
      e.stopImmediatePropagation();
      openModal();
      return false;
    });
  });
})(jQuery);
