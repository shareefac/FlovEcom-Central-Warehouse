/* Central Warehouse staff screens: small conveniences only; every page works without them. Loaded as an external file (CSP: no inline script). */
(function () {
  'use strict';

  var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

  // The answer form of a website product: choosing "create a new product" opens "Details of the new product" (plan F199).
  var details = document.getElementById('new-product');
  if (details) {
    Array.prototype.forEach.call(document.querySelectorAll('input[type="radio"][name="action"]'), function (radio) {
      radio.addEventListener('change', function () {
        if (radio.checked && radio.value === 'new_item') {
          details.open = true;
        }
      });
    });
  }

  // A password field marked data-reveal gets a "Show" button, so a person on a phone can check what they typed. Its words come
  // from the page (data-show, data-hide, data-show-label, data-hide-label: Words::SIGN_IN), English if a page has none. The
  // button goes after the field's <label>, so the field's name stays the label's words.
  Array.prototype.forEach.call(document.querySelectorAll('input[type="password"][data-reveal]'), function (input) {
    var word = function (name, fallback) { return input.getAttribute('data-' + name) || fallback; };
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'reveal';
    b.textContent = word('show', 'Show');
    b.setAttribute('aria-pressed', 'false');
    b.setAttribute('aria-label', word('show-label', 'Show the password'));
    b.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.textContent = show ? word('hide', 'Hide') : word('show', 'Show');
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? word('hide-label', 'Hide the password') : word('show-label', 'Show the password'));
    });
    var label = input.closest ? input.closest('label') : null;
    (label || input).insertAdjacentElement('afterend', b);
    if (input.form) {
      input.form.addEventListener('submit', function () { input.type = 'password'; });
    }
  });

  // Unsaved edits (the receive editor, the bench check): another form on the page (attach, import, copy, cancel) leaves the page,
  // so it asks first instead of throwing the typed edits away. The words come from the page (data-unsaved-text on the edited form,
  // data-busy-text on the leaving one: Words::RECEIPT), English if a page has none.
  var guarded = document.querySelectorAll('form[data-unsaved]');
  each(guarded, function (form) {
    var mark = function () { form.setAttribute('data-dirty', '1'); };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', function () { form.removeAttribute('data-dirty'); });
  });
  each(document.querySelectorAll('form[data-leaves]'), function (form) {
    form.addEventListener('submit', function (ev) {
      var dirty = document.querySelector('form[data-unsaved][data-dirty]');
      if (form.getAttribute('data-busy') === '1') {
        ev.preventDefault();
        window.alert(form.getAttribute('data-busy-text') || 'The photo is still being made smaller: wait a moment, then press the button again.');
        return;
      }
      if (dirty && !window.confirm(dirty.getAttribute('data-unsaved-text') || 'You have changes on this page that are not saved. They are lost if you go on. '
          + 'Press Cancel, then save them first. (OK goes on without them.)')) {
        ev.preventDefault();
      }
    });
  });

  // A form that changes something is sent once: the button is disabled on the first submit.
  each(document.querySelectorAll('form[method="post"]'), function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) {
        return;
      }
      var buttons = form.querySelectorAll('button[type="submit"]');
      window.setTimeout(function () {
        each(buttons, function (b) { b.disabled = true; });
      }, 0);
    });
  });

  // Coming back with the back button must not leave a disabled button behind.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      each(document.querySelectorAll('button[type="submit"]'), function (b) { b.disabled = false; });
    }
  });

  // A file input with a size limit: a camera photo larger than the limit is made smaller in the browser (JPEG, at most 2400 px),
  // anything else too large is refused here instead of by the server after the upload (its words: data-too-big, English if none).
  each(document.querySelectorAll('input[type="file"][data-max-bytes]'), function (input) {
    var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 2097152;
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file || file.size <= max) {
        return;
      }
      var tooBig = function () {
        window.alert(input.getAttribute('data-too-big') || ('This file is too big: at most ' + (max / 1048576).toFixed(0) + ' MB. Choose a smaller one.'));
        input.value = '';
      };
      // createImageBitmap decodes the file without a blob: or data: URL (the CSP allows images from this site only).
      if (!/^image\/(jpeg|png)$/.test(file.type) || typeof window.DataTransfer !== 'function' || typeof window.createImageBitmap !== 'function') {
        tooBig();
        return;
      }
      var form = input.form;
      if (form) { form.setAttribute('data-busy', '1'); }
      var done = function () { if (form) { form.removeAttribute('data-busy'); } };
      window.createImageBitmap(file).then(function (img) {
        var scale = Math.min(1, 2400 / Math.max(img.width, img.height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        var quality = 0.85;
        var tryOnce = function () {
          canvas.toBlob(function (blob) {
            if (blob && blob.size > max && quality > 0.45) {
              quality -= 0.15;
              tryOnce();
              return;
            }
            done();
            if (!blob || blob.size > max) {
              tooBig();
              return;
            }
            var dt = new window.DataTransfer();
            dt.items.add(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
            input.files = dt.files;
          }, 'image/jpeg', quality);
        };
        tryOnce();
      }, function () {
        done();
        tooBig();
      });
    });
  });

  // A scanner ends with Enter: in a field marked data-enter="next" (the bench's stamp code) it moves on instead of saving the page.
  each(document.querySelectorAll('[data-enter="next"]'), function (field) {
    field.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') {
        return;
      }
      ev.preventDefault();
      var fields = Array.prototype.filter.call(field.form ? field.form.elements : [], function (el) {
        return !el.disabled && el.type !== 'hidden' && typeof el.focus === 'function' && el.offsetParent !== null;
      });
      var i = fields.indexOf(field);
      if (i >= 0 && fields[i + 1]) { fields[i + 1].focus(); }
    });
  });

  // The bench: find a line by scanning its barcode (or typing a code): it is highlighted and its "checked" box focused. "Not on this
  // page" comes from the field's data-not-here (Words::BENCH, %s = what was scanned).
  var find = document.getElementById('bench-find');
  if (find) {
    var result = document.getElementById('bench-find-result');
    var norm = function (v) { v = String(v || '').trim().toLowerCase(); return /^[0-9]+$/.test(v) ? v.replace(/^0+/, '') : v; };
    find.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') {
        return;
      }
      ev.preventDefault();
      var want = norm(find.value);
      if (want === '') {
        return;
      }
      var hit = null;
      each(document.querySelectorAll(find.getAttribute('data-find-in') || '.bench-line'), function (card) {
        card.classList.remove('found');
        if (!hit && (card.getAttribute('data-codes') || '').split(/\s+/).map(norm).indexOf(want) >= 0) {
          hit = card;
        }
      });
      if (hit) {
        hit.classList.add('found');
        hit.scrollIntoView({ block: 'center' });
        var box = hit.querySelector('input[type="checkbox"]');
        if (box) { box.focus(); }
        if (result) { result.textContent = (hit.querySelector('h2') || hit).textContent.trim(); }
      } else if (result) {
        result.textContent = (find.getAttribute('data-not-here') || 'Not on this page: %s').replace('%s', find.value.trim());
      }
      find.value = '';
    });
  }

  // The bench's fill buttons (they only fill the form; nothing is saved until "Save the check").
  each(document.querySelectorAll('[data-fill-stamps]'), function (b) {
    b.addEventListener('click', function () {
      each(document.querySelectorAll('select[data-stamp]'), function (s) {
        if (s.value === '') { s.value = '1'; s.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      each(document.querySelectorAll('select[data-stamp-type]'), function (s) {
        if (s.value === '') { s.value = 'digital'; s.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  });
  each(document.querySelectorAll('[data-tick-all]'), function (b) {
    b.addEventListener('click', function () {
      each(document.querySelectorAll('article.bench-line input[type="checkbox"][name$="_ok"]'), function (c) {
        if (!c.checked) { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  });

  // The bench: what happens to unstamped units is asked only when some are unstamped.
  each(document.querySelectorAll('article.bench-line'), function (card) {
    var count = card.querySelector('input[data-unstamped]');
    var more = card.querySelector('[data-unstamped-only]');
    if (!count || !more) {
      return;
    }
    var show = function () {
      var v = parseInt(count.value, 10);
      var stamp = card.querySelector('select[data-stamp]');
      more.hidden = !(v > 0) && !(stamp && stamp.value === '0');
    };
    count.addEventListener('input', show);
    card.addEventListener('change', show);
    show();
  });

  // New delivery: "receive all as ordered" follows the purchase order chosen.
  each(document.querySelectorAll('select[data-ticks]'), function (s) {
    var box = document.getElementById(s.getAttribute('data-ticks'));
    if (!box) {
      return;
    }
    s.addEventListener('change', function () { box.checked = s.value !== ''; });
  });
})();
