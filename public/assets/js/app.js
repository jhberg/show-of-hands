// Toasts: the server renders one into #toast, or an htmx response names one in HX-Trigger.
(function () {
  'use strict';
  var timer;

  function hideLater(toast) {
    clearTimeout(timer);
    timer = setTimeout(function () { toast.classList.remove('on'); }, 3000);
  }

  function show(message) {
    var toast = document.getElementById('toast');
    if (!toast || !message) return;
    toast.textContent = message;
    toast.classList.add('on');
    hideLater(toast);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var toast = document.getElementById('toast');
    if (toast && toast.classList.contains('on')) hideLater(toast);
  });

  document.body.addEventListener('toast', function (event) {
    show(event.detail && event.detail.value);
  });
})();
