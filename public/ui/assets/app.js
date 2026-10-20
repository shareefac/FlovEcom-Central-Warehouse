/* Central Warehouse staff screens: small conveniences only; every page works without them. Loaded as an external file (CSP: no inline script). */
(function () {
  'use strict';

  var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

  // The script runs: the CSS draws what only works with it (the boards' fold chevrons).
  document.documentElement.classList.add('js');

  // A board's group title folds its rows (design v4); without the script every group stays open.
  each(document.querySelectorAll('button.grp-toggle[aria-controls]'), function (b) {
    b.addEventListener('click', function () {
      var target = document.getElementById(b.getAttribute('aria-controls'));
      if (!target) {
        return;
      }
      var open = b.getAttribute('aria-expanded') !== 'false';
      b.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (target.tagName === 'TBODY') {
        // A group inside one table keeps its title row: only its other rows fold.
        target.classList.toggle('is-collapsed', open);
      } else {
        target.hidden = open;
      }
    });
  });

  // Bulk action on a list (docs/decisions.md U108): the rows' tick boxes, "Select all on this page" (a link that also works
  // without the script: ?all=1 draws the page ticked), and the action bar, shown only while rows are ticked and kept at the bottom
  // of the screen. Its words come from the page (data-none, data-one, data-many, data-too-many: Words::BULK). Without the script
  // the bar is a plain row of buttons under the list. A row's box marked data-bulk-manual is ticked by hand only: "select all"
  // (the header box and the link) leaves it alone (By Item, docs/decisions.md M59).
  each(document.querySelectorAll('form[data-bulk]'), function (form) {
    var bar = form.querySelector('[data-bulkbar]');
    if (!bar) {
      return;
    }
    var boxes = form.querySelectorAll('input[type="checkbox"][data-bulk-row]');
    var all = form.querySelector('input[data-bulk-all]');
    var count = bar.querySelector('[data-bulk-count]');
    var big = bar.querySelector('[data-bulk-n]');
    var clear = bar.querySelector('[data-bulk-clear]');
    var max = parseInt(bar.getAttribute('data-max'), 10) || 0;
    var word = function (name) { return bar.getAttribute('data-' + name) || ''; };
    var update = function () {
      var n = 0;
      each(boxes, function (b) {
        var row = b.closest ? b.closest('tr') : null;
        if (row) { row.classList.toggle('picked', b.checked); }
        if (b.checked) { n++; }
      });
      bar.hidden = n === 0;
      bar.classList.toggle('over', max > 0 && n > max);
      if (big) { big.textContent = n > 0 ? String(n) : ''; }
      if (count) {
        count.textContent = n === 0 ? word('none') : (max > 0 && n > max ? word('too-many').replace('%s', String(n)).replace('%s', String(max))
          : (n === 1 ? word('one') : word('many').replace('%s', String(n))));
      }
      if (all) {
        // The header box speaks for the rows "tick all" ticks: a row marked data-bulk-manual is ticked by hand only.
        var auto = 0;
        var autoOn = 0;
        each(boxes, function (b) {
          if (!b.hasAttribute('data-bulk-manual')) {
            auto++;
            if (b.checked) { autoOn++; }
          }
        });
        all.checked = autoOn > 0 && autoOn === auto;
        all.indeterminate = n > 0 && !all.checked;
      }
    };
    var setAll = function (on) {
      each(boxes, function (b) {
        if (on && b.hasAttribute('data-bulk-manual')) { return; }
        b.checked = on;
      });
      update();
    };
    bar.classList.add('floating');
    if (all) {
      all.hidden = false;
      all.addEventListener('change', function () { setAll(all.checked); });
    }
    each(document.querySelectorAll('[data-bulk-all-link]'), function (a) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        setAll(true);
      });
    });
    if (clear) {
      clear.hidden = false;
      clear.addEventListener('click', function () { setAll(false); });
    }
    each(boxes, function (b) { b.addEventListener('change', update); });
    update();
  });

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

  // Pack PO (docs/decisions.md PO2): a search box over a long list of choices (the suppliers of an order). Without the script it stays
  // hidden and the list works as it is (a select is searched by typing its first letters). Typing shows only the choices whose words
  // hold what was typed; the placeholder choice and the one chosen always stay.
  each(document.querySelectorAll('input[data-filter-select]'), function (input) {
    var select = document.getElementById(input.getAttribute('data-filter-select'));
    if (!select) {
      return;
    }
    input.hidden = false;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      each(select.options, function (o) {
        o.hidden = q !== '' && o.value !== '' && !o.selected && o.text.toLowerCase().indexOf(q) === -1;
      });
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault(); // Enter in the search box chooses nothing and sends nothing
        var first = Array.prototype.filter.call(select.options, function (o) { return !o.hidden && o.value !== ''; })[0];
        if (first) { select.value = first.value; select.focus(); }
      }
    });
  });

  // A form that changes something is sent once: the button is disabled on the first submit.
  var sendOnce = function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) {
        return;
      }
      var buttons = form.querySelectorAll('button[type="submit"]');
      window.setTimeout(function () {
        each(buttons, function (b) { b.disabled = true; });
      }, 0);
    });
  };
  each(document.querySelectorAll('form[method="post"]'), sendOnce);

  // By Item's quick match (the owner's request of 20 Oct 2026: "directly add dropdown with full details"). "Match…" in a cell of a
  // store that has no variant for the item is a <details> with a search box. Without the script its Search opens the picker page (a
  // GET form after the list, which the box and its button name with form=: the list sits inside the bulk form). With it, opening the
  // fold and typing (250 ms after the last key; 2 letters or more, or an emptied box: every waiting variant, best sellers first, as
  // the picker's empty search) show the store's variants
  // in the cell: the read-only fragment at the address in data-options, a path of this site (the suggestions for the item first, then
  // the search), parsed inert by DOMParser (nothing in it runs) and shown only when its root says it is that fragment
  // (data-qp-fragment). Anything else (the sign-in page of a session that ended, an error page, no network) shows the page's words
  // for it (data-failed) beside the fold's way to the matching page: never a part of another page, never nothing. A row's "Match to …"
  // is a plain POST form of the variant's own decide route, so a refusal lands on the variant's page with its words, as from there.
  // In the document the rows sit inside the list's bulk form, but a control belongs to the form nearest it: neither sends the other's.
  each(document.querySelectorAll('details[data-quick-pick]'), function (box) {
    var base = box.getAttribute('data-options') || '';
    var out = box.querySelector('[data-qp-results]');
    var input = box.querySelector('input[data-qp-q]');
    if (base.indexOf('/ui/review/products/') !== 0 || base.indexOf('?') < 0 || !out || !input || !window.fetch || !window.DOMParser) {
      return;
    }
    var timer = 0;
    var asked = null;
    var turn = 0;
    var touched = false;
    var show = function (node) {
      while (out.firstChild) {
        out.removeChild(out.firstChild);
      }
      out.appendChild(node);
    };
    var line = function (text, cls) {
      var p = document.createElement('p');
      p.className = cls;
      p.textContent = text;
      return p;
    };
    // null: the plain opening (the suggestions; the item's own words when nothing is suggested); once the person typed, their words,
    // also none at all (`q=`: every waiting variant, as the picker's emptied search).
    var words = function () {
      return touched ? input.value.trim() : null;
    };
    var load = function (q) {
      var key = q === null ? '' : '&q=' + encodeURIComponent(q);
      if (key === asked) {
        return;
      }
      asked = key;
      var mine = ++turn;
      out.setAttribute('aria-busy', 'true');
      show(line(box.getAttribute('data-loading') || '', 'qp-wait muted'));
      window.fetch(base + key, { credentials: 'same-origin', method: 'GET', headers: { Accept: 'text/html' } })
        .then(function (r) {
          if (!r.ok || r.redirected) {
            throw new Error('not the variants');
          }
          return r.text();
        })
        .then(function (html) {
          if (mine !== turn) {
            return;
          }
          var doc = new window.DOMParser().parseFromString(html, 'text/html');
          var part = doc.querySelector('[data-qp-fragment]');
          if (!part) {
            throw new Error('not the variants');
          }
          var node = document.importNode(part, true);
          each(node.querySelectorAll('form[method="post"]'), sendOnce);
          show(node);
          out.removeAttribute('aria-busy');
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          asked = null; // the next try asks again
          var p = line(box.getAttribute('data-failed') || '', 'qp-error');
          p.setAttribute('role', 'alert');
          show(p);
          out.removeAttribute('aria-busy');
        });
    };
    box.addEventListener('toggle', function () {
      if (box.open) {
        load(words());
      }
    });
    input.addEventListener('input', function () {
      touched = true;
      window.clearTimeout(timer);
      var v = input.value.trim();
      if (v.length === 1) {
        return; // one letter finds too much: wait for the next
      }
      timer = window.setTimeout(function () { load(words()); }, 250);
    });
    if (input.form) {
      // Search (or Enter) with the script: the variants in the fold at once; the fold's link still opens the matching page.
      input.form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        touched = true;
        window.clearTimeout(timer);
        if (!box.open) {
          box.open = true;
        }
        load(words());
      });
    }
    if (box.open) {
      load(words());
    }
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

  // A count sheet's lines (form[data-mark], Stock > Counts): a quantity is saved when Enter is pressed in its box (the form's own
  // button; the server keeps only the lines changed on this page). The script marks a box typed in and not saved yet, with the
  // page's words (data-mark-text: Words::COUNTS); the guard above (data-unsaved) asks before the scan box or another form leaves.
  // It also writes the moment a box was first typed into, into its hidden at_<line> (review M-7: a page saved once after Tab from
  // box to box gave every line the moment Enter was pressed). The moment is the page's own drawn time (data-drawn, the server's clock,
  // milliseconds) plus how long ago the page was loaded here, so the tablet's own clock does not matter; the server refuses one after
  // now or before the line was on the sheet. A box put back to what it was drawn with loses its moment.
  var loaded = Date.now();
  each(document.querySelectorAll('form[data-mark]'), function (form) {
    var prefix = form.getAttribute('data-mark') || '';
    var drawn = parseInt(form.getAttribute('data-drawn') || '', 10);
    form.addEventListener('input', function (ev) {
      var input = ev.target;
      if (!input || !input.name || input.name.indexOf(prefix) !== 0) {
        return;
      }
      var line = input.name.slice(prefix.length);
      var was = form.elements['was_' + line];
      var changed = !was || was.value !== input.value.trim();
      var at = form.elements['at_' + line];
      if (at && !isNaN(drawn)) {
        if (!changed) {
          at.value = '';
        } else if (at.value === '') {
          at.value = String(drawn + Math.max(0, Date.now() - loaded));
        }
      }
      var state = input.parentNode ? input.parentNode.querySelector('.save-state') : null;
      input.classList.toggle('unsaved', changed);
      if (state) {
        if (!state.hasAttribute('data-was')) {
          state.setAttribute('data-was', state.textContent);
        }
        state.textContent = changed ? (form.getAttribute('data-mark-text') || '') : state.getAttribute('data-was');
        state.classList.toggle('unsaved', changed);
      }
    });
  });

  // A link to a folded form (the toolbar's "New user", "New warehouse", "New reason" lead to #new): the fold opens.
  var openTarget = function () {
    var id = window.location.hash.slice(1);
    var el = id !== '' ? document.getElementById(id) : null;
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
    }
  };

  // The create drawers (design v5: a list page's "New ..." form, details.drawer): from 700 px the open fold is a panel on the right
  // (CSS). Here: Esc, a click on the dimmed page and the panel's header close it; Tab stays inside it; focus goes to its first field
  // and, when it closes, back to what opened it. On a phone it stays an ordinary fold. A fold the server sent open (a refused form,
  // whose error is above it; an empty list) stays a fold in the page (is-inline) until it is closed and opened again.
  var wide = window.matchMedia ? window.matchMedia('(min-width: 700px)') : null;
  var isWide = function () { return !!wide && wide.matches; };
  var focusable = function (root) {
    return Array.prototype.filter.call(root.querySelectorAll('summary, a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])'),
      function (el) { return el.offsetParent !== null || el.tagName === 'SUMMARY'; });
  };
  // One drawer at a time is the modal one: the last opened (a second one would cover it).
  var openDrawer = function () {
    var all = document.querySelectorAll('details.drawer[open]:not(.is-inline)');
    return all.length > 0 ? all[all.length - 1] : null;
  };
  var drawers = [];
  var linkOpener = null; // the "New ..." link that opened a fold (following it moves the focus to the page first)
  each(document.querySelectorAll('details.drawer'), function (d) {
    var opener = null;
    // The page column is a CSS container (its lists turn into cards), and a container holds what is fixed inside it under the dimmed
    // layer: an open drawer goes to the end of <body>, and back to its place in the page when it closes or the screen narrows.
    var home = document.createComment('drawer');
    d.parentNode.insertBefore(home, d);
    var move = function (into) {
      var focused = d.contains(document.activeElement) ? document.activeElement : null;
      into();
      if (focused) { focused.focus(); }
    };
    var lift = function () {
      if (d.parentNode !== document.body) { move(function () { document.body.appendChild(d); }); }
    };
    var settle = function () {
      if (home.parentNode && d.previousSibling !== home) { move(function () { home.parentNode.insertBefore(d, home.nextSibling); }); }
    };
    // It becomes the modal drawer: any other open one goes back into the page as a fold, it is lifted, and the focus goes inside
    // (what had it, outside, gets it back when the drawer closes).
    var enter = function () {
      drawers.forEach(function (o) {
        if (o.d !== d && o.d.open && !o.d.classList.contains('is-inline')) { o.d.classList.add('is-inline'); o.settle(); }
      });
      var was = document.activeElement;
      if (was && was !== document.body && !d.contains(was)) {
        opener = was;
      } else if (linkOpener && !d.contains(linkOpener)) {
        opener = linkOpener;
      }
      linkOpener = null;
      lift();
      if (!d.contains(document.activeElement)) {
        var first = focusable(d).filter(function (el) { return el.tagName !== 'SUMMARY'; })[0];
        (first || d.querySelector('summary')).focus();
      }
    };
    // Where an open drawer belongs now: on <body> from 700 px, in the page below that (the breakpoint, both ways).
    var place = function () {
      if (d.open && !d.classList.contains('is-inline') && isWide()) {
        if (d.parentNode !== document.body) { enter(); }
      } else {
        settle();
      }
    };
    if (d.open) {
      d.classList.add('is-inline');
    }
    d.addEventListener('toggle', function () {
      if (!d.open) {
        var wasDrawer = d.parentNode === document.body;
        d.classList.remove('is-inline');
        settle();
        if (!wasDrawer) {
          return;
        }
        if (opener && document.contains(opener)) {
          opener.focus();
        }
        if (!opener || document.activeElement !== opener) {
          d.querySelector('summary').focus(); // what opened it is gone or hidden: the fold's own header
        }
        opener = null;
        return;
      }
      if (!d.classList.contains('is-inline') && isWide()) {
        enter();
      }
    });
    drawers.push({ d: d, place: place, settle: settle });
  });
  if (wide) {
    var follow = function () { drawers.forEach(function (o) { o.place(); }); };
    if (wide.addEventListener) { wide.addEventListener('change', follow); } else if (wide.addListener) { wide.addListener(follow); }
    window.addEventListener('resize', follow); // the same again on a resize (cheap: place() only moves what is in the wrong place)
  }
  // A link to a fold of this page (the toolbar's "New ..." leads to #new) opens it, also when the address already ends in #new and no
  // hashchange comes (after the drawer was closed with Esc or the dimmed page).
  document.addEventListener('click', function (ev) {
    // Only a plain click that stays in this tab (not Ctrl, Cmd, Shift or Alt, not another button, not a new tab or a download).
    if (ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) {
      return;
    }
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href*="#"]') : null;
    if (!a || a.hasAttribute('download') || (a.target !== '' && a.target !== '_self')) {
      return;
    }
    var url = new URL(a.getAttribute('href'), window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.search !== window.location.search || url.hash.length < 2) {
      return;
    }
    var el = document.getElementById(decodeURIComponent(url.hash.slice(1)));
    if (el && el.tagName === 'DETAILS' && !el.open) {
      // The link is what a drawer gives the focus back to: only for a drawer, and only a link outside it.
      linkOpener = el.classList.contains('drawer') && !el.contains(a) ? a : null;
      el.open = true;
    }
  });
  document.addEventListener('keydown', function (ev) {
    var d = openDrawer();
    if (!d || !isWide()) {
      return;
    }
    if (ev.key === 'Escape') {
      ev.preventDefault();
      d.open = false;
      return;
    }
    if (ev.key === 'Tab') {
      var list = focusable(d);
      if (list.length === 0) {
        return;
      }
      var i = list.indexOf(document.activeElement);
      if (ev.shiftKey && (i <= 0)) {
        ev.preventDefault();
        list[list.length - 1].focus();
      } else if (!ev.shiftKey && (i === -1 || i === list.length - 1)) {
        ev.preventDefault();
        list[0].focus();
      }
    }
  });
  // The dimmed page is the body's own layer: a click on it lands on <body>.
  document.addEventListener('click', function (ev) {
    var d = openDrawer();
    if (d && isWide() && ev.target === document.body) {
      d.open = false;
    }
  });
  openTarget();
  window.addEventListener('hashchange', openTarget);

  // The small menus (the person's menu, Filter and Sort, the "New" button's list): Esc or a click elsewhere closes the open one.
  var menus = 'details.user-menu[open], details.tb-pop[open], details.split-more[open]';
  document.addEventListener('click', function (ev) {
    each(document.querySelectorAll(menus), function (m) {
      if (!m.contains(ev.target)) { m.open = false; }
    });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') {
      return;
    }
    each(document.querySelectorAll(menus), function (m) {
      m.open = false;
      var s = m.querySelector('summary');
      if (s) { s.focus(); }
    });
  });

  // The message after a change (a toast at the bottom right) fades after a while; a click on it fades it at once. It stays for a
  // person who moves into it with the keyboard or the mouse.
  each(document.querySelectorAll('p.toast'), function (t) {
    var held = false;
    var gone = function () { if (!held) { t.classList.add('is-gone'); } };
    t.addEventListener('mouseenter', function () { held = true; });
    t.addEventListener('mouseleave', function () { held = false; window.setTimeout(gone, 3000); });
    t.addEventListener('click', function () { held = false; gone(); });
    window.setTimeout(gone, 8000);
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
