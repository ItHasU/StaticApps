(function () {
  "use strict";

  var dropzone = document.getElementById("dropzone");
  var fileInput = document.getElementById("app_zip");
  var dropzoneLabel = document.getElementById("dropzone-label");

  if (dropzone && fileInput) {
    ["dragenter", "dragover"].forEach(function (eventName) {
      dropzone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropzone.classList.add("dropzone-active");
      });
    });

    ["dragleave", "drop"].forEach(function (eventName) {
      dropzone.addEventListener(eventName, function (event) {
        event.preventDefault();
        dropzone.classList.remove("dropzone-active");
      });
    });

    dropzone.addEventListener("drop", function (event) {
      var files = event.dataTransfer && event.dataTransfer.files;
      if (files && files.length > 0) {
        fileInput.files = files;
        updateDropzoneLabel(files[0].name);
      }
    });

    fileInput.addEventListener("change", function () {
      if (fileInput.files.length > 0) {
        updateDropzoneLabel(fileInput.files[0].name);
      }
    });
  }

  function updateDropzoneLabel(filename) {
    if (dropzoneLabel) {
      dropzoneLabel.textContent = "Fichier sélectionné : " + filename;
    }
  }

  document.querySelectorAll(".delete-form").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      var confirmed = window.confirm(
        "Supprimer définitivement cette application ? Cette action est irréversible."
      );
      if (!confirmed) {
        event.preventDefault();
        return;
      }
      var confirmField = form.querySelector(".confirm-field");
      if (confirmField) {
        confirmField.value = "1";
      }
    });
  });
})();
