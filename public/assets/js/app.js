// Toasts: the server renders one into #toast, or an htmx response names one in HX-Trigger.
// The poll page's refresh, the poll form's kind switch, the copy button of the poll link, and the
// closing of the phone menu live here too.
(function () {
  'use strict';
  var timer;

  document.documentElement.classList.add('js');

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

  // The poll form's labels, placeholders, and question field follow the chosen kind. The server
  // renders them for the kind it knows; the other kind's words wait in data attributes.
  document.addEventListener('change', function (event) {
    var radio = event.target;
    if (!(radio instanceof HTMLInputElement) || radio.name !== 'kind' || !radio.form || radio.form.id !== 'poll-form') return;
    var kind = radio.value;
    radio.form.querySelectorAll('[data-' + kind + ']').forEach(function (el) {
      el.textContent = el.getAttribute('data-' + kind);
    });
    radio.form.querySelectorAll('[data-placeholder-' + kind + ']').forEach(function (el) {
      el.setAttribute('placeholder', el.getAttribute('data-placeholder-' + kind));
    });
    radio.form.querySelectorAll('[data-kind-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-kind-only') !== kind;
    });
  });

  // Copies the poll link. Where the browser refuses, the link is selected for copying by hand.
  document.addEventListener('click', function (event) {
    var button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;
    if (!button) return;
    var source = document.getElementById(button.getAttribute('data-copy'));
    if (!source) return;
    var selectByHand = function () {
      var range = document.createRange();
      range.selectNodeContents(source);
      var selection = window.getSelection();
      if (selection) {
        selection.removeAllRanges();
        selection.addRange(range);
      }
      show(button.getAttribute('data-copy-failed'));
    };
    if (!navigator.clipboard || !navigator.clipboard.writeText) {
      selectByHand();
      return;
    }
    navigator.clipboard.writeText(source.textContent.trim()).then(function () {
      show(button.getAttribute('data-copied'));
    }, selectByHand);
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

  // Cancelling a panel or a confirmation swaps out the control that had the focus. data-focus
  // names the control that opened it, so a keyboard user carries on from there.
  document.addEventListener('htmx:afterSettle', function (event) {
    var config = event.detail.requestConfig;
    var id = config && config.elt.getAttribute('data-focus');
    var target = id && document.getElementById(id);
    if (target) target.focus();
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

  // The phone menu is a <details>, so it opens without JavaScript. With it, a tap beside the menu
  // or Escape closes it again, and Escape returns the focus to the menu button.
  document.addEventListener('click', function (event) {
    var open = document.querySelector('details.menu[open]');
    if (open && !(event.target instanceof Node && open.contains(event.target))) open.removeAttribute('open');
  });

  document.addEventListener('keydown', function (event) {
    var open = document.querySelector('details.menu[open]');
    if (!open || event.key !== 'Escape') return;
    open.removeAttribute('open');
    open.querySelector('summary').focus();
  });
})();
