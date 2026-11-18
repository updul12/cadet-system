/**
 * Cadet Unit MS — global UI behaviour
 *  · Bootstrap toasts for flash messages
 *  · data-confirm forms
 *  · Bootstrap validation helper
 *  · Submit spinners (anti double-submit)
 *  · Empty-state rows for tables
 */
(function () {
  'use strict';

  var TOAST_MS = 5000;

  /* ---- Convert flash alerts into Bootstrap toasts ----------------- */
  function showToast(type, message) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Toast) return false;
    var map = {
      success: 'text-bg-success',
      danger:  'text-bg-danger',
      warning: 'text-bg-warning',
      info:    'text-bg-info',
      primary: 'text-bg-primary'
    };
    var cls = map[type] || 'text-bg-secondary';
    var icons = {
      success: 'bi-check-circle-fill',
      danger:  'bi-exclamation-triangle-fill',
      warning: 'bi-exclamation-circle-fill',
      info:    'bi-info-circle-fill'
    };
    var icon = icons[type] || 'bi-bell-fill';

    var host = document.getElementById('toastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'toastHost';
      host.className = 'toast-container position-fixed top-0 end-0 p-3';
      host.style.zIndex = '1090';
      document.body.appendChild(host);
    }

    var el = document.createElement('div');
    el.className = 'toast align-items-center border-0 ' + cls;
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML =
      '<div class="d-flex">' +
        '<div class="toast-body"><i class="bi ' + icon + ' me-2"></i>' +
          String(message).replace(/[<>&]/g, function (c) {
            return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
          }) +
        '</div>' +
        '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>';
    host.appendChild(el);
    bootstrap.Toast.getOrCreateInstance(el, { delay: TOAST_MS }).show();
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    return true;
  }

  function promoteAlertsToToasts() {
    /* Flash messages only — marked data-toast by the layout.
       Inline form-validation alerts stay in place. */
    document.querySelectorAll('.alert-dismissible[data-toast]').forEach(function (el) {
      var type = 'info';
      var m = (el.className || '').match(/alert-(success|danger|warning|info|primary)/);
      if (m) type = m[1];
      var msg = (el.textContent || '').replace(/\s+/g, ' ').trim();
      el.remove();
      if (msg) showToast(type, msg);
    });
  }

  /* ---- Confirm dialogs on destructive forms ---------------------- */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });

  /* ---- Bootstrap form validation --------------------------------- */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.classList.contains('needs-validation')) return;
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    }
    form.classList.add('was-validated');
  }, false);

  /* ---- Submit spinners: disable button + show spinner ------------ */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    /* Skip GET filter forms and confirmed-destructive double-clicks. */
    if ((form.method || 'get').toLowerCase() === 'get') return;
    if (form.dataset.spinner === 'off') return;

    var btn = form.querySelector('button[type="submit"]:not([data-no-spinner])');
    if (!btn || btn.disabled) return;
    if (btn.dataset.origHtml === undefined) {
      btn.dataset.origHtml = btn.innerHTML;
    }
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' +
      'Working…';
  });

  /* ---- Empty-state rows for data tables -------------------------- */
  function injectEmptyStates() {
    document.querySelectorAll('table tbody').forEach(function (tb) {
      var rows = tb.querySelectorAll('tr');
      if (rows.length > 0) return;
      /* Skip if a custom empty row was already rendered server-side. */
      var table = tb.closest('table');
      var msg = (table && table.getAttribute('data-empty')) || 'No records found yet.';
      var cols = (table && table.querySelectorAll('thead th').length) || 1;
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = cols;
      td.className = 'text-center text-muted py-4';
      td.innerHTML =
        '<div class="py-2">' +
          '<i class="bi bi-inbox fs-3 d-block mb-2 opacity-50"></i>' +
          '<span>' + msg.replace(/[<>&]/g, function (c) {
            return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
          }) + '</span>' +
        '</div>';
      tr.appendChild(td);
      tb.appendChild(tr);
    });
  }

  /* ---- Boot ------------------------------------------------------ */
  document.addEventListener('DOMContentLoaded', function () {
    promoteAlertsToToasts();
    injectEmptyStates();
  });
})();
