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

  // A section's tabs scroll sideways inside their strip when they do not fit (a phone, a narrow window). The open tab is scrolled
  // into sight (the strip only: the page itself never moves), and the strip is marked with the side it can still scroll to
  // (more-left, more-right), which the stylesheet fades, so the tabs off the screen have a cue. Without the script the strip still
  // scrolls; it only has no fade.
  each(document.querySelectorAll('nav.tabs'), function (strip) {
    var mark = function () {
      var max = strip.scrollWidth - strip.clientWidth;
      strip.classList.toggle('more-left', max > 1 && strip.scrollLeft > 1);
      strip.classList.toggle('more-right', max > 1 && strip.scrollLeft < max - 1);
    };
    var reveal = function () {
      var open = strip.querySelector('[aria-current]');
      if (!open || strip.scrollWidth <= strip.clientWidth) {
        return;
      }
      // The strip is the tabs' offsetParent (position: relative), so offsetLeft is the tab's place in it.
      if (open.offsetLeft < strip.scrollLeft || open.offsetLeft + open.offsetWidth > strip.scrollLeft + strip.clientWidth) {
        strip.scrollLeft = Math.max(0, open.offsetLeft - (strip.clientWidth - open.offsetWidth) / 2);
      }
    };
    reveal();
    mark();
    strip.addEventListener('scroll', mark, { passive: true });
    // A narrower window keeps the open tab in sight; a phone's address bar showing or hiding (a resize of the height only) leaves
    // the strip where the person scrolled it.
    var width = window.innerWidth;
    window.addEventListener('resize', function () {
      if (window.innerWidth !== width) { width = window.innerWidth; reveal(); }
      mark();
    });
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () { reveal(); mark(); }); // the tabs' widths change once Poppins has loaded
    }
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
    // Sent: nothing of it is left unsaved, unless the send was stopped (Cancel on the question below: a stock record's lines form both
    // guards its edits and leaves the page, docs/decisions.md OV5).
    form.addEventListener('submit', function (ev) {
      window.setTimeout(function () {
        if (!ev.defaultPrevented) {
          form.removeAttribute('data-dirty');
        }
      }, 0);
    });
  });
  each(document.querySelectorAll('form[data-leaves]'), function (form) {
    form.addEventListener('submit', function (ev) {
      var dirty = null;
      each(document.querySelectorAll('form[data-unsaved][data-dirty]'), function (f) {
        if (f !== form && dirty === null) {
          dirty = f;
        }
      });
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

  // A form that changes something is sent once: the button is disabled on the first submit. Its buttons are those inside it and those
  // outside that name it (form="…": the count sheet's "Before you make it final", docs/decisions.md OV6).
  var sendOnce = function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) {
        return;
      }
      var buttons = Array.prototype.filter.call(form.elements, function (el) {
        return el.tagName === 'BUTTON' && el.getAttribute('type') === 'submit';
      });
      window.setTimeout(function () {
        each(buttons, function (b) { b.disabled = true; });
      }, 0);
    });
  };
  each(document.querySelectorAll('form[method="post"]'), sendOnce);

  // The one read of the network: a read-only fragment of this site's own matching routes, By Item's quick match (/ui/review/products/…),
  // the lists' Check & match fold (/ui/review/listing/…/check), Simple matching's choices of a cell (/ui/match/options?…), the order's product
  // box (/ui/purchasing/orders/products?…) and Lines to place's item box (/ui/queues/suspense/items?…, docs/decisions.md LQ5), each named
  // by its marker. Fetched with the session's cookie for
  // this site only, parsed inert by DOMParser (nothing in it runs) and given back only when its root carries the marker of the route it
  // was asked of; anything else (the sign-in page of a session that ended, an error page, no network, another address) fails, and the
  // caller shows the page's words for that.
  var FRAGMENTS = { '[data-qp-fragment]': '/ui/review/products/', '[data-lc-fragment]': '/ui/review/listing/', '[data-sm-fragment]': '/ui/match/options?', '[data-po-fragment]': '/ui/purchasing/orders/products?', '[data-lq-fragment]': '/ui/queues/suspense/items?' };
  // The supplier invoice's item box (docs/decisions.md PV) is the order page's: the same fragment, from the invoice's own route.
  var ALSO = { '[data-po-fragment]': '/ui/purchasing/invoices/products?' };
  var fragment = function (url, marker) {
    var path = FRAGMENTS[marker];
    if (path && url.indexOf(path) !== 0 && ALSO[marker] && url.indexOf(ALSO[marker]) === 0) {
      path = ALSO[marker];
    }
    if (!path || url.indexOf(path) !== 0 || !window.fetch || !window.DOMParser) {
      return Promise.reject(new Error('not a fragment of this site'));
    }
    return window.fetch(url, { credentials: 'same-origin', method: 'GET', headers: { Accept: 'text/html' } })
      .then(function (r) {
        if (!r.ok || r.redirected) {
          throw new Error('not the fragment');
        }
        return r.text();
      })
      .then(function (html) {
        var doc = new window.DOMParser().parseFromString(html, 'text/html');
        var part = doc.querySelector(marker);
        if (!part) {
          throw new Error('not the fragment');
        }
        var node = document.importNode(part, true);
        each(node.querySelectorAll('form[method="post"]'), sendOnce);
        return node;
      });
  };

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
      fragment(base + key, '[data-qp-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
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

  // The lists' Check & match fold (docs/decisions.md CM1; the owner's request of 20 Oct 2026: "add a match button here so no need to go
  // inside the page"). A row found by the check on arrival with a Likely suggestion has a <details> under its name. Without the script
  // it opens to the variant's page link. With it, each opening shows the variant checked against its suggestion in place: the fragment
  // at the address in data-check (a path of this site's check route), which alone decides whether the row may be answered here and then
  // carries the two answers (plain POST forms of the decide route). Anything else shows the page's words (data-failed) beside the link.
  each(document.querySelectorAll('details[data-check-fold]'), function (box) {
    var url = box.getAttribute('data-check') || '';
    var out = box.querySelector('[data-check-results]');
    var page = box.querySelector('.lc-page');
    if (url.indexOf('/ui/review/listing/') !== 0 || url.indexOf('/check?') < 0 || !out || !window.fetch || !window.DOMParser) {
      return;
    }
    var turn = 0;
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
    var load = function () {
      var mine = ++turn;
      out.setAttribute('aria-busy', 'true');
      show(line(box.getAttribute('data-loading') || '', 'lc-wait muted'));
      fragment(url, '[data-lc-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
          show(node);
          if (page) {
            page.hidden = true; // the fragment leads to the variant's page itself
          }
          out.removeAttribute('aria-busy');
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          var p = line(box.getAttribute('data-failed') || '', 'lc-error');
          p.setAttribute('role', 'alert');
          show(p);
          if (page) {
            page.hidden = false;
          }
          out.removeAttribute('aria-busy');
        });
    };
    // Asked again at every opening: the row may have changed since (the fragment is what the decision is checked against).
    box.addEventListener('toggle', function () {
      if (box.open) {
        load();
      }
    });
    if (box.open) {
      load();
    }
  });

  // The site's one dropdown (docs/decisions.md SM11, PX6): a text box with a list of its own that this script draws (a browser's <datalist>
  // popup is one line as wide as the box, and cut long names), at the page's top level: no table, card or scrolling box cuts it. The list
  // opens under the box (above it when there is more room there), wider than the box where the screen allows and to the left near its
  // right edge, as tall as the room it has, with its own scroll; Down and Up move in it, Enter chooses (and sends nothing while it is
  // open), Escape, a press elsewhere or leaving the box shut it; a click or a tap chooses. One list is open at a time, and it follows its
  // box when the page scrolls. What the list holds and what a choice does are the caller's (cfg): Simple matching's cells, the order's
  // supplier box and its product box.
  //   cfg.options()   the choices the keys and taps go to, in order (a line that is not a choice is left out of it);
  //   cfg.current()   where the choice in force is among them (-1: none), the one the keys start from;
  //   cfg.choose(li)  what a choice does (the list is shut first);
  //   cfg.enter(ev, open)  what Enter does when no choice is marked (none: as a box of a form, it sends it while the list is shut).
  var comboOpen = null; // the one list open now, or null
  var combo = function (input, list, cfg) {
    var active = -1; // the choice the keys are on, or -1
    var shut = false; // Escape shut the list: an answer does not open it again until the words change
    var me = null;
    var options = function () { return cfg.options(); };
    var isOpen = function () { return !list.hidden; };
    // The choice the keys are on (Down, Up): marked for the eye, named to a screen reader, scrolled into the list's sight.
    var mark = function (i) {
      var all = options();
      active = i >= 0 && i < all.length ? i : -1;
      each(all, function (o, j) {
        o.setAttribute('aria-selected', j === active ? 'true' : 'false');
        o.classList.toggle('is-active', j === active);
      });
      if (active < 0 || !isOpen()) {
        input.removeAttribute('aria-activedescendant');
        return;
      }
      var li = all[active];
      input.setAttribute('aria-activedescendant', li.id);
      if (li.offsetTop < list.scrollTop) {
        list.scrollTop = li.offsetTop;
      } else if (li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight) {
        list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight;
      }
    };
    // Where the list goes (fixed, at the page's top level): under the box, above it when there is more room there; from the box's left
    // edge, 36rem wide where the screen allows (never narrower than the box); near the right edge of the screen it opens to the left
    // (its right edge on the box's); on a phone it spans the screen but for the page's gutter; never past the screen; as tall as the
    // room it has, with its own scroll. A box out of sight (scrolled away up, down or sideways, clipped by a scrolling box around it
    // such as the table's own sideways scroll, its fold shut) shuts its list.
    var clipped = function (r) {
      for (var el = input.parentElement; el && el !== document.body; el = el.parentElement) {
        var st = window.getComputedStyle(el);
        if (/(auto|scroll|hidden|clip)/.test(st.overflowX + ' ' + st.overflowY)) {
          var c = el.getBoundingClientRect();
          if (r.right <= c.left || r.left >= c.right || r.bottom <= c.top || r.top >= c.bottom) {
            return true;
          }
        }
      }
      return false;
    };
    var place = function () {
      var r = input.getBoundingClientRect();
      var root = document.documentElement;
      var vw = root.clientWidth || window.innerWidth;
      var vh = window.innerHeight;
      if ((r.width === 0 && r.height === 0) || r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw || clipped(r)) {
        close();
        return;
      }
      var css = window.getComputedStyle(root);
      var rem = parseFloat(css.fontSize) || 16;
      var edge = Math.min(16, parseFloat(css.getPropertyValue('--gutter')) || 16);
      var width = Math.min(Math.max(36 * rem, r.width), vw - 2 * edge);
      var left = r.left;
      if (left + width > vw - edge) {
        left = r.right - width; // near the right edge of the screen: it opens to the left
      }
      left = Math.max(edge, Math.min(left, vw - edge - width));
      var below = vh - r.bottom - 8;
      var above = r.top - 8;
      var up = below < 200 && above > below;
      list.style.left = left + 'px';
      list.style.width = width + 'px';
      list.style.maxHeight = Math.max(96, Math.min(24 * rem, up ? above : below)) + 'px';
      list.style.top = (up ? Math.max(4, r.top - 4 - list.offsetHeight) : r.bottom + 4) + 'px';
    };
    // Shown only while the box has the focus and the list has choices (and Escape did not shut it since the words last changed); one
    // list open at a time; the choice in force is the one the keys start from.
    var open = function () {
      if (shut || options().length === 0 || document.activeElement !== input) {
        close();
        return;
      }
      if (comboOpen && comboOpen !== me) {
        comboOpen.close();
      }
      comboOpen = me;
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      place();
      mark(active >= 0 ? active : (cfg.current ? cfg.current() : -1));
    };
    var close = function () {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      mark(-1);
      if (comboOpen === me) {
        comboOpen = null;
      }
    };
    var choose = function (li) {
      close();
      cfg.choose(li);
    };
    // The box says it has a list, and the list is the page's own, at its top level.
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');
    document.body.appendChild(list);
    input.addEventListener('keydown', function (ev) {
      if (ev.isComposing) {
        return;
      }
      var all = options();
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        if (all.length === 0) {
          return;
        }
        ev.preventDefault();
        if (!isOpen()) {
          shut = false;
          open();
          if (active < 0) {
            mark(ev.key === 'ArrowDown' ? 0 : all.length - 1);
          }
          return;
        }
        mark(ev.key === 'ArrowDown' ? Math.min(active + 1, all.length - 1) : Math.max(active - 1, 0));
      } else if (ev.key === 'Enter') {
        if (cfg.enter && (!isOpen() || active < 0)) {
          cfg.enter(ev, isOpen()); // no choice marked: the box says what Enter does (the product box: Add; the supplier box: never send)
          return;
        }
        if (!isOpen()) {
          return; // the list is shut: Enter sends the form (Apply), as before
        }
        ev.preventDefault(); // the list is open: Enter chooses, and sends nothing
        if (active >= 0) {
          choose(all[active]);
        } else {
          close();
        }
      } else if (ev.key === 'Escape' && isOpen()) {
        ev.preventDefault();
        shut = true;
        close();
      }
    });
    // A press on the list keeps the focus in the box (its blur would shut the list under the press); a click or a tap chooses (a line
    // that is not a choice, such as the one naming whom to ask, does nothing).
    list.addEventListener('mousedown', function (ev) {
      ev.preventDefault();
    });
    list.addEventListener('click', function (ev) {
      var li = ev.target && ev.target.closest ? ev.target.closest('li[role="option"]') : null;
      if (li && list.contains(li) && li.getAttribute('aria-disabled') !== 'true') {
        choose(li);
      }
    });
    input.addEventListener('input', function () {
      mark(-1); // the words changed: no choice stays marked, so Enter cannot bring an old one back (the box marks its own choice again)
    });
    input.addEventListener('click', function () {
      shut = false;
      open();
    });
    input.addEventListener('blur', function () {
      // Leaving the box (Tab, a press elsewhere) shuts its list, a moment later: a tap that moved the focus still lands on its choice.
      window.setTimeout(function () {
        if (document.activeElement !== input) {
          close();
        }
      }, 150);
    });
    me = { input: input, list: list, open: open, close: close, place: place, mark: mark, isOpen: isOpen, choose: choose,
      unshut: function () { shut = false; } };
    return me;
  };
  // The open list follows its box when the page, or a box around it, scrolls, and when the window changes size (once a frame); a press
  // anywhere but on the box or its list shuts it.
  var comboFrame = 0;
  var comboFollow = function (ev) {
    if (!comboOpen || comboFrame || (ev && ev.target === comboOpen.list)) {
      return;
    }
    comboFrame = window.requestAnimationFrame(function () {
      comboFrame = 0;
      if (comboOpen) {
        comboOpen.place();
      }
    });
  };
  window.addEventListener('scroll', comboFollow, true);
  window.addEventListener('resize', comboFollow);
  document.addEventListener('mousedown', function (ev) {
    if (comboOpen && ev.target !== comboOpen.input && !comboOpen.list.contains(ev.target)) {
      comboOpen.close();
    }
  }, true);

  // Simple matching (docs/decisions.md SM4, SM11; the owner's requests of 25 and 26 Oct 2026: "one dropdown is enough ... a normal basic
  // dropdown", then "I want to still see the full name ... I need also the status of the variant shown there from each shop"). A cell's
  // box is a plain text box with the site's one dropdown (combo). The list is filled from the read-only fragment at the form's data-options
  // (a path of this site's options route): the item's own suggestions for that store while the box is empty (asked when it gets the focus),
  // else (2 letters or more, or one digit: an ID; 300 ms after the last key) that store's products still waiting for a match, the one whose
  // ID it is first. Each choice is two lines, built here as text: its full name on the store, then its ID, its status on the store, its
  // strength and its mark. A choice (also the one the page drew chosen, to confirm a typed ID) puts its words in the box, as a datalist's
  // choice did, and fills the form's hidden fields (the website product, its version, its open suggestion); a typed ID that is exactly one
  // choice's ID is that choice, kept as the list showed it (no search is drawn over it while the box names it). Apply with nothing chosen
  // says the page's words (data-choose) and sends nothing, unless the list could not be loaded: then the typed ID goes to the server, as
  // without the script (the one waiting product of the store with that ID comes back chosen, to confirm with a second Apply). Every word
  // comes from the page (data-loading, data-failed, data-choose, the list's aria-label, the fragment's lines).
  var smForms = [];
  each(document.querySelectorAll('form[data-sm-add]'), function (form) {
    var base = form.getAttribute('data-options') || '';
    var input = form.querySelector('input[data-sm-pick]');
    var list = form.querySelector('ul[data-sm-list]');
    var note = form.querySelector('[data-sm-note]');
    var field = function (name) { return form.querySelector('input[type="hidden"][name="' + name + '"]'); };
    var lid = field('listing_id');
    var ver = field('expected_map_version');
    var prop = field('proposal_id');
    if (base.indexOf('/ui/match/options?') !== 0 || !input || !list || !list.id || !note || !lid || !ver || !prop || !window.fetch || !window.DOMParser) {
      return;
    }
    var timer = 0;
    var asked = null;
    var turn = 0;
    var failed = false;
    var say = function (text) {
      note.textContent = text;
      note.hidden = text === '';
    };
    var options = function () { return list.querySelectorAll('li[data-listing-id]'); };
    // The list's choices, built from the fragment's (or the page's) <li>: the box's words for it and what Apply sends for it, copied
    // attribute by attribute, and its two lines as text (nothing of the fragment's markup is kept).
    var draw = function (from) {
      var items = Array.prototype.slice.call(from);
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      each(items, function (o, i) {
        var li = document.createElement('li');
        li.id = list.id + '-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        each(['data-label', 'data-listing-id', 'data-map-version', 'data-proposal-id', 'data-option'], function (name) {
          li.setAttribute(name, o.getAttribute(name) || '');
        });
        each(['sm-opt-name', 'sm-opt-meta'], function (cls) {
          var part = o.querySelector('.' + cls);
          var span = document.createElement('span');
          span.className = cls;
          span.textContent = part ? part.textContent.trim() : '';
          li.appendChild(span);
        });
        list.appendChild(li);
      });
      c.mark(-1);
    };
    // The choice the box names now: its words exactly (picked from the list), else the one choice whose ID is what was typed.
    var pick = function () {
      var typed = input.value.trim();
      var byLabel = [];
      var byId = [];
      each(options(), function (o) {
        if (o.getAttribute('data-label') === input.value) { byLabel.push(o); }
        if (typed !== '' && o.getAttribute('data-option') === typed) { byId.push(o); }
      });
      var found = byLabel.length === 1 ? byLabel[0] : null; // the words name one choice only (they start with the store's ID: they do)
      if (!found && byId.length === 1) { found = byId[0]; }
      lid.value = found ? found.getAttribute('data-listing-id') || '' : '';
      ver.value = found ? found.getAttribute('data-map-version') || '' : '';
      prop.value = found ? found.getAttribute('data-proposal-id') || '' : '';
      return found;
    };
    // Where the choice the hidden fields hold is in the list (-1: none).
    var current = function () {
      var at = -1;
      each(options(), function (o, i) {
        if (lid.value !== '' && o.getAttribute('data-listing-id') === lid.value) { at = i; }
      });
      return at;
    };
    // A choice (a click, a tap, Enter): the box takes its words (the list is shut); then an input event, as a datalist's choice fired, so
    // everything typing does follows (the hidden fields filled, no answer asked for before drawn over it, no search started).
    var c = combo(input, list, {
      options: options,
      current: current,
      choose: function (li) {
        input.value = li.getAttribute('data-label') || '';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
      },
    });
    draw(options()); // the choice the page drew (a typed ID to confirm) is drawn as every other
    var load = function (q) {
      var key = '&q=' + encodeURIComponent(q);
      if (key === asked) {
        return;
      }
      asked = key;
      var mine = ++turn;
      say(form.getAttribute('data-loading') || '');
      fragment(base + key, '[data-sm-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
          failed = false;
          draw(node.querySelectorAll('ul[data-sm-choices] > li[data-listing-id]'));
          var says = node.querySelector('[data-sm-says]');
          say(says ? says.textContent.trim() : '');
          if (pick()) {
            window.clearTimeout(timer); // a choice made from this answer: no search waiting to start may choose over it
          }
          c.open(); // (only while the box has the focus)
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          asked = null; // the next key asks again
          failed = true;
          say(form.getAttribute('data-failed') || '');
        });
    };
    input.addEventListener('focus', function () {
      if (lid.value === '') {
        load(input.value.trim()); // an empty box: the suggestions; words a refusal kept (no choice behind them): looked for again
        c.open(); // what is drawn for these words already (none yet: the answer opens it)
      }
    });
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      // The words changed: no answer asked for before them is drawn (it is about other words, and could choose over this one).
      turn++; // a search still on its way is not drawn: the choice stays
      asked = null;
      c.unshut();
      var chosen = pick();
      if (chosen) {
        // Picked from the list, or an ID typed whole that is one of its products: that choice, as the list showed it. Nothing more is
        // looked for while the box names it (docs/decisions.md SM4).
        say('');
        c.mark(current());
        return;
      }
      c.mark(-1);
      var v = input.value.trim();
      if (v.length === 1 && !/^[0-9]$/.test(v)) {
        say('');
        return; // one letter finds too much: wait for the next (one digit is an ID: asked at once)
      }
      timer = window.setTimeout(function () { load(v); }, 300);
    });
    smForms.push({ form: form, pick: pick, say: say, input: input, close: c.close, failed: function () { return failed; } });
  });
  if (smForms.length > 0) {
    // Asked before the "send once" of every form (the capture phase): a submit stopped here never leaves its button disabled.
    document.addEventListener('submit', function (ev) {
      for (var i = 0; i < smForms.length; i++) {
        var f = smForms[i];
        if (f.form !== ev.target) {
          continue;
        }
        f.close();
        if (!f.pick() && !(f.failed() && f.input.value.trim() !== '')) {
          ev.preventDefault();
          f.say(f.form.getAttribute('data-choose') || '');
          f.input.focus();
        }
        return;
      }
    }, true);
  }

  // The order's two "+ Add new …" forms (PO on one page, docs/decisions.md PX5): each is a fold of the order's one form; without the script
  // its own header opens it. Here the header is hidden and the dropdown's last line opens it (openPanel), the first field focused, filled
  // with what the box held when it is empty; Enter in it presses its own button (never Add, the form's first button).
  var openPanel = function (panel, fill) {
    if (!panel) {
      return;
    }
    panel.open = true;
    if (fill) {
      fill(panel);
    }
    var first = panel.querySelector('input:not([type="hidden"]):not([type="checkbox"])');
    if (first) {
      first.focus();
    }
  };
  each(document.querySelectorAll('details[data-po-panel]'), function (panel) {
    var summary = panel.querySelector('summary');
    var button = panel.querySelector('button[name="action"]');
    if (summary) {
      summary.hidden = true;
    }
    panel.addEventListener('keydown', function (ev) {
      var t = ev.target;
      if (ev.key === 'Enter' && !ev.isComposing && button && t && t.tagName === 'INPUT' && t.type !== 'checkbox') {
        ev.preventDefault();
        button.click();
      }
    });
  });
  // A dropdown's last line (docs/decisions.md PX5): "+ Add new …" for a person who may add one (a choice: it opens that form), else the
  // line naming whom to ask (not a choice). The words come from the page (data-add, data-ask).
  var lastLine = function (box, list, n) {
    var add = box.getAttribute('data-add');
    var ask = box.getAttribute('data-ask');
    if (!add && !ask) {
      return;
    }
    var li = document.createElement('li');
    li.id = list.id + '-' + n;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    li.textContent = add || ask;
    if (add) {
      li.setAttribute('data-add', '1');
      li.className = 'sm-add';
    } else {
      li.setAttribute('aria-disabled', 'true');
      li.className = 'sm-ask';
    }
    list.appendChild(li);
  };
  var twoLines = function (li, name, meta) {
    each([['sm-opt-name', name], ['sm-opt-meta', meta]], function (p) {
      if (p[1] === '') {
        return;
      }
      var span = document.createElement('span');
      span.className = p[0];
      span.textContent = p[1];
      li.appendChild(span);
    });
  };

  // The order's supplier (PO on one page, docs/decisions.md PX6; the owner, 26 Oct 2026: "supplier choose option add new button in this
  // dropdown"): a plain select, which works so without the script. Here it becomes a box with the site's one dropdown: its options are the
  // list (the name, and on a second line a supplier not usable yet says so), the ones holding every word typed, then the last line. A
  // choice sets the select, which the form sends; leaving the box without one puts back the name of the supplier chosen. Enter in this box
  // never sends the order: it chooses the first supplier of the list, or opens it.
  each(document.querySelectorAll('select[data-po-supplier]'), function (select) {
    var panel = document.getElementById(select.getAttribute('data-panel') || '');
    var label = document.querySelector('label[for="' + select.id + '"]');
    var ask = select.parentNode.querySelector('.po-ask');
    var input = document.createElement('input');
    input.type = 'text';
    input.id = select.id + '-box';
    input.className = 'po-box';
    input.setAttribute('autocomplete', 'off');
    input.placeholder = select.getAttribute('data-placeholder') || '';
    var list = document.createElement('ul');
    list.id = select.id + '-list';
    list.className = 'sm-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', select.getAttribute('data-label') || '');
    list.hidden = true;
    var nameOf = function (o) { return o.getAttribute('data-name') || o.text; };
    var shown = function () {
      var o = select.options[select.selectedIndex];
      return o && o.value !== '' ? nameOf(o) : '';
    };
    input.value = shown();
    select.hidden = true;
    select.parentNode.insertBefore(input, select);
    select.parentNode.insertBefore(list, select);
    if (label) {
      label.htmlFor = input.id;
    }
    if (ask) {
      ask.hidden = true; // said as the list's last line
    }
    var options = function () { return list.querySelectorAll('li[role="option"]:not([aria-disabled="true"])'); };
    var draw = function () {
      var words = input.value === shown() ? [] : input.value.trim().toLowerCase().split(/\s+/);
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      var n = 0;
      each(select.options, function (o) {
        var name = nameOf(o);
        if (o.value === '' || words.some(function (w) { return w !== '' && name.toLowerCase().indexOf(w) < 0; })) {
          return;
        }
        var li = document.createElement('li');
        li.id = list.id + '-' + n++;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.setAttribute('data-value', o.value);
        twoLines(li, name, o.getAttribute('data-meta') || '');
        list.appendChild(li);
      });
      lastLine(select, list, n);
      c.mark(-1);
    };
    var cfg = {
      options: options,
      current: function () {
        var at = -1;
        each(options(), function (o, i) {
          if (o.getAttribute('data-value') === select.value) { at = i; }
        });
        return at;
      },
      choose: function (li) {
        if (li.getAttribute('data-add') === '1') {
          var typed = input.value === shown() ? '' : input.value.trim();
          input.value = shown();
          openPanel(panel, function (p) {
            var name = p.querySelector('input[name="new_supplier_name"]');
            if (name && name.value === '' && typed !== '') { name.value = typed; }
          });
          return;
        }
        select.value = li.getAttribute('data-value') || '';
        input.value = shown();
        if (panel) {
          panel.open = false;
        }
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
      },
      enter: function (ev, open) {
        ev.preventDefault(); // Enter in the supplier's box never sends the order
        if (!open) {
          draw();
          c.unshut();
          c.open();
          return;
        }
        var first = options()[0];
        if (first) {
          c.choose(first);
        }
      },
    };
    var c = combo(input, list, cfg);
    draw();
    input.addEventListener('focus', function () {
      draw();
      c.open();
    });
    input.addEventListener('input', function () {
      draw();
      c.unshut();
      c.open();
    });
    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (document.activeElement !== input) {
          input.value = shown(); // no choice made: the box names the supplier chosen
        }
      }, 200);
    });
  });

  // The order's product box (PO on one page, docs/decisions.md PX6): the scan box with the site's one dropdown. A scan, or a CW number,
  // their code or words of the name with Enter, adds as without the script (Add, the form's first button: Enter with nothing marked in the
  // list shuts it and sends). Typing (2 letters or more, 300 ms after the last key) asks the products route (the read-only fragment at
  // data-options, for the supplier chosen on the page) for this supplier's products first; a choice puts its words in the box and its
  // product in the form's hidden fields, which Add sends; changing the words drops it, and an answer asked for before them is never drawn.
  // The list ends with "+ Add new item", which opens that form with what the box held (digits: the barcode, else the name), or with the
  // line naming whom to ask. Every word comes from the page (data-loading, data-failed, data-add, data-ask, the fragment's lines).
  each(document.querySelectorAll('input[data-po-pick]'), function (input) {
    var form = input.form;
    var base = input.getAttribute('data-options') || '';
    var list = form ? form.querySelector('ul[data-po-list]') : null;
    var note = form ? form.querySelector('[data-po-note]') : null;
    var si = form ? form.querySelector('input[type="hidden"][name="pick_si"]') : null;
    var sku = form ? form.querySelector('input[type="hidden"][name="pick_sku"]') : null;
    var panel = document.getElementById(input.getAttribute('data-panel') || '');
    var supplier = document.getElementById('po-supplier');
    // The order's route, or the supplier invoice's (docs/decisions.md PV: the invoice's item box is this one).
    if ((base.indexOf('/ui/purchasing/orders/products?') !== 0 && base.indexOf('/ui/purchasing/invoices/products?') !== 0) || !list || !list.id || !note || !si || !sku
        || !window.fetch || !window.DOMParser) {
      return;
    }
    var ask = (input.closest('section') || form).querySelector('.po-ask');
    if (ask) {
      ask.hidden = true; // said as the list's last line
    }
    var timer = 0;
    var asked = null;
    var turn = 0;
    var say = function (text) {
      note.textContent = text;
      note.hidden = text === '';
    };
    var options = function () { return list.querySelectorAll('li[role="option"]:not([aria-disabled="true"])'); };
    // The choices, built from the fragment's <li>: the box's words for it and what Add sends, copied attribute by attribute, and its two
    // lines as text (nothing of the fragment's markup is kept); then the last line.
    var draw = function (from) {
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      var n = 0;
      each(from, function (o) {
        var li = document.createElement('li');
        li.id = list.id + '-' + n++;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        each(['data-label', 'data-si', 'data-sku'], function (name) {
          li.setAttribute(name, o.getAttribute(name) || '');
        });
        var part = function (cls) { var p = o.querySelector('.' + cls); return p ? p.textContent.trim() : ''; };
        twoLines(li, part('sm-opt-name'), part('sm-opt-meta'));
        list.appendChild(li);
      });
      lastLine(input, list, n);
      c.mark(-1);
    };
    // The product the box names now, put in the hidden fields (none: they are emptied): the choice made, kept as itself while the box holds
    // its words (two packs of one product may have had the same words: the Codex review of bd26fc3, finding 1), else the one choice whose
    // words the box holds exactly (words that name several name none).
    var chosen = null;
    var pick = function () {
      var found = null;
      if (chosen && list.contains(chosen) && chosen.getAttribute('data-label') === input.value) {
        found = chosen;
      } else {
        var same = [];
        each(list.querySelectorAll('li[data-label]'), function (o) {
          if (o.getAttribute('data-label') === input.value) { same.push(o); }
        });
        found = same.length === 1 ? same[0] : null; // the words name one choice only
      }
      chosen = found;
      si.value = found ? found.getAttribute('data-si') || '' : '';
      sku.value = found ? found.getAttribute('data-sku') || '' : '';
      return found;
    };
    var c = combo(input, list, {
      options: options,
      choose: function (li) {
        if (li.getAttribute('data-add') === '1') {
          openPanel(panel, function (p) {
            var typed = input.value.trim();
            var digits = /^[0-9 ]{6,64}$/.test(typed);
            var target = p.querySelector('input[name="' + (digits ? 'item_barcode' : 'item_name') + '"]');
            if (target && target.value === '' && typed !== '' && !pick()) { target.value = digits ? typed.replace(/ /g, '') : typed; }
          });
          return;
        }
        chosen = li;
        input.value = li.getAttribute('data-label') || '';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
      },
      enter: function (ev, open) {
        if (open) {
          c.close(); // nothing marked in the open list: Enter adds what the box says, as without the script
        }
      },
    });
    draw(list.querySelectorAll('li[data-label]')); // the choice the page drew (kept after a refusal) is drawn as any other
    pick();
    var load = function (q) {
      var key = (supplier ? '&supplier=' + encodeURIComponent(supplier.value) : '') + '&q=' + encodeURIComponent(q);
      if (key === asked) {
        return;
      }
      asked = key;
      var mine = ++turn;
      say(input.getAttribute('data-loading') || '');
      fragment(base + key, '[data-po-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
          draw(node.querySelectorAll('ul[data-po-choices] > li[data-label]'));
          var says = node.querySelector('[data-po-says]');
          say(says ? says.textContent.trim() : '');
          c.open(); // (only while the box has the focus)
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          asked = null; // the next key asks again
          draw([]);
          say(input.getAttribute('data-failed') || '');
        });
    };
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      turn++; // the words changed: no answer asked for before them is drawn
      asked = null;
      c.unshut();
      if (pick()) {
        say('');
        return; // a choice: nothing more is looked for while the box names it
      }
      draw([]); // the words changed: the choices on show were for other words
      var v = input.value.trim();
      if (v.length < 2) {
        say('');
        return; // one letter finds too much: wait for the next
      }
      timer = window.setTimeout(function () { load(v); }, 300);
    });
    if (supplier) {
      supplier.addEventListener('change', function () {
        // Another supplier: a choice of the last one's products goes (their supply is that supplier's), and its own are asked for anew.
        window.clearTimeout(timer);
        turn++;
        asked = null;
        if (si.value !== '' || sku.value !== '') {
          input.value = '';
        }
        draw([]);
        pick();
      });
    }
  });

  // The supplier invoice's "From" (docs/decisions.md PV): every open delivery and order, each naming its supplier (data-supplier), so the
  // plain select works without the script; here only the chosen supplier's are offered (the others hidden and switched off), and a choice
  // of another supplier's goes when the supplier changes. The server refuses another supplier's document anyway.
  each(document.querySelectorAll('select[data-pi-from]'), function (from) {
    var supplier = document.getElementById('po-supplier');
    var keep = function () {
      var chosen = supplier ? supplier.value : '';
      each(from.querySelectorAll('option[data-supplier]'), function (o) {
        var off = chosen !== '' && o.getAttribute('data-supplier') !== chosen;
        o.hidden = off;
        o.disabled = off;
        if (off && o.selected) {
          from.value = '';
        }
      });
      each(from.querySelectorAll('optgroup'), function (g) {
        g.hidden = g.querySelector('option:not([disabled])') === null;
      });
    };
    keep();
    if (supplier) {
      supplier.addEventListener('change', keep);
    }
  });

  // The supplier invoice's due date (docs/decisions.md PV): the invoice date plus the supplier's payment terms in days (data-terms: on the
  // supplier's option, or on the box when the supplier is fixed), while the box is empty or still holds the date filled for the person
  // (data-auto); once the person types in it, or clears it, it is theirs (the Codex review of 6418b15, finding 4). The new page's
  // due_shown says what the box showed, so a date cleared on purpose is not filled again by the server. No terms: left as it is.
  each(document.querySelectorAll('input[data-pi-due]'), function (due) {
    var form = due.form;
    var date = form ? form.querySelector('input[name="invoice_date"]') : null;
    var shown = form ? form.querySelector('input[type="hidden"][name="due_shown"]') : null;
    var supplier = document.getElementById('po-supplier');
    // A page drawn again after a refusal with the box cleared by the person (due_shown kept, the box empty) starts as theirs (the Codex
    // review of 8f6fbb7, M2; PV32): a new invoice date does not fill it again.
    var typed = !!(shown && shown.value !== '' && due.value === '');
    var auto = due.getAttribute('data-auto') === '1' ? due.value : null;
    if (!date) {
      return;
    }
    var terms = function () {
      var o = supplier && supplier.selectedIndex >= 0 ? supplier.options[supplier.selectedIndex] : null;
      var t = o ? o.getAttribute('data-terms') : due.getAttribute('data-terms');
      return t !== null && /^[0-9]{1,4}$/.test(t) ? parseInt(t, 10) : null;
    };
    var hint = form ? form.querySelector('[data-pi-due-hint]') : null;
    var say = function () {
      // "From the supplier's terms: 30 days." (the words from the page), shown only while the supplier chosen has terms.
      var days = terms();
      if (hint) {
        hint.hidden = days === null;
        hint.textContent = (hint.getAttribute('data-words') || '').replace('%s', String(days));
      }
    };
    var fill = function () {
      var days = terms();
      var m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(date.value);
      if (typed || days === null || !m || (due.value !== '' && due.value !== auto)) {
        return;
      }
      var d = new Date(Date.UTC(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10) + days));
      var two = function (n) { return (n < 10 ? '0' : '') + n; };
      due.value = d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate());
      auto = due.value;
      if (shown) {
        shown.value = due.value;
      }
    };
    due.addEventListener('input', function () { typed = true; });
    date.addEventListener('change', fill);
    if (supplier) {
      supplier.addEventListener('change', fill);
      supplier.addEventListener('change', say);
    }
  });

  // Lines to place (Approvals › From the stores, docs/decisions.md LQ4): a row's "Item in CW" is the order page's dropdown. Without the script
  // it is the text box for the item's CW code (the server reads `item` as it always did). Here: typing a name, a barcode or a CW number (2
  // characters or more, 300 ms after the last key; at once with Enter, so a scan is looked up when the scanner sends Enter) asks the items route
  // (the read-only fragment at data-options) for the items it names, each with its CW code and its stock; a choice puts its CW code in the box,
  // which the row's form sends, and shows it as a green "picked" chip with "Change" (the box back, empty). The item a usable barcode or a CW
  // number names exactly (the fragment's data-exact) is chosen by Enter; words never are.
  // The list ends with "+ Add new item" (the new item page, the line's name filled in) or the line naming whom to ask. Place with words that
  // are no choice and no CW code says the page's words (data-choose) and sends nothing. A refused answer's item comes back picked
  // (data-picked). Every word comes from the page.
  each(document.querySelectorAll('input[data-lq-pick]'), function (input) {
    var base = input.getAttribute('data-options') || '';
    var cell = input.parentNode;
    var form = input.form;
    var list = cell ? cell.querySelector('ul[data-lq-list]') : null;
    var chip = cell ? cell.querySelector('[data-lq-chip]') : null;
    var chipName = chip ? chip.querySelector('[data-lq-chip-name]') : null;
    var change = chip ? chip.querySelector('button[data-lq-change]') : null;
    var note = cell ? cell.querySelector('[data-lq-note]') : null;
    if (base.indexOf('/ui/queues/suspense/items?') !== 0 || !form || !list || !list.id || !chip || !chipName || !change || !note || !window.fetch || !window.DOMParser) {
      return;
    }
    var link = cell.querySelector('[data-lq-add-link]');
    if (link) {
      link.hidden = true; // said as the list's last line
    }
    input.removeAttribute('maxlength'); // a name is typed here; what the form sends is the CW code of the item chosen
    input.placeholder = input.getAttribute('data-placeholder') || input.placeholder;
    var timer = 0;
    var turn = 0;
    var say = function (text) {
      note.textContent = text;
      note.hidden = text === '';
    };
    var options = function () { return list.querySelectorAll('li[role="option"]:not([aria-disabled="true"])'); };
    var draw = function (from) {
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      var n = 0;
      each(from, function (o) {
        var li = document.createElement('li');
        li.id = list.id + '-' + n++;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        each(['data-label', 'data-code'], function (name) {
          li.setAttribute(name, o.getAttribute(name) || '');
        });
        var part = function (cls) { var p = o.querySelector('.' + cls); return p ? p.textContent.trim() : ''; };
        twoLines(li, part('sm-opt-name'), part('sm-opt-meta'));
        list.appendChild(li);
      });
      lastLine(input, list, n);
      c.mark(-1);
    };
    // The item chosen: its CW code in the box (the form sends it), the box hidden behind the chip naming it.
    var picked = function (label) {
      window.clearTimeout(timer);
      turn++;
      say('');
      chipName.textContent = label;
      chip.hidden = false;
      input.hidden = true;
    };
    var c = combo(input, list, {
      options: options,
      choose: function (li) {
        if (li.getAttribute('data-add') === '1') {
          var href = input.getAttribute('data-add-href') || '';
          if (href.indexOf('/ui/items/new') === 0) {
            window.location.href = href;
          }
          return;
        }
        input.value = li.getAttribute('data-code') || '';
        picked(li.getAttribute('data-label') || input.value);
      },
      enter: function (ev) {
        ev.preventDefault(); // Enter in the box never sends the row: it looks the words up now (a scanner's Enter), and one item found is chosen
        window.clearTimeout(timer);
        load(input.value.trim(), true);
      },
    });
    var load = function (q, enter) {
      if (q.length < 2) {
        return;
      }
      var mine = ++turn;
      say(input.getAttribute('data-loading') || '');
      fragment(base + 'q=' + encodeURIComponent(q), '[data-lq-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
          var found = node.querySelectorAll('ul[data-lq-choices] > li[data-code]');
          draw(found);
          var says = node.querySelector('[data-lq-says]');
          say(says ? says.textContent.trim() : '');
          if (enter && found.length === 1 && node.getAttribute('data-exact') === '1') {
            c.choose(options()[0]); // a usable barcode or a CW number names its item exactly (LQ9); words are never chosen for the person
            return;
          }
          c.open(); // (only while the box has the focus)
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          draw([]);
          say(input.getAttribute('data-failed') || '');
        });
    };
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      turn++; // the words changed: no answer asked for before them is drawn
      c.unshut();
      draw([]);
      var v = input.value.trim();
      if (v.length < 2) {
        say('');
        return;
      }
      timer = window.setTimeout(function () { load(v, false); }, 300);
    });
    change.addEventListener('click', function () {
      chip.hidden = true;
      input.hidden = false;
      input.value = '';
      draw([]);
      input.focus();
    });
    // Place with words in the box that are no item chosen and no CW code: asked before the "send once" of every form (the capture phase),
    // so a submit stopped here never leaves its button disabled.
    document.addEventListener('submit', function (ev) {
      var v = input.value.trim();
      if (ev.target !== form || input.hidden || v === '' || /^cw-?[0-9]{1,10}$/i.test(v)) {
        return; // an item chosen (the chip), a CW code typed, or nothing (the box is required: the browser says so)
      }
      ev.preventDefault();
      say(input.getAttribute('data-choose') || '');
      input.focus();
    }, true);
    draw([]);
    if (input.getAttribute('data-picked')) {
      picked(input.getAttribute('data-picked'));
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

  // ==== search-match-ux (docs/decisions.md SX5, SX7): the page's search from the keyboard, and the next Check & match ==============
  // "/" puts the cursor in the page's own search box (the one marked data-page-search; a folded one, the variant page's "Pick a
  // different product", is opened first), unless the person is typing in a field or holds Ctrl, Cmd or Alt. The box says so to a
  // screen reader (aria-keyshortcuts="/").
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey || ev.defaultPrevented) {
      return;
    }
    var t = ev.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) {
      return;
    }
    var box = document.querySelector('main input[data-page-search]');
    if (!box) {
      return;
    }
    var fold = box.closest ? box.closest('details') : null;
    if (fold && !fold.open) {
      fold.open = true;
    }
    ev.preventDefault();
    box.focus();
    if (typeof box.select === 'function') {
      box.select();
    }
  });

  // After an answer from a list's Check & match, CW comes back to the list at the next row (#l-<id>, with the "Done" notice: the
  // address carries notice= and prev=). That row's fold is opened by itself, so the next check is one press away; the toast says so
  // in the page's words (data-opened: Words::CHECK). Opening it only reads its details (the same GET as a press on it); it answers
  // nothing.
  (function () {
    var params = window.location.search;
    var id = window.location.hash.slice(1);
    if (!/^l-[0-9]+$/.test(id) || !/[?&]notice=(decided_link|decided_reject|pending_second)(&|$)/.test(params) || !/[?&]prev=[0-9]+(&|$)/.test(params)) {
      return;
    }
    var row = document.getElementById(id);
    var fold = row ? row.querySelector('details[data-check-fold]') : null;
    if (!fold || fold.open) {
      return;
    }
    fold.open = true;
    var head = fold.querySelector('summary');
    if (head) {
      head.focus({ preventScroll: true }); // a keyboard goes on from the row the list came back to (Tab: its answers)
    }
    var toast = document.querySelector('p.toast');
    var said = fold.getAttribute('data-opened') || '';
    if (toast && said !== '') {
      toast.textContent = toast.textContent.replace(/\s+$/, '') + ' ' + said;
    }
  })();
  // ==== end search-match-ux ==========================================================================================================

  // New delivery: "receive all as ordered" follows the purchase order chosen.
  each(document.querySelectorAll('select[data-ticks]'), function (s) {
    var box = document.getElementById(s.getAttribute('data-ticks'));
    if (!box) {
      return;
    }
    s.addEventListener('change', function () { box.checked = s.value !== ''; });
  });
})();
