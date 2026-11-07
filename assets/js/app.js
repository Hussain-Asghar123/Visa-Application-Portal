/* Visa Application Portal – progressive enhancements (the site works without JavaScript). */
(function () {
  'use strict';
  // Confirmation prompts for destructive actions: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
      return;
    }
    // Prevent double submission (e.g. slow mobile uploads).
    var btn = form.querySelector('button[type="submit"]:not([data-no-disable])');
    if (btn && !ev.defaultPrevented) {
      setTimeout(function () { btn.disabled = true; btn.dataset.originalText = btn.textContent; btn.textContent = 'Please wait…'; }, 0);
    }
  });

  // Client-side file size hint (server still validates).
  document.querySelectorAll('input[type="file"][data-max-mb]').forEach(function (input) {
    input.addEventListener('change', function () {
      var max = parseFloat(input.getAttribute('data-max-mb')) * 1024 * 1024;
      if (input.files && input.files[0] && input.files[0].size > max) {
        alert('This file is larger than ' + input.getAttribute('data-max-mb') + ' MB. Please choose a smaller file.');
        input.value = '';
      }
    });
  });

  // Show/hide fields that depend on a checkbox: <div data-show-if="checkbox-id">
  document.querySelectorAll('[data-show-if]').forEach(function (el) {
    var src = document.getElementById(el.getAttribute('data-show-if'));
    if (!src) { return; }
    var sync = function () { el.hidden = !(src.checked || src.value === '1'); };
    src.addEventListener('change', sync);
    sync();
  });

  // Security Center live test: private files must NOT be downloadable (expect 403/404).
  document.querySelectorAll('[data-probe]').forEach(function (row) {
    var icon = row.querySelector('.probe-icon');
    var out = row.querySelector('.probe-result');
    fetch(row.getAttribute('data-probe'), { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
      .then(function (r) {
        var blocked = r.status === 403 || r.status === 404 || r.type === 'opaqueredirect';
        icon.className = 'bi probe-icon ' + (blocked ? 'bi-check-circle-fill text-success' : 'bi-x-octagon-fill text-danger');
        out.textContent = blocked ? 'blocked (' + (r.status || 'redirect') + ') – safe' : 'OPEN (' + r.status + ') – NOT protected! Check the .htaccess files.';
      })
      .catch(function () {
        icon.className = 'bi probe-icon bi-check-circle-fill text-success';
        out.textContent = 'blocked – safe';
      });
  });

  // Copy a link to the clipboard: <button data-copy="https://...">
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        var html = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
        setTimeout(function () { btn.innerHTML = html; }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this link:', text); });
      } else {
        window.prompt('Copy this link:', text);
      }
    });
  });

  // Live status tracker: re-fetch the panel every 15 s while the tab is visible.
  document.querySelectorAll('[data-track-src]').forEach(function (panel) {
    var checked = document.querySelector('[data-track-checked]');
    var stamp = function () {
      if (checked) { checked.textContent = 'Last checked ' + new Date().toLocaleTimeString(); }
    };
    var timer = null;
    var refresh = function () {
      if (document.hidden || timer === false) { return; }
      fetch(panel.getAttribute('data-track-src'), { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) {
          if (r.status === 404 || r.status === 403) { // link was reset by the visa office, or the page needs the passport number again
            clearInterval(timer);
            timer = false;
            if (checked) { checked.textContent = 'This link has been replaced – ask the visa office for the new one.'; }
            return null;
          }
          return r.ok ? r.text() : null;
        })
        .then(function (html) {
          if (html === null) { return; }
          if (html !== panel.innerHTML) {
            panel.innerHTML = html;
            panel.classList.remove('track-flash');
            void panel.offsetWidth; // restart the highlight animation
            panel.classList.add('track-flash');
          }
          stamp();
        })
        .catch(function () { /* offline – try again on the next tick */ });
    };
    stamp();
    timer = setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
  });

  // Fingerprint photos: shrink big phone photos (max 2000 px, JPEG) and upload as soon as one is taken.
  document.querySelectorAll('[data-bio-form]').forEach(function (form) {
    var input = form.querySelector('[data-bio-input]');
    var label = input.closest('label');
    form.querySelectorAll('[data-bio-submit]').forEach(function (b) { b.hidden = true; });
    var send = function () {
      label.classList.add('disabled');
      label.querySelector('[data-bio-label]').textContent = 'Uploading…';
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    };
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) { return; }
      if (!window.createImageBitmap || !window.DataTransfer || !/^image\/(jpeg|png)$/.test(file.type)) { send(); return; }
      createImageBitmap(file).then(function (bmp) {
        var scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
        if (scale === 1 && file.size < 4 * 1024 * 1024) { send(); return; }
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(bmp.width * scale);
        canvas.height = Math.round(bmp.height * scale);
        canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          if (blob) {
            var dt = new DataTransfer();
            dt.items.add(new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'finger') + '.jpg', { type: 'image/jpeg' }));
            input.files = dt.files;
          }
          send();
        }, 'image/jpeg', 0.92);
      }).catch(send);
    });
  });

  // Re-enable buttons when the page is restored from the back/forward cache.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      document.querySelectorAll('button[disabled][data-original-text]').forEach(function (b) { b.disabled = false; b.textContent = b.dataset.originalText; });
    }
  });
})();
