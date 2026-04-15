jQuery(document).ready(function ($) {
  // Colour pickers
  $(".ainbae-bacs-color-picker").wpColorPicker({
    change: function (e, ui) {
      $(e.target).val(ui.color.toString());
      schedulePreview();
    },
    clear: function () {
      schedulePreview();
    },
  });

  // WhatsApp toggle
  $('input[name="whatsapp_enabled"]').on("change", function () {
    var row = $("#ainbae-bacs-wa-number-row");
    if (this.checked) {
      row.css({ opacity: "1", pointerEvents: "auto" });
    } else {
      row.css({ opacity: ".4", pointerEvents: "none" });
    }
    schedulePreview();
  });

  // Border radius slider
  $("#ainbae_bacs_br_range").on("input", function () {
    $("#card_border_radius").val(this.value);
    schedulePreview();
  });
  $("#card_border_radius").on("input", function () {
    $("#ainbae_bacs_br_range").val(this.value);
    schedulePreview();
  });

  // Label fields
  $('[name^="label_"]').on("input", schedulePreview);

  // Preview Logic
  var previewTimer;
  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(buildPreview, 80);
  }

  function g(name) {
    var el = document.querySelector('[name="' + name + '"]');
    if (!el) return "";
    return el.type === "checkbox" ? (el.checked ? "1" : "0") : el.value;
  }

  function h(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function buildPreview() {
    var container = document.getElementById("ainbae-bacs-preview-container");
    if (!container) return;

    var wa = g("whatsapp_enabled") === "1";
    var rawBr = g("card_border_radius");
    var br = (parseInt(rawBr, 10) || 0) + "px";

    var html =
      '<div style="background:' +
      g("color_card_bg") +
      ";border:1px solid " +
      g("color_card_border") +
      ";border-radius:" +
      br +
      ';padding:20px 16px;font-family:inherit;">' +
      '<h3 style="text-align:center;margin:0 0 6px;font-size:16px;font-weight:700;color:' +
      g("color_heading") +
      ';">' +
      h(g("label_heading")) +
      "</h3>" +
      '<p style="text-align:center;color:' +
      g("color_subtitle") +
      ';font-size:12px;margin:0 0 14px;">' +
      h(g("label_subtitle")) +
      "</p>" +
      '<div style="border:2px dashed ' +
      g("color_dropzone_border") +
      ";border-radius:8px;background:" +
      g("color_dropzone_bg") +
      ';padding:18px 12px;text-align:center;margin-bottom:10px;">' +
      '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="' +
      g("color_icon") +
      '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 6px;"><polyline points="16 16 12 12 8 16"></polyline><line x1="12" y1="12" x2="12" y2="21"></line><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path></svg>' +
      '<div style="font-size:12px;color:#555;">' +
      h(g("label_dropzone")) +
      "</div></div>" +
      '<div style="padding:10px;border-radius:6px;text-align:center;background:linear-gradient(90deg,' +
      g("color_upload_btn_from") +
      "," +
      g("color_upload_btn_to") +
      ");color:" +
      g("color_upload_btn_text") +
      ';font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:5px;">' +
      h(g("label_upload_btn")) +
      "</div>" +
      '<p style="text-align:center;font-size:11px;color:' +
      g("color_hint") +
      ';margin:0 0 8px;">' +
      h(g("label_hint")) +
      "</p>";

    if (wa) {
      html +=
        '<div style="display:flex;align-items:center;gap:8px;margin:10px 0;">' +
        '<div style="flex:1;border-bottom:1px solid ' +
        g("color_or_line") +
        '"></div>' +
        '<span style="color:' +
        g("color_or_text") +
        ';font-size:11px;">OR</span>' +
        '<div style="flex:1;border-bottom:1px solid ' +
        g("color_or_line") +
        '"></div></div>' +
        '<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-radius:6px;background:' +
        g("color_wa_btn_bg") +
        ";border:1.5px solid " +
        g("color_wa_btn_border") +
        ";color:" +
        g("color_wa_btn_text") +
        ';font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;">' +
        '<svg fill="currentColor" width="14" height="14" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M11.42 9.49c-.19-.09-1.1-.54-1.27-.61s-.29-.09-.42.1-.48.6-.59.73-.21.14-.4 0a5.13 5.13 0 0 1-1.49-.92 5.25 5.25 0 0 1-1-1.29c-.11-.18 0-.28.08-.38s.18-.21.28-.32a1.39 1.39 0 0 0 .18-.31.38.38 0 0 0 0-.33c0-.09-.42-1-.58-1.37s-.3-.32-.41-.32h-.4a.72.72 0 0 0-.5.23 2.1 2.1 0 0 0-.65 1.55A3.59 3.59 0 0 0 5 8.2 8.32 8.32 0 0 0 8.19 11c.44.19.78.3 1.05.39a2.53 2.53 0 0 0 1.17.07 1.93 1.93 0 0 0 1.26-.88 1.67 1.67 0 0 0 .11-.88c-.05-.07-.17-.12-.36-.21z"/><path d="M13.29 2.68A7.36 7.36 0 0 0 8 .5a7.44 7.44 0 0 0-6.41 11.15l-1 3.85 3.94-1a7.4 7.4 0 0 0 3.55.9H8a7.44 7.44 0 0 0 5.29-12.72zM8 14.12a6.12 6.12 0 0 1-3.15-.87l-.22-.13-2.34.61.62-2.28-.14-.23a6.18 6.18 0 0 1 9.6-7.65 6.12 6.12 0 0 1 1.81 4.37A6.19 6.19 0 0 1 8 14.12z"/></svg>' +
        h(g("label_wa_btn")) +
        "</div>";
    }

    html += "</div>";
    container.innerHTML = html;
  }

  setTimeout(buildPreview, 300);
});
