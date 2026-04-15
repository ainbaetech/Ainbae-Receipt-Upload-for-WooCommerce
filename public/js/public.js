document.addEventListener("DOMContentLoaded", function () {
  var input = document.getElementById("ainbae_bacs_receipt_file");
  var zone = document.getElementById("ainbae-bacs-dropzone");
  var label = document.getElementById("ainbae-bacs-file-name");

  if (!input || !zone || !label) return;

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
});
