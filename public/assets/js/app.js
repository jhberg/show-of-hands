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

  // The poll page's refresh waits while nobody sees the page, and while the member is choosing in
  // a field, such as whom to give their vote to, because the fresh <main> would reset the choice.
  // The member may start choosing while a refresh is under way, so its answer checks again.
  function choosing(main) {
    var active = document.activeElement;
    return active !== null && main.contains(active) && active.matches('input, select, textarea') ||
      Array.prototype.some.call(main.querySelectorAll('select'), function (select) { return select.value !== ''; });
  }

  document.addEventListener('htmx:confirm', function (event) {
    var poller = event.target;
    if (!poller.hasAttribute('data-live')) return;
    if (document.hidden || choosing(poller.closest('main'))) event.preventDefault();
  });

  // A refresh that brings nothing new leaves <main> alone, so nothing flickers and a selection stays.
  // htmx names the requesting element only in requestConfig: this event's target is <main>.
  document.addEventListener('htmx:beforeSwap', function (event) {
    if (!event.detail.requestConfig.elt.hasAttribute('data-live')) return;
    var main = event.detail.target;
    var fresh = document.createElement('template');
    fresh.innerHTML = event.detail.serverResponse;
    // htmx marks the refresh's own element while it runs; the fresh copy has no such mark.
    var current = main.cloneNode(true);
    current.querySelector('[data-live]').removeAttribute('class');
    if (choosing(main) || current.isEqualNode(fresh.content.firstElementChild)) event.detail.shouldSwap = false;
  });
})();
