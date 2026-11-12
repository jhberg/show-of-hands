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

  // A request that fails (no network, or the server answers an error htmx does not swap in)
  // leaves the page as it was, so the toast asks the member to tap again. A 403 never gets here:
  // the server answers it with HX-Refresh. The poll page's refresh fails quietly and tries again.
  function failed(event) {
    var config = event.detail.requestConfig;
    var toast = document.getElementById('toast');
    if (!toast || config && config.elt.hasAttribute('data-live')) return;
    show(toast.getAttribute(config && config.verb === 'get' ? 'data-not-loaded' : 'data-not-saved'));
  }

  document.addEventListener('htmx:responseError', failed);
  document.addEventListener('htmx:sendError', failed);

  // The poll form's labels and placeholders follow the chosen kind. The server renders them for the
  // kind it knows; the other kind's words wait in data attributes. CSS shows the question field.
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
  // A refused field counts as choosing, so the refresh keeps its message until the member acts.
  // The member may start choosing while a refresh is under way, so its answer checks again.
  function choosing(main) {
    var active = document.activeElement;
    return active !== null && main.contains(active) && active.matches('input, select, textarea') ||
      main.querySelector('[aria-invalid=true]') !== null ||
      Array.prototype.some.call(main.querySelectorAll('select'), function (select) { return select.value !== ''; });
  }

  document.addEventListener('htmx:confirm', function (event) {
    var poller = event.target;
    if (!poller.hasAttribute('data-live')) return;
    if (document.hidden || choosing(poller.closest('main'))) event.preventDefault();
  });

  // Cancelling a panel or a confirmation, and actions such as delegating or closing, swap out the
  // control that had the focus. data-focus names where a keyboard user carries on: the control that
  // opened the panel, or what the action brought, such as the result's heading.
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

  // The phone menu and the colour mode panel are <details>, so they open without JavaScript. With
  // it, a tap beside one, Escape, or the focus moving on closes it again, and Escape returns the
  // focus to its button. An open panel would otherwise cover the control the focus moved to.
  document.addEventListener('click', function (event) {
    var open = document.querySelector('details.pop[open]');
    if (open && !(event.target instanceof Node && open.contains(event.target))) open.removeAttribute('open');
  });

  document.addEventListener('keydown', function (event) {
    var open = document.querySelector('details.pop[open]');
    if (!open || event.key !== 'Escape') return;
    open.removeAttribute('open');
    open.querySelector('summary').focus();
  });

  // Only a focus that lands outside closes it: Safari focuses nothing on a tap, and a tap on a
  // link inside the panel must still reach the link.
  document.addEventListener('focusout', function (event) {
    var open = event.target instanceof Element ? event.target.closest('details.pop[open]') : null;
    var next = event.relatedTarget;
    if (open && next instanceof Node && !open.contains(next)) open.removeAttribute('open');
  });
})();
