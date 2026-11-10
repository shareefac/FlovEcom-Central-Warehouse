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
  // A group the page draws to start folded (Stock > Counts: inside the plan, the lines the same as the system; docs/decisions.md CV1, CV10)
  // is folded here, by the toggle's own code: without the script it stays open, so nothing is out of reach.
  each(document.querySelectorAll('button.grp-toggle[data-folded]'), function (b) { b.click(); });

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
    b.className = 'btn ghost sm reveal';
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

  // What the script has to say about a field or a form, in the page and never in a browser dialog (an alert froze the tab until someone
  // pressed OK: docs/decisions.md GV11): one line at the end of $box (made once, then reused), role="alert" so a screen reader says it too.
  var tell = function (box, text) {
    var line = box.querySelector('[data-said]');
    if (!line) {
      line = document.createElement('span');
      line.className = 'inline-alert';
      line.setAttribute('role', 'alert');
      line.setAttribute('data-said', '1');
      box.appendChild(line);
    }
    line.textContent = text;
  };

  // Unsaved edits (a delivery's page, a stock record's lines): another form on the page (import, copy, cancel) leaves the page, so it
  // says so first in that form instead of throwing the typed edits away; pressing its button again goes on without them (GV11). The
  // words come from the page (data-unsaved-text on the edited form, data-busy-text on the leaving one), English if a page has none.
  // A field typed in marks the form it BELONGS to (docs/decisions.md W12.17): the one around it, or the one it names with form="…" wherever
  // it stands (an invoice's planned date, an order's cancel reason, a line to place's item; a store product's "Or find another item" inside
  // the decision form is its own GET form's, never the decision form's). Listened to on the document, so a field outside its form counts.
  var markOwner = function (ev) {
    var t = ev.target;
    var owner = t && (t.form || (t.closest ? t.closest('form') : null));
    if (owner && owner.hasAttribute('data-unsaved')) {
      owner.setAttribute('data-dirty', '1');
    }
  };
  document.addEventListener('input', markOwner, true);
  document.addEventListener('change', markOwner, true);
  var guarded = document.querySelectorAll('form[data-unsaved]');
  each(guarded, function (form) {
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
  // (A form put in place by a save without reload is wired the same way: leaving(), docs/decisions.md AJ5.)
  var leaving = function (form) {
    form.addEventListener('submit', function (ev) {
      var dirty = null;
      each(document.querySelectorAll('form[data-unsaved][data-dirty]'), function (f) {
        if (f !== form && dirty === null) {
          dirty = f;
        }
      });
      if (form.getAttribute('data-busy') === '1') {
        ev.preventDefault();
        tell(form, form.getAttribute('data-busy-text') || 'The photo is still being made smaller: wait a moment, then press the button again.');
        return;
      }
      if (dirty && form.getAttribute('data-go') !== '1') {
        ev.preventDefault();
        form.setAttribute('data-go', '1');
        tell(form, dirty.getAttribute('data-unsaved-text') || 'You have changes on this page that are not saved. They are lost if you go on: save them first, '
          + 'or press the same button again to go on without them.');
      }
    });
  };
  each(document.querySelectorAll('form[data-leaves]'), leaving);
  // A link that leaves the page while a form holds typed values says so first too (docs/decisions.md CV14; Codex's review of 7cfcc89: a
  // tab or a filter link lost a typed count), in the page and never in a browser dialog (GV11): the first press goes nowhere and a red
  // note at the bottom of the screen says why (role="alert"; the words: the page's data-unsaved-link, Words::UI unsaved_link); the same
  // link pressed again goes on without them, as a leaving form's second press does. A tab, a filter, the pager, a crumb, the menu.
  // Registered on window, so a component's own handler (a dropdown's link, the drawer's "New …") has had its say first. A link to a part
  // of this page, a download, one that opens elsewhere, a modified click (a new tab) and "Leave without saving" (data-leave: it says what
  // it does) never stop. The tab of the page shown is no link at all (CV13).
  var leaveNote = null;
  window.addEventListener('click', function (ev) {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
      return;
    }
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || a.hasAttribute('download') || a.hasAttribute('data-leave') || (a.getAttribute('target') || '_self') !== '_self') {
      return;
    }
    if ((a.getAttribute('href') || '').charAt(0) === '#') {
      return;
    }
    var dirty = document.querySelector('form[data-unsaved][data-dirty]');
    if (!dirty || a.getAttribute('data-go') === '1') {
      return;
    }
    ev.preventDefault();
    each(document.querySelectorAll('a[data-go]'), function (x) { x.removeAttribute('data-go'); });
    a.setAttribute('data-go', '1');
    if (!leaveNote) {
      leaveNote = document.createElement('p');
      leaveNote.className = 'toast error';
      leaveNote.setAttribute('role', 'alert');
      // A press on it puts it away (p.toast's is-gone, as the layout's message); the person stays with what they typed.
      leaveNote.addEventListener('click', function () { leaveNote.classList.add('is-gone'); });
      document.body.appendChild(leaveNote);
    }
    leaveNote.textContent = document.body.getAttribute('data-unsaved-link') || 'You have changes on this page that are not saved. They are lost if you '
      + 'leave: save them first, or press the same link again to go on without them.';
    leaveNote.classList.remove('is-gone');
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
      // Looked at again once every listener of this send has run: one that stopped it (a size or count refused in the page) leaves the
      // buttons as they were (the Codex review of 5c070f8, GV19).
      window.setTimeout(function () {
        if (ev.defaultPrevented) {
          return;
        }
        each(buttons, function (b) { b.disabled = true; });
      }, 0);
    });
  };
  each(document.querySelectorAll('form[method="post"]'), sendOnce);

  // The one read of the network: a read-only fragment of this site's own matching routes, By Item's quick match (/ui/review/products/…),
  // the lists' Check & match fold (/ui/review/listing/…/check), Simple matching's choices of a cell (/ui/match/options?…), the order's product
  // box (/ui/purchasing/orders/products?…), Lines to place's item box (/ui/queues/suspense/items?…, docs/decisions.md LQ5) and a family's
  // picker (/ui/items/families/<id>/find?…, docs/decisions.md FP3), each named by its marker. Fetched with the session's cookie for
  // this site only, parsed inert by DOMParser (nothing in it runs) and given back only when its root carries the marker of the route it
  // was asked of; anything else (the sign-in page of a session that ended, an error page, no network, another address) fails, and the
  // caller shows the page's words for that.
  // Sent (POST, with the form's CSRF token and a FormOnce key in its body; docs/decisions.md KG21, KG24), only two kinds: the lines' grid's
  // "+ Add new item" (its route answers a fragment, data-ni-fragment) and the editor's Save (its own address answers the editor's page,
  // data-kg-editor: what it gives back is the part of the page around the form, to put in place; a refusal's 409 / 422 is its answer too):
  // the order's, the supplier invoice's, a delivery's (docs/decisions.md GW7: its one form, /ui/receiving/<id>/lines) and a quick adjustment's
  // (docs/decisions.md AJ4: a new one's /ui/stock/adjustments/new, a draft's /ui/stock/adjustments/<id>/grid). A delivery's scan box reads its
  // own choices (GW3: data-gi-fragment, /ui/receiving/<id>/products?…).
  var SENT = { '[data-ni-fragment]': /^\/ui\/purchasing\/(orders|invoices)\/new-item$/, '[data-kg-editor]': /^\/ui\/(purchasing\/(orders|invoices)(\/[1-9][0-9]{0,17}\/lines)?|receiving\/[1-9][0-9]{0,17}\/lines|stock\/adjustments\/(new|[1-9][0-9]{0,17}\/grid))$/ };
  var FRAGMENTS = { '[data-qp-fragment]': '/ui/review/products/', '[data-lc-fragment]': '/ui/review/listing/', '[data-sm-fragment]': '/ui/match/options?', '[data-po-fragment]': '/ui/purchasing/orders/products?', '[data-lq-fragment]': '/ui/queues/suspense/items?', '[data-aj-fragment]': '/ui/stock/adjustments/products?', '[data-fp-fragment]': '/ui/items/families/', '[data-gi-fragment]': '/ui/receiving/' };
  // The supplier invoice's item box (docs/decisions.md PV) is the order page's: the same fragment, from the invoice's own route.
  var ALSO = { '[data-po-fragment]': '/ui/purchasing/invoices/products?' };
  var fragment = function (url, marker, body) {
    var path = FRAGMENTS[marker];
    if (path && url.indexOf(path) !== 0 && ALSO[marker] && url.indexOf(ALSO[marker]) === 0) {
      path = ALSO[marker];
    }
    var sent = body !== undefined && SENT[marker] !== undefined && SENT[marker].test(url);
    if ((!sent && (!path || url.indexOf(path) !== 0 || body !== undefined)) || !window.fetch || !window.DOMParser) {
      return Promise.reject(new Error('not a fragment of this site'));
    }
    var editor = marker === '[data-kg-editor]';
    return window.fetch(url, sent ? { credentials: 'same-origin', method: 'POST', headers: { Accept: 'text/html' }, body: body, redirect: 'follow' }
      : { credentials: 'same-origin', method: 'GET', headers: { Accept: 'text/html' } })
      .then(function (r) {
        // The editor's Save lands on the editor's page (303, followed) or answers a refusal (409, 422) with it; nothing else is an answer.
        if (editor ? (r.status !== 200 && r.status !== 409 && r.status !== 422) : (!r.ok || r.redirected)) {
          throw new Error('not the fragment');
        }
        return r.text();
      })
      .then(function (html) {
        var doc = new window.DOMParser().parseFromString(html, 'text/html');
        var part = doc.querySelector(editor ? 'form[data-kg-editor]' : marker);
        if (!part || (editor && !part.parentNode)) {
          throw new Error('not the fragment');
        }
        var node = document.importNode(editor ? part.parentNode : part, true);
        if (!editor) {
          each(node.querySelectorAll('form[method="post"]'), sendOnce);
        } else {
          // The answer's own name (its <title>), for the page that puts the answer in place: a first save names the tab as its draft (kgTitle).
          node.setAttribute('data-kg-title', doc.title || '');
        }
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
      // What is measured here is on the screen; the list is placed in the page, drawn at --zoom (app.css).
      var zoom = parseFloat(css.getPropertyValue('--zoom')) || 1;
      var rem = (parseFloat(css.fontSize) || 16) * zoom;
      var edge = Math.min(16, parseFloat(css.getPropertyValue('--gutter')) || 16) * zoom;
      var width = Math.min(Math.max(36 * rem, r.width), vw - 2 * edge);
      var left = r.left;
      if (left + width > vw - edge) {
        left = r.right - width; // near the right edge of the screen: it opens to the left
      }
      left = Math.max(edge, Math.min(left, vw - edge - width));
      var below = vh - r.bottom - 8;
      var above = r.top - 8;
      var up = below < 200 && above > below;
      list.style.left = left / zoom + 'px';
      list.style.width = width / zoom + 'px';
      list.style.maxHeight = Math.max(96, Math.min(24 * rem, up ? above : below)) / zoom + 'px';
      list.style.top = (up ? Math.max(4, r.top - 4 - list.offsetHeight * zoom) : r.bottom + 4) / zoom + 'px';
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

  // The site's keys (docs/decisions.md KG13-KG20; the owner, 6 Nov 2026: "i need add item multiple short key option in keyboard, i need
  // standard short key option like Tally, ERPNext"): ONE listener on the document and ONE table of keys (KEYS, below), shared by every page:
  // the lines' grid (docGrid registers itself in kb.grid, a box with "+ Add new" in kb.boxes), the lists of orders and invoices
  // (table[data-kb-rows]), the panel "Keyboard shortcuts" (keys_panel). A letter is read by its place on the keyboard (ev.code: a Mac's
  // Option makes other characters); on a Mac Cmd or Ctrl is the Ctrl of a key and Option its Alt, and the page shows Cmd and Option
  // (kbd[data-mac]). No key a browser keeps is taken (Ctrl+N, T, W, Tab; Ctrl+Shift+N, T, Delete; Alt+D, E, F; the F keys), nor a key the
  // dropdown or a box took first (ev.defaultPrevented). Each key's words are the panel's (Words::KEYS); docs/dev.md has the table.
  var kb = { grid: null, boxes: [], row: -1, busy: false };
  var kbNav = window.navigator || {};
  var kbMac = /mac|iphone|ipad/i.test((kbNav.userAgentData && kbNav.userAgentData.platform) || kbNav.platform || '');
  if (kbMac) {
    each(document.querySelectorAll('kbd[data-mac]'), function (k) {
      if (k.getAttribute('data-mac') === '') {
        k.hidden = true; // a key a Mac has not (Insert)
      } else {
        k.textContent = k.getAttribute('data-mac');
      }
    });
  }
  // Exactly these modifiers: Ctrl (on a Mac Cmd or Ctrl), Alt (Option), Shift; a Windows key never counts as Ctrl.
  var kbIs = function (ev, ctrl, alt, shift) {
    return (ev.ctrlKey || (kbMac && ev.metaKey)) === ctrl && ev.altKey === alt && ev.shiftKey === shift && (kbMac || !ev.metaKey);
  };
  // Typing in a box (where /, ?, Ctrl+Z and Up / Down are the box's own).
  var kbTyping = function (t) {
    if (!t || !t.tagName) {
      return false;
    }
    if (t.isContentEditable || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') {
      return true;
    }
    return t.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|hidden|file|image)$/.test(t.type || 'text');
  };
  var kbInGrid = function (t) { return !!(kb.grid && t && t.nodeType === 1 && kb.grid.table.contains(t)); };
  var kbCell = function (t) { return kbInGrid(t) && t.hasAttribute('data-kg-cell'); };
  var kbBox = function (t) {
    var found = null;
    each(kb.boxes, function (b) { if (b.input === t) { found = b; } });
    return found;
  };
  // The panel "Keyboard shortcuts": only the keys this page has (data-kb-when: grid, list, new, pdf), a group with none left out; Esc,
  // Close or a press outside its box shuts it, and the cursor goes back where it was.
  var kbPanel = document.querySelector('[data-kb-panel]');
  var kbBack = null;
  var kbRows = function () {
    return Array.prototype.filter.call(document.querySelectorAll('table[data-kb-rows] a.row-link'), function (a) { return !a.closest('[hidden]'); });
  };
  var kbShut = function () {
    if (!kbPanel || kbPanel.hidden) {
      return;
    }
    kbPanel.hidden = true;
    if (kbBack && kbBack.focus) {
      kbBack.focus();
    }
    kbBack = null;
  };
  var kbOpen = function () {
    if (!kbPanel) {
      return;
    }
    var has = { grid: !!kb.grid, list: kbRows().length > 0, 'new': !!kbPanel.querySelector('a[data-key-new]'), pdf: !!document.querySelector('a[data-key-print]') };
    // A grid that names its keys (a delivery's, docs/decisions.md GW2) shows only those of the Lines group.
    var own = kb.grid && kb.grid.keys ? kb.grid.keys : null;
    each(kbPanel.querySelectorAll('[data-kb-when]'), function (r) {
      var when = r.getAttribute('data-kb-when');
      r.hidden = !has[when] || (when === 'grid' && own !== null && own.indexOf(r.getAttribute('data-kb-key') || '') < 0);
    });
    each(kbPanel.querySelectorAll('[data-kb-group]'), function (g) { g.hidden = g.querySelector('.kb-row:not([hidden])') === null; });
    kbBack = document.activeElement;
    kbPanel.hidden = false;
    var close = kbPanel.querySelector('[data-kb-close]');
    if (close) {
      close.focus();
    }
  };
  if (kbPanel) {
    each(kbPanel.querySelectorAll('[data-kb-close]'), function (b) { b.addEventListener('click', kbShut); });
    kbPanel.addEventListener('click', function (ev) {
      if (ev.target === kbPanel) {
        kbShut(); // a press on the backdrop
      }
    });
    each(document.querySelectorAll('[data-kb-open]'), function (b) { b.addEventListener('click', kbOpen); });
  }
  // A list's rows (the orders, the invoices): Up / Down mark one (in sight), Enter opens it (its link: the unsaved-changes guard asks first).
  var kbMark = function (by) {
    var rows = kbRows();
    each(document.querySelectorAll('tr.kb-on'), function (tr) { tr.classList.remove('kb-on'); });
    kb.row = Math.max(0, Math.min(rows.length - 1, kb.row < 0 ? (by > 0 ? 0 : rows.length - 1) : kb.row + by));
    var tr = rows[kb.row].closest('tr');
    if (tr) {
      tr.classList.add('kb-on');
      if (tr.scrollIntoView) {
        tr.scrollIntoView({ block: 'nearest' });
      }
    }
  };
  var kbClick = function (sel) {
    var a = document.querySelector(sel);
    if (a) {
      a.click(); // a link pressed: the page's guard of unsaved changes asks first, as for a press with the mouse
    }
    return !!a;
  };
  var kbSearch = function () {
    var box = document.querySelector('main input[data-page-search]') || document.querySelector('main form.toolbar input[type="search"]') || document.getElementById('side-find');
    var fold = box && box.closest ? box.closest('details') : null;
    if (fold && !fold.open) {
      fold.open = true; // SX5: a folded search is opened first
    }
    if (box) {
      box.focus();
      if (box.select) {
        box.select();
      }
    }
    return !!box;
  };
  // The table of keys: the first whose test holds runs (its default action stopped), unless it answers false (not for it after all).
  var KEYS = [
    // Lines (the order's and the invoice's grid): only inside the lines, but Save, the undo and "+ Add new".
    { id: 'take', test: function (ev, t) {
      var b = kbBox(t);
      return b !== null && kbInGrid(t) && ev.key === 'Tab' && kbIs(ev, false, false, false) && (b.marked ? b.marked() : kb.grid.marked()) !== null;
    }, run: function (ev, t) {
      var b = kbBox(t);
      if (b.take) {
        b.take();
      } else {
        kb.grid.takeMarked();
      }
    } },
    { id: 'next', test: function (ev, t) { return kbCell(t) && ev.key === 'Enter' && kbIs(ev, false, false, false); }, run: function (ev, t) { kb.grid.step(t, 1); } },
    { id: 'updown', test: function (ev, t) { return kbCell(t) && (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') && kbIs(ev, false, false, false); },
      run: function (ev, t) { return kb.grid.vertical(t, ev.key === 'ArrowDown' ? 1 : -1); } }, // false: the box's own (a delivery's selects, GW2)
    { id: 'new_line', test: function (ev, t) {
      return kbInGrid(t) && ((ev.key === 'Enter' && kbIs(ev, true, false, false)) || (ev.code === 'KeyN' && kbIs(ev, false, true, false))
        || (ev.key === 'Insert' && kbIs(ev, false, false, false)));
    }, run: function () { kb.grid.toEmpty(); } },
    { id: 'delete_line', test: function (ev, t) {
      return kbInGrid(t) && ((ev.key === 'Delete' && kbIs(ev, true, false, false)) || (kbMac && ev.key === 'Backspace' && kbIs(ev, true, false, false))
        || (ev.code === 'KeyR' && kbIs(ev, false, true, false)));
    }, run: function (ev, t) { return kb.grid.removeAt(t); } },
    { id: 'undo_line', test: function (ev, t) {
      return !!kb.grid && kb.grid.canUndo() && ((ev.code === 'KeyU' && kbIs(ev, false, true, false)) || (ev.code === 'KeyZ' && kbIs(ev, true, false, false) && !kbTyping(t)));
    }, run: function () { kb.grid.undo(); } },
    { id: 'fill_down', test: function (ev, t) { return kbInGrid(t) && ev.code === 'KeyD' && kbIs(ev, true, false, false); }, run: function (ev, t) { return kb.grid.fillDown(t); } },
    { id: 'add_new', test: function (ev, t) { return kbBox(t) !== null && ev.code === 'KeyC' && kbIs(ev, false, true, false); }, run: function (ev, t) { return kbBox(t).addNew(); } },
    { id: 'save', test: function (ev) { return !!kb.grid && ev.code === 'KeyS' && kbIs(ev, true, false, false); }, run: function () { kb.grid.save(); } },
    // (a grid whose boxes are not data-kg-cell names them itself, kb.grid.own: the quick adjustment's, AJ1)
    { id: 'esc', test: function (ev, t) { return ev.key === 'Escape' && (kbCell(t) || kbBox(t) !== null || (kbInGrid(t) && !!kb.grid.own && kb.grid.own(t))); },
      run: function (ev, t) { t.blur(); } },
    // Page: every page.
    { id: 'new_doc', test: function (ev) { return ev.code === 'KeyB' && kbIs(ev, true, false, false); }, run: function () { return kbClick('a[data-key-new]'); } },
    { id: 'search', test: function (ev, t) { return (ev.code === 'KeyG' && kbIs(ev, true, false, false)) || (ev.key === '/' && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !kbTyping(t)); },
      run: function () { return kbSearch(); } },
    { id: 'pdf', test: function (ev) { return ev.code === 'KeyP' && kbIs(ev, true, false, false); }, run: function () { return kbClick('a[data-key-print]'); } }, // else the browser prints
    { id: 'help', test: function (ev, t) {
      return !!kbPanel && ((ev.key === '?' && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !kbTyping(t)) || (ev.code === 'Slash' && kbIs(ev, true, false, false)));
    }, run: function () { kbOpen(); } },
    { id: 'rows', test: function (ev, t) { return (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') && kbIs(ev, false, false, false) && !kbTyping(t) && kbRows().length > 0; },
      run: function (ev) { kbMark(ev.key === 'ArrowDown' ? 1 : -1); } },
    { id: 'open_row', test: function (ev, t) {
      return ev.key === 'Enter' && kbIs(ev, false, false, false) && kb.row >= 0 && !(t && t.closest && t.closest('a, button, input, select, textarea, summary'));
    }, run: function () {
      var rows = kbRows();
      if (!rows[kb.row]) {
        return false;
      }
      rows[kb.row].click();
      return true;
    } },
  ];
  document.addEventListener('keydown', function (ev) {
    if (ev.defaultPrevented || ev.isComposing) {
      return; // the dropdown, or a box, took the key
    }
    var t = ev.target;
    if (kbPanel && !kbPanel.hidden) {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        kbShut();
      }
      return; // nothing else while the panel is open
    }
    for (var i = 0; i < KEYS.length; i++) {
      if (KEYS[i].test(ev, t) && KEYS[i].run(ev, t) !== false) {
        ev.preventDefault();
        return;
      }
    }
  });

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
        if (kb.busy) {
          input.value = shown();
          return; // a save is on its way: the supplier stays as it was sent (the Codex re-check of 48dc75b, finding 4)
        }
        if (li.getAttribute('data-add') === '1') {
          var typed = input.value === shown() ? '' : input.value.trim();
          input.value = shown();
          openPanel(panel, function (p) {
            var name = p.querySelector('input[name="new_supplier_name"]');
            if (name && name.value === '' && typed !== '') { name.value = typed; }
          });
          return;
        }
        var value = li.getAttribute('data-value') || '';
        if (value !== select.value && select.value !== '' && hasLines() && select.getAttribute('data-change-ask')) {
          askChange(value);
          var chosen = Array.prototype.filter.call(select.options, function (x) { return x.value === value; })[0];
          input.value = chosen ? nameOf(chosen) : shown(); // the supplier asked about, until Keep puts the one chosen before back (S6)
          return;
        }
        set(value);
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
    var set = function (value) {
      if (kb.busy) {
        input.value = shown();
        return; // a save is on its way: the supplier stays as it was sent (KG33, KG35)
      }
      select.value = value;
      input.value = shown();
      if (panel) {
        panel.open = false;
      }
      select.dispatchEvent(new window.Event('change', { bubbles: true }));
    };
    // Another supplier with lines on the page (docs/decisions.md KG30; the owner, 6 Nov 2026: "also in draft and new easily supplier
    // change option"): asked in the page, never a browser dialog, "Change supplier to X? Lines stay; prices come from X where it has them."
    // [Change] [Keep Y] (the words from the page). Change sets the box (the save moves the lines: the server's rule); Keep, Esc or a
    // press elsewhere leaves the supplier as it was.
    var asking = null;
    var shutAsk = function () {
      if (asking && asking.parentNode) {
        asking.parentNode.removeChild(asking);
      }
      asking = null;
    };
    var hasLines = function () {
      var f = select.form;
      return !!f && Array.prototype.some.call(f.querySelectorAll('tr[data-kg-row]:not([data-kg-charge])'), function (tr) { return !tr.hidden; });
    };
    var askChange = function (value) {
      shutAsk();
      var o = Array.prototype.filter.call(select.options, function (x) { return x.value === value; })[0];
      var name = o ? nameOf(o) : '';
      var box = document.createElement('div');
      box.className = 'kg-confirm';
      box.setAttribute('role', 'group');
      box.setAttribute('data-kg-confirm', '');
      var p = document.createElement('p');
      p.setAttribute('aria-live', 'polite');
      p.textContent = (select.getAttribute('data-change-ask') || '').replace('%s', name).replace('%s', name);
      var yes = document.createElement('button');
      yes.type = 'button';
      yes.className = 'btn sm primary';
      yes.setAttribute('data-kg-change', '');
      yes.textContent = select.getAttribute('data-change-yes') || '';
      var keep = document.createElement('button');
      keep.type = 'button';
      keep.className = 'btn ghost sm';
      keep.setAttribute('data-kg-keep', '');
      keep.textContent = (select.getAttribute('data-change-keep') || '').replace('%s', shown());
      yes.addEventListener('click', function () {
        if (kb.busy) {
          return; // a save is on its way: asked again after it (the Codex re-check of 54cf4cf, finding C)
        }
        shutAsk();
        set(value);
        input.focus();
      });
      keep.addEventListener('click', function () {
        shutAsk();
        input.value = shown();
        input.focus();
      });
      box.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
          ev.preventDefault();
          keep.click();
        }
      });
      box.appendChild(p);
      box.appendChild(yes);
      box.appendChild(keep);
      select.parentNode.insertBefore(box, select);
      asking = box;
      yes.focus();
    };
    var c = combo(input, list, cfg);
    draw();
    // Alt+C in the box (Tally's "create on the fly"): "+ Add new supplier" with what was typed, for who may add one.
    if (select.getAttribute('data-add')) {
      input.setAttribute('aria-keyshortcuts', 'Alt+C');
    }
    kb.boxes.push({ input: input, addNew: function () {
      var add = list.querySelector('li[data-add="1"]');
      if (!add) {
        return false;
      }
      c.choose(add);
      return true;
    } });
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
        if (document.activeElement !== input && !asking) {
          input.value = shown(); // no choice made: the box names the supplier chosen (while the question is asked, the one asked about)
        }
      }, 200);
    });
  });

  // What a save without reload says (docs/decisions.md KG24; a delivery's too, GW7): one line at the bottom of the screen, made once.
  // It goes as the layout's message after a change does (p.toast, further down: 8 s, a press on it at once, never under the mouse): the
  // staging look of 10 Nov 2026 found "Saved" still over the page's corner minutes later. Words that must be read stay: a refusal's (bad)
  // until a press on them or the next words, and "Saving" (stays) for as long as the save is on its way.
  var kgToastTimer = 0;
  var kgToastHeld = false; // the mouse is on it
  var kgToastFades = false; // the words on show are ones that go by themselves
  var kgToastWaits = false; // the words on show say a save is on its way ("Saving"): only its answer takes their place
  var kgToastGone = function () {
    var t = document.querySelector('p.toast[data-kg-toast]');
    if (t && !kgToastHeld) {
      t.classList.add('is-gone');
    }
  };
  var kgToast = function (text, bad, stays) {
    var t = document.querySelector('p.toast[data-kg-toast]');
    if (!t) {
      t = document.createElement('p');
      t.setAttribute('data-kg-toast', '1');
      document.body.appendChild(t);
      t.addEventListener('mouseenter', function () { kgToastHeld = true; });
      t.addEventListener('mouseleave', function () {
        kgToastHeld = false;
        if (kgToastFades) {
          window.clearTimeout(kgToastTimer);
          kgToastTimer = window.setTimeout(kgToastGone, 3000);
        }
      });
      t.addEventListener('click', function () {
        if (kgToastWaits) {
          return; // a press does not hide that the save is still on its way (Codex re-check 8)
        }
        kgToastHeld = false;
        window.clearTimeout(kgToastTimer);
        t.classList.add('is-gone');
      });
    }
    window.clearTimeout(kgToastTimer); // words said earlier never fade the newer ones
    t.className = bad ? 'toast error' : 'notice toast'; // (on show again: is-gone goes with the old class)
    t.setAttribute('role', bad ? 'alert' : 'status');
    t.textContent = text;
    kgToastWaits = !bad && !!stays;
    kgToastFades = !bad && !stays;
    if (kgToastFades) {
      kgToastTimer = window.setTimeout(kgToastGone, 8000);
    }
  };
  // An answer put in place names the tab as the page it is now (fragment() hands the answer's <title> over as data-kg-title): a new record's
  // first save turns the page into its draft, the address bar and the tab's name with it. An answer without a name changes nothing.
  var kgTitle = function (part) {
    var title = part.getAttribute('data-kg-title') || '';
    if (title !== '' && document.title !== title) {
      document.title = title;
    }
  };
  // While a save is on its way the form's boxes are read-only (and its lists, files and ticks switched off), so what the answer puts in place
  // is what was sent, never a later edit lost under it (the Codex review of 04421ad, finding 5): hold(true), then hold(false) with the answer.
  var kgHold = function (form) {
    var held = [];
    var disabledHere = [];
    return function (on) {
      kb.busy = on;
      if (on) {
        each(form.querySelectorAll('input, textarea, select'), function (el) {
          if (el.type === 'hidden') {
            return;
          }
          if (el.tagName === 'SELECT' || /^(file|checkbox|radio)$/.test(el.type || '')) {
            if (!el.disabled) {
              el.disabled = true;
              disabledHere.push(el);
            }
          } else if (!el.readOnly) {
            el.readOnly = true;
            held.push(el);
          }
        });
        form.setAttribute('aria-busy', 'true');
        return;
      }
      each(held, function (el) { el.readOnly = false; });
      each(disabledHere, function (el) { el.disabled = false; });
      held = [];
      disabledHere = [];
      form.removeAttribute('aria-busy');
    };
  };

  // The lines of a document as a standard grid (docs/decisions.md KG1-KG26; the owner, 6 Nov 2026: "purchase invoice and sales invoice,
  // purchase order view style need to standard feel, need also key shortcut ...", "make ajax", "save all in ajax; row wise delete option and
  // proper validation", "each row within same place edit option", "keep style erpnext"): the order's and the supplier invoice's page, later
  // the sales invoice's, one engine for all (what each page has is in its data-kg-* attributes). It is started by the product box of the
  // grid's empty last line (box: that box and what it does; its dropdown is the site's one, combo()). With the script nothing of the lines
  // is ever posted until Save, and Save itself is sent without leaving the page:
  //  - a choice in the empty line (or a scan with Enter that the products route answers with one item) is a new line, filled in place from
  //    the page's row template (the item's name and CW number, its pack, 1, the supplier's price), with a short soft highlight; a scan keeps
  //    the cursor in the box for the next one (the same item scanned again: one pack more, as Add does), a choice puts it in the line's Qty;
  //  - every box of every line is edited in place, the item too: a line's item box is the site's one dropdown (its own list), and a saved
  //    line whose item is changed is sent as a new line that takes its place (`line`: its number; its remove field sent);
  //  - a line's x, its keys, or the lines ticked and "Delete" remove lines at once (they fade out; "Line 3 removed." with Undo); "+ Add row"
  //    goes to the empty line; "Add several" ticks items of one search and adds them as lines; "+ Add new item" makes the item by the
  //    new-item route (PoAddNew::newItemLine: never the document) and adds it as a line;
  //  - each line's amount and the totals follow the boxes; the boxes are checked as the person leaves them and on Save (the server's own
  //    rules, said in the page's words; a rule the server does not have only warns, never stops);
  //  - Save (Ctrl+S) sends the form by the one fetch and puts the server's answer in place (the lines, the totals, the version, the
  //    messages; a new document's address); a refusal comes back the same way with every line kept and its words on its line; anything
  //    else changes nothing and says so, and the next Save is a plain post. Confirm and "make final" stay plain posts.
  // The site's keys (kb, KEYS) call into kb.grid. Every word comes from the page (data-kg-*, data-words, the template's labels).
  var docGrid = function (table, box) {
    var form = box.input.form;
    var body = table.tBodies[0];
    var empty = body ? body.querySelector('tr[data-kg-empty]') : null;
    var section = table.closest('section') || form;
    var tpl = section ? section.querySelector('template[data-kg-row]') : null;
    var field = form ? form.querySelector('input[data-kg-field]') : null;
    var undoLine = section ? section.querySelector('[data-kg-undo-line]') : null;
    var undoText = undoLine ? undoLine.querySelector('[data-kg-undo-text]') : null;
    var undoButton = undoLine ? undoLine.querySelector('button[data-kg-undo]') : null;
    if (!form || !body || !empty || !tpl || !tpl.content || !tpl.content.firstElementChild || !field || !undoLine || !undoText || !undoButton) {
      return null;
    }
    var word = function (name) { return table.getAttribute('data-kg-' + name) || ''; };
    var max = parseInt(word('max'), 10) || 0;
    var maxQty = parseInt(word('max-qty'), 10) || 1000000;
    var minQty = parseInt(word('min-qty'), 10) || 0;
    var removeName = word('remove');
    var rates = {};
    try {
      rates = JSON.parse(word('rates') || '{}') || {};
    } catch (e) {
      rates = {};
    }
    // The page's other parts, read when needed (a save puts the server's answer in their place).
    var q = function (sel) { return form.querySelector(sel) || document.querySelector(sel); };
    var supplier = function () { return document.getElementById('po-supplier'); };
    var motion = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    // The lines on show, in order (a saved line removed is hidden until it is sent; a line fading out is gone already; not the empty line).
    var lines = function () {
      return Array.prototype.filter.call(body.querySelectorAll('tr[data-kg-row]'), function (tr) { return !tr.hidden && !tr.hasAttribute('data-kg-gone'); });
    };
    var boxesOf = function (tr) { return tr.querySelectorAll('input[data-kg-cell]'); };
    var cellIn = function (tr, cell) { return tr.querySelector('input[data-kg-cell="' + cell + '"]'); };
    var goTo = function (el) {
      if (!el) {
        return;
      }
      el.focus();
      if (el.select) {
        el.select();
      }
    };
    var dirty = function () {
      if (form.hasAttribute('data-unsaved')) {
        form.setAttribute('data-dirty', '1');
      }
    };
    // Figures as the services read them: a whole number of packs (PoLinesFile::wholeNumber), a pack's price in pounds with 4 decimals at
    // most (SupplierItems::packPrice, as 1/10000 of a pound), an amount in pounds and pence (signed where the page allows it).
    var whole = function (v) {
      var m = /^([0-9]{1,12})(?:\.0+)?$/.exec(String(v).replace(/,/g, '').trim());
      return m ? parseInt(m[1], 10) : null;
    };
    var e4 = function (v) {
      var m = /^([0-9]{0,10})(?:\.([0-9]{1,4}))?$/.exec(String(v).replace(/[,£\s ]/g, ''));
      if (!m || (m[1] === '' && m[2] === undefined)) {
        return null;
      }
      return parseInt(m[1] || '0', 10) * 10000 + parseInt(((m[2] || '') + '0000').slice(0, 4), 10);
    };
    var e2 = function (v, signed) {
      var m = /^(-?)([0-9]{1,10})(?:\.([0-9]{1,2}))?$/.exec(String(v).replace(/[,£\s ]/g, ''));
      if (!m || (m[1] === '-' && !signed)) {
        return null;
      }
      var n = parseInt(m[2], 10) * 100 + parseInt(((m[3] || '') + '00').slice(0, 2), 10);
      return m[1] === '-' ? -n : n;
    };
    var money = function (n) {
      var a = Math.abs(n);
      return (n < 0 ? '-' : '') + '£' + Math.floor(a / 100).toLocaleString('en-GB') + '.' + ('0' + (a % 100)).slice(-2);
    };
    // A line's amount in pence (packs x a pack's price, half-up to the penny: PoMath::lineAmountE2), or null while a box is not a figure;
    // a charge with a price only is one pack of it; a charge typed as an amount is that amount; a line without boxes, its saved amount.
    var amountOf = function (tr) {
      if (!tr.querySelector('input[data-kg-cell="qty"], input[data-kg-cell="price"], input[data-kg-cell="amount"]')) {
        var stored = tr.getAttribute('data-kg-e2') || ''; // a line shown as it is saved (too many for the form): the server's amount
        return /^-?[0-9]{1,15}$/.test(stored) ? parseInt(stored, 10) : null;
      }
      var amount = cellIn(tr, 'amount');
      if (amount) {
        return e2(amount.value, true);
      }
      var qty = cellIn(tr, 'qty');
      var price = cellIn(tr, 'price');
      var packs = qty ? whole(qty.value) : (price ? 1 : null);
      var p = price ? e4(price.value) : null;
      if (packs === null || p === null || packs > maxQty) {
        return null;
      }
      return Math.floor((packs * p + 50) / 100);
    };
    var isNew = function (tr) { return tr.hasAttribute('data-kg-new') || tr.hasAttribute('data-kg-swap'); };
    // The VAT a new line and the delivery charge take: the supplier's own (its option, on a new order), else the page's.
    var newVat = function () {
      var s = supplier();
      var o = s && s.selectedIndex >= 0 ? s.options[s.selectedIndex] : null;
      var own = o ? o.getAttribute('data-vat') || '' : '';
      return own !== '' && rates[own] ? own : word('vat');
    };
    var rateOf = function (code) { return rates[code] ? e2(rates[code].rate, false) : null; };
    var totals = function () {
      var sum = 0;
      var count = 0;
      var units = 0;
      var groups = [];
      var vat = function (code, rate, amount) {
        if (rate === null || code === '') {
          return;
        }
        var g = null;
        each(groups, function (x) { if (x.code === code && x.rate === rate) { g = x; } });
        if (!g) {
          g = { code: code, rate: rate, net: 0, vat: 0 };
          groups.push(g);
        }
        g.net += amount;
        g.vat += Math.floor((amount * rate + 5000) / 10000); // PoMath::lineVatE2, a line at a time
      };
      each(lines(), function (tr) {
        var a = amountOf(tr);
        var shown = tr.querySelector('[data-kg-amount]');
        if (shown && tr.querySelector('input[data-kg-cell]')) {
          shown.textContent = a === null ? '' : money(a);
        }
        count += 1;
        var qty = cellIn(tr, 'qty');
        var packs = qty ? whole(qty.value) : null;
        if (packs !== null && !tr.hasAttribute('data-kg-charge')) {
          units += packs * (parseInt(tr.getAttribute('data-kg-upp') || '1', 10) || 1);
        }
        if (a !== null) {
          sum += a;
          var code = isNew(tr) ? newVat() : tr.getAttribute('data-kg-vat') || '';
          vat(code, isNew(tr) ? rateOf(code) : e2(tr.getAttribute('data-kg-rate') || '', false), a);
        }
      });
      // The delivery typed: its own row of the taxes table (the demo the owner approved, KG31), at the supplier's VAT; in the grand total.
      var delivery = q('input[data-kg-delivery]');
      var charge = delivery ? e2(delivery.value, false) : null;
      var chargeVat = 0;
      if (delivery) {
        var dRate = rateOf(newVat());
        var dRow = delivery.closest('tr');
        var cellOf = function (k) { return dRow ? dRow.querySelector('[data-kg-delivery-' + k + ']') : null; };
        chargeVat = charge !== null && charge > 0 ? Math.floor((charge * dRate + 5000) / 10000) : 0;
        if (cellOf('rate')) {
          cellOf('rate').textContent = (dRate / 100).toFixed(2).replace(/0+$/, '').replace(/\.$/, '') + '%';
        }
        if (cellOf('vat')) {
          cellOf('vat').textContent = charge !== null && charge > 0 ? money(chargeVat) : '';
        }
        if (cellOf('total')) {
          cellOf('total').textContent = charge !== null && charge > 0 ? money(charge + chargeVat) : '';
        }
        if (dRow) {
          var dNo = dRow.querySelector('td.c-no');
          if (dNo) {
            dNo.textContent = String(groups.length + 1);
          }
        }
      }
      if (charge === null || charge <= 0) {
        charge = 0;
      }
      var put = function (key, text) {
        each(document.querySelectorAll('[data-kg-total="' + key + '"]'), function (el) {
          if (el.tagName === 'INPUT') {
            el.value = text;
          } else {
            el.textContent = text;
          }
        });
      };
      var countEl = document.querySelector('[data-kg-total="count"]');
      if (countEl) {
        countEl.textContent = (countEl.getAttribute(count === 1 ? 'data-one' : 'data-many') || '').replace('%s', count.toLocaleString('en-GB'))
          .replace('%s', units.toLocaleString('en-GB'));
      }
      put('units', units.toLocaleString('en-GB'));
      put('net', money(sum));
      put('sum', money(sum));
      var tax = 0;
      var rows = document.querySelector('[data-kg-vat-rows]');
      var seen = {};
      each(groups, function (g) { tax += g.vat; });
      if (rows) {
        // The taxes and charges table (ERPNext's): a row a VAT code (No., the code and its words, the rate, the net, the VAT, the total),
        // each cell named by its column's heading (a card on a phone).
        var heads = [];
        each(rows.parentNode.querySelectorAll('thead th'), function (th) { heads.push(th.textContent); });
        while (rows.firstChild) {
          rows.removeChild(rows.firstChild);
        }
        each(groups, function (g, i) {
          var key = seen[g.code] ? g.code + ' ' + (g.rate / 100).toFixed(2) + '%' : g.code;
          seen[g.code] = true;
          var label = key === g.code && rates[g.code] && rates[g.code].label ? rates[g.code].label
            : (g.rate / 100).toFixed(2).replace(/0+$/, '').replace(/\.$/, '') + '%';
          var tr = document.createElement('tr');
          each([String(i + 1), (rows.getAttribute('data-words') || '').replace('%s', key).replace('%s', label),
            (g.rate / 100).toFixed(2).replace(/0+$/, '').replace(/\.$/, '') + '%', money(g.net), money(g.vat), money(g.net + g.vat)], function (text, j) {
            var td = document.createElement(j === 1 ? 'th' : 'td');
            td.textContent = text;
            if (j !== 1) {
              td.className = j === 0 ? 'c-no' : 'num';
              td.setAttribute('data-label', heads[j] || '');
            }
            tr.appendChild(td);
          });
          rows.appendChild(tr);
        });
      }
      put('vat', money(tax + chargeVat));
      put('gross', money(sum + tax + charge + chargeVat));
      var paperNet = q('input[name="net_total"]');
      var paperVat = q('input[name="vat_total"]');
      if (paperNet || paperVat) { // the invoice: the paper's figures when typed, else the lines (InvoiceOnePage::editPage)
        var net = paperNet ? e2(paperNet.value, false) : null;
        var pv = paperVat ? e2(paperVat.value, false) : null;
        put('total', money((net === null ? sum : net) + (pv === null ? 0 : pv)));
      }
    };
    var renumber = function () {
      var all = lines();
      each(all, function (tr, i) {
        var no = tr.querySelector('[data-kg-no]');
        if (no) {
          var t = no.querySelector('input[data-kg-tick]');
          no.textContent = String(i + 1); // the number; the line's tick (in the same cell) put back before it, as it was
          if (t) {
            no.insertBefore(t, no.firstChild);
          }
        }
      });
      var last = empty.querySelector('[data-kg-no]');
      if (last) {
        last.textContent = String(all.length + 1);
      }
    };
    // The lines removed last (one removal, or the lines ticked), until the next removal or a save: Undo puts them back where they were.
    var undo = null;
    var hideUndo = function () {
      undoLine.hidden = true;
      undoText.textContent = '';
    };
    var removable = function (tr) {
      return !!tr && tr !== empty && tr.hasAttribute('data-kg-row') && !tr.hidden && (tr.hasAttribute('data-kg-new') || tr.querySelector('[data-kg-x]') !== null);
    };
    // A line out of the lines at once (the numbers, the totals), faded out, then gone: a new line from the page, a saved one hidden with its
    // remove field sent with the form (the server leaves it out without reading it).
    var takeOut = function (tr) {
      var u = { tr: tr, before: tr.nextSibling, flag: null, timer: 0, off: [] };
      tr.setAttribute('data-kg-gone', '1');
      if (!tr.hasAttribute('data-kg-new')) {
        var no = tr.getAttribute('data-kg-line') || '';
        // Its boxes are not sent, only its remove field: a long document's removals stay inside PHP's max_input_vars (the Codex review,
        // finding 10); Undo sends them again.
        u.off = offBoxes(tr);
        var flag = tr.querySelector('input[data-kg-flag]');
        if (!flag && removeName !== '' && /^[1-9][0-9]*$/.test(no)) {
          flag = document.createElement('input');
          flag.type = 'hidden';
          flag.name = removeName.replace('%d', no);
          flag.value = '1';
          flag.setAttribute('data-kg-flag', '1');
          tr.appendChild(flag);
          u.flag = flag;
        }
      }
      var end = function () {
        u.timer = 0;
        tr.removeAttribute('data-kg-gone');
        if (tr.hasAttribute('data-kg-new')) {
          if (tr.parentNode) {
            tr.parentNode.removeChild(tr);
          }
        } else {
          tr.hidden = true;
        }
        tr.classList.remove('kg-fade');
      };
      if (motion) {
        tr.classList.add('kg-fade');
        u.timer = window.setTimeout(end, 180);
      } else {
        end();
      }
      return u;
    };
    // A removed saved line's named boxes switched off (not sent), all but a remove field; a list of them, to switch on again.
    var offBoxes = function (tr) {
      var off = [];
      each(tr.querySelectorAll('input[name], select[name], textarea[name]'), function (el) {
        if (!el.disabled && !el.hasAttribute('data-kg-flag') && !(removeName !== '' && el.name === removeName.replace('%d', tr.getAttribute('data-kg-line') || ''))) {
          el.disabled = true;
          off.push(el);
        }
      });
      return off;
    };
    var putBack = function (u) {
      if (u.timer) {
        window.clearTimeout(u.timer);
      }
      u.tr.removeAttribute('data-kg-gone');
      u.tr.classList.remove('kg-fade');
      if (u.flag && u.flag.parentNode) {
        u.flag.parentNode.removeChild(u.flag);
      }
      each(u.off || [], function (el) { el.disabled = false; });
      if (!u.tr.parentNode) {
        body.insertBefore(u.tr, u.before && u.before.parentNode === body ? u.before : empty);
      }
      u.tr.hidden = false;
    };
    var removeLines = function (trs, cell) {
      if (sending) {
        return; // a save is on its way (hold)
      }
      var all = lines();
      var gone = trs.filter(removable);
      if (gone.length === 0) {
        return;
      }
      var at = all.indexOf(gone[0]);
      var next = null;
      for (var i = all.indexOf(gone[gone.length - 1]) + 1; i < all.length && next === null; i++) {
        next = gone.indexOf(all[i]) < 0 ? all[i] : null;
      }
      if (undo) {
        each(undo.items, function (u) { if (u.timer) { window.clearTimeout(u.timer); } }); // the earlier removal stays done
      }
      undo = { items: gone.map(takeOut) };
      undoText.textContent = gone.length === 1 ? word('removed').replace('%s', String(at + 1)) : word('removed-many').replace('%s', String(gone.length));
      undoLine.hidden = false;
      dirty();
      renumber();
      totals();
      tick();
      goTo(next ? (cellIn(next, cell) || boxesOf(next)[0] || box.input) : box.input);
    };
    undoButton.addEventListener('click', function () {
      if (!undo || sending) {
        return; // nothing to put back, or a save on its way (hold)
      }
      var u = undo;
      undo = null;
      each(u.items, putBack);
      hideUndo();
      renumber();
      totals();
      twice(); // the same item on two lines said again (S4)
      goTo(boxesOf(u.items[0].tr)[0] || box.input);
    });
    // The lines ticked (ERPNext's way of taking out several at once): the head's box ticks every line, "Delete" shows while any is ticked.
    var tickAll = table.querySelector('input[data-kg-tick-all]');
    var tickDelete = section.querySelector('button[data-kg-delete]');
    var ticked = function () { return lines().filter(function (tr) { var t = tr.querySelector('input[data-kg-tick]'); return !!t && t.checked; }); };
    var tick = function () {
      var n = ticked().length;
      if (tickDelete) {
        tickDelete.hidden = n === 0;
        tickDelete.textContent = (n === 1 ? word('delete-one') : word('delete-many')).replace('%s', String(n));
      }
      if (tickAll) {
        tickAll.checked = n > 0 && n === lines().filter(removable).length;
      }
    };
    if (tickAll) {
      tickAll.addEventListener('change', function () {
        each(lines(), function (tr) {
          var t = tr.querySelector('input[data-kg-tick]');
          if (t && removable(tr)) {
            t.checked = tickAll.checked;
          }
        });
        tick();
      });
    }
    if (tickDelete) {
      tickDelete.addEventListener('click', function () { removeLines(ticked(), 'qty'); });
    }
    // A line as the page knows it, to copy (Ctrl+D) or to compare (the same item twice): its item (data-si / data-sku / data-u and its
    // words, data-name...) and its figures.
    var itemOf = function (tr) {
      if (!tr || tr.getAttribute('data-name') === null || tr.hasAttribute('data-kg-charge')) {
        return null;
      }
      var qty = cellIn(tr, 'qty');
      var price = cellIn(tr, 'price');
      return { si: tr.getAttribute('data-si') || '', sku: tr.getAttribute('data-sku') || '', u: tr.getAttribute('data-u') || '',
        upp: tr.getAttribute('data-kg-upp') || '1', name: tr.getAttribute('data-name') || '', code: tr.getAttribute('data-code') || '',
        pack: tr.getAttribute('data-pack') || '', their: tr.getAttribute('data-their') || '', q: qty ? qty.value : '1', p: price ? price.value : '',
        stock: tr.getAttribute('data-stock') || '', coming: tr.getAttribute('data-coming') || '' };
    };
    var sameItem = function (a, b) { return !!a && !!b && (a.si !== '' ? a.si === b.si : (b.si === '' && a.sku === b.sku && (a.u || '1') === (b.u || '1'))); };
    // A line's item, put in its row: its attributes (what the server is sent) and its words (what the person sees: the item box, the CW code,
    // their code, the pack).
    var fill = function (tr, it) {
      tr.setAttribute('data-si', it.si);
      tr.setAttribute('data-sku', it.si !== '' ? '' : it.sku);
      tr.setAttribute('data-u', it.si !== '' ? '' : it.u);
      tr.setAttribute('data-kg-upp', it.upp || '1');
      each(['name', 'code', 'pack', 'their'], function (k) { tr.setAttribute('data-' + k, it[k]); });
      var item = cellIn(tr, 'item');
      if (item) {
        item.value = it.name;
      }
      var put = function (sel, text) {
        var el = tr.querySelector(sel);
        if (el) {
          el.textContent = text;
        }
        return el;
      };
      put('[data-kg-name]', it.name);
      put('[data-kg-code]', it.code);
      var their = put('[data-kg-their]', it.their);
      put('[data-kg-pack]', it.pack + (!their && it.their !== '' ? ' · ' + it.their : ''));
      // The item's current stock (KG34), from the choice (the products route's data-stock / data-coming); a save's answer draws the
      // server's figures in its place.
      var stockCell = tr.querySelector('[data-kg-stock]');
      if (stockCell && it.stock !== undefined) {
        tr.setAttribute('data-stock', it.stock || '');
        tr.setAttribute('data-coming', it.coming || '');
        while (stockCell.firstChild) {
          stockCell.removeChild(stockCell.firstChild);
        }
        var whole = function (v) { return /^-?[0-9]+$/.test(v || '') ? parseInt(v, 10) : null; };
        var onHand = whole(it.stock);
        var coming = whole(it.coming);
        if (onHand !== null) {
          stockCell.appendChild(document.createTextNode(onHand.toLocaleString('en-GB')));
          if (coming !== null && coming > 0) {
            var sub = document.createElement('span');
            sub.className = 'o-sub kg-coming';
            sub.textContent = word('coming').replace('%s', coming.toLocaleString('en-GB'));
            stockCell.appendChild(sub);
          }
        }
      }
    };
    // A line's item box (every box of every line is edited in place, KG22): the item's name in the site's one dropdown, its own list asked
    // of the products route as the empty line's is; a choice is the line's item (a saved line: replaced by a new line in its place, sent
    // with its remove field); "+ Add new item" opens that form for this line. Made when the box is first used.
    var made = 0;
    var itemBox = function (tr, input) {
      var list = document.createElement('ul');
      list.className = 'sm-list';
      list.id = 'kg-item-list-' + (++made);
      list.setAttribute('role', 'listbox');
      list.setAttribute('aria-label', box.list.getAttribute('aria-label') || '');
      list.hidden = true;
      var timer = 0;
      var turn = 0;
      var options = function () { return list.querySelectorAll('li[role="option"]:not([aria-disabled="true"])'); };
      var c = null;
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
          each(['data-label', 'data-si', 'data-sku', 'data-name', 'data-code', 'data-pack', 'data-upp', 'data-their', 'data-price', 'data-stock', 'data-coming'], function (name) {
            li.setAttribute(name, o.getAttribute(name) || '');
          });
          var part = function (cls) { var p = o.querySelector('.' + cls); return p ? p.textContent.trim() : ''; };
          twoLines(li, part('sm-opt-name'), part('sm-opt-meta'));
          list.appendChild(li);
        });
        lastLine(box.input, list, n);
        c.mark(-1);
      };
      c = combo(input, list, {
        options: options,
        choose: function (li) {
          if (li.getAttribute('data-add') === '1') {
            newItem.open(input.value.trim(), tr);
            return;
          }
          swap(tr, li);
        },
        enter: function (ev) {
          ev.preventDefault(); // Enter in a line's item box never sends the form; with no choice marked it goes on to the line's next box
          goTo(step(input, 1));
        },
      });
      // The keys' layer knows this box as it knows the empty line's (the Codex review of 04421ad, finding 11): Tab takes its marked choice,
      // Alt+C is "+ Add new item" with what was typed, for who may add one.
      var markedHere = function () { return list.hidden ? null : list.querySelector('li[aria-selected="true"]'); };
      kb.boxes.push({ input: input, marked: markedHere, take: function () { var m = markedHere(); if (m) { c.choose(m); } },
        addNew: function () {
          if (!box.input.getAttribute('data-add')) {
            return false;
          }
          newItem.open(input.value.trim(), tr);
          return true;
        } });
      input.addEventListener('input', function () {
        window.clearTimeout(timer);
        turn++;
        c.unshut();
        draw([]);
        var v = input.value.trim();
        if (v.length < 2) {
          return;
        }
        timer = window.setTimeout(function () {
          var mine = ++turn;
          fragment(box.base + box.keyFor(v), '[data-po-fragment]')
            .then(function (node) {
              if (mine === turn) {
                draw(node.querySelectorAll('ul[data-po-choices] > li[data-label]'));
                c.open();
              }
            })
            .catch(function () {
              if (mine === turn) {
                draw([]);
              }
            });
        }, 300);
      });
    };
    // A line's item changed in place: a new line's is replaced; a saved line is sent as a new line with its number (it takes its place).
    var swap = function (tr, li) {
      if (sending) {
        return; // a save is on its way (hold)
      }
      var a = function (name) { return li.getAttribute(name) || ''; };
      fill(tr, { si: a('data-si'), sku: a('data-sku'), u: a('data-upp'), upp: a('data-upp') || '1', name: a('data-name') || a('data-label'), code: a('data-code'),
        pack: a('data-pack'), their: a('data-their'), stock: a('data-stock'), coming: a('data-coming') }); // its stock too (KG34, KG35)
      if (!tr.hasAttribute('data-kg-new')) {
        tr.setAttribute('data-kg-swap', '1');
        var no = tr.getAttribute('data-kg-line') || '';
        if (!tr.querySelector('input[data-kg-flag]') && removeName !== '' && /^[1-9][0-9]*$/.test(no)) {
          var flag = document.createElement('input');
          flag.type = 'hidden';
          flag.name = removeName.replace('%d', no);
          flag.value = '1';
          flag.setAttribute('data-kg-flag', '1');
          tr.appendChild(flag);
        }
      }
      var price = cellIn(tr, 'price');
      if (price && a('data-price') !== '') {
        price.value = a('data-price');
      }
      flash(tr);
      dirty();
      totals();
      check(tr);
      goTo(cellIn(tr, 'qty') || price);
    };
    var flash = function (tr) {
      tr.classList.remove('kg-flash');
      if (motion) {
        tr.classList.add('kg-flash'); // a short soft highlight (app.css)
      }
    };
    // A line's own parts: its x removes it in place (without the script it is a submit: a save without that line), its tick box, its item box.
    var wire = function (tr) {
      if (tr.hasAttribute('data-kg-wired')) {
        return;
      }
      tr.setAttribute('data-kg-wired', '1');
      var x = tr.querySelector('[data-kg-x]');
      if (x) {
        x.tabIndex = -1; // Tab goes box to box; the x is a click (or Ctrl+Delete)
        x.addEventListener('click', function (ev) {
          ev.preventDefault();
          removeLines([tr], 'qty');
        });
      }
      var noCell = tr.querySelector('td.c-no');
      if (noCell && removable(tr) && !tr.querySelector('input[data-kg-tick]')) {
        var t = document.createElement('input');
        t.type = 'checkbox';
        t.className = 'kg-tick';
        t.tabIndex = -1;
        t.setAttribute('data-kg-tick', '1');
        t.setAttribute('aria-label', word('tick').replace('%s', noCell.textContent));
        t.addEventListener('change', tick);
        noCell.insertBefore(t, noCell.firstChild);
      }
      // The item, edited in place: the name becomes the item box; the item's page stays a link on its CW code.
      var name = tr.querySelector('.kg-name');
      if (name && removable(tr) && !tr.hasAttribute('data-kg-charge') && !cellIn(tr, 'item')) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'kg-item';
        input.value = tr.getAttribute('data-name') || name.textContent;
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('data-kg-cell', 'item');
        input.setAttribute('aria-label', word('item-label'));
        input.setAttribute('aria-keyshortcuts', 'Control+D Alt+C');
        var href = name.getAttribute('href');
        var code = tr.querySelector('.kg-code');
        if (href && code && code.tagName !== 'A') {
          var a = document.createElement('a');
          a.className = 'kg-code';
          a.href = href;
          a.tabIndex = -1;
          a.title = word('open');
          a.setAttribute('data-kg-code', '');
          a.textContent = code.textContent;
          code.parentNode.replaceChild(a, code);
        }
        name.parentNode.replaceChild(input, name);
        input.addEventListener('focus', function made1() {
          input.removeEventListener('focus', made1);
          itemBox(tr, input);
        });
      }
    };
    each(body.querySelectorAll('tr[data-kg-row]'), wire);
    table.addEventListener('input', function (ev) {
      if (ev.target && ev.target.hasAttribute && ev.target.hasAttribute('data-kg-cell')) {
        totals();
      }
    });
    form.addEventListener('input', function (ev) {
      var t = ev.target;
      if (t && (t.hasAttribute('data-kg-delivery') || t.name === 'net_total' || t.name === 'vat_total')) {
        totals();
      }
    });
    document.addEventListener('change', function (ev) {
      if (ev.target === supplier()) {
        totals();
      }
    });
    // Checks in the page (KG25): each box as it is left and every box on Save, by the server's own rules said in the page's words; the
    // server checks again and has the last word. `warn`: a rule the server does not have here only says so, it never stops a save.
    var say = function (el, text, warn) {
      var id = el.id || (el.id = 'kg-box-' + (++made));
      var msg = document.getElementById(id + '-msg');
      if (text === '') {
        el.removeAttribute('aria-invalid');
        el.classList.remove('kg-warned');
        if (msg) {
          msg.parentNode.removeChild(msg);
        }
        var d = (el.getAttribute('aria-describedby') || '').split(' ').filter(function (x) { return x !== '' && x !== id + '-msg'; }).join(' ');
        if (d === '') {
          el.removeAttribute('aria-describedby');
        } else {
          el.setAttribute('aria-describedby', d);
        }
        return;
      }
      if (!msg) {
        msg = document.createElement('span');
        msg.id = id + '-msg';
        el.parentNode.insertBefore(msg, el.nextSibling);
        el.setAttribute('aria-describedby', ((el.getAttribute('aria-describedby') || '') + ' ' + msg.id).trim());
      }
      msg.className = warn ? 'kg-msg warn' : 'kg-msg';
      msg.textContent = text;
      if (warn) {
        el.removeAttribute('aria-invalid');
        el.classList.add('kg-warned');
      } else {
        el.setAttribute('aria-invalid', 'true');
        el.classList.remove('kg-warned');
      }
    };
    var problem = function (el) {
      var msg = document.getElementById((el.id || '') + '-msg');
      return msg && msg.className.indexOf('warn') < 0 ? msg.textContent : '';
    };
    var check = function (tr) {
      var out = [];
      var keep = function (el, text, warn) {
        if (!el) {
          return;
        }
        say(el, text, warn);
        if (text !== '' && !warn) {
          out.push(el);
        }
      };
      var item = cellIn(tr, 'item');
      keep(item, item && item.value.trim() !== (tr.getAttribute('data-name') || '') ? word('v-item') : '', false);
      var qty = cellIn(tr, 'qty');
      if (qty) {
        var n = whole(qty.value);
        keep(qty, n === null || n < minQty || n > maxQty ? (minQty === 0 ? word('v-qty0') : word('v-qty').replace('%s', maxQty.toLocaleString('en-GB'))) : '', false);
      }
      var price = cellIn(tr, 'price');
      if (price) {
        var charge = tr.hasAttribute('data-kg-charge');
        keep(price, price.value.trim() !== '' && (charge ? e2(price.value, false) === null : e4(price.value) === null) ? word(charge ? 'v-money' : 'v-price') : '', false);
      }
      var amount = cellIn(tr, 'amount');
      keep(amount, amount && e2(amount.value, true) === null ? word('v-money') : '', false);
      return out;
    };
    // The same item on two lines: said on both (a warning; the server takes both).
    var twice = function () {
      var all = lines();
      each(all, function (tr, i) {
        var it = itemOf(tr);
        var other = -1;
        each(all, function (o, j) {
          if (j !== i && other < 0 && sameItem(it, itemOf(o))) {
            other = j;
          }
        });
        var el = cellIn(tr, 'item') || cellIn(tr, 'qty');
        if (el && problem(el) === '') {
          say(el, other >= 0 ? word('v-twice').replace('%s', String(other + 1)) : '', true);
        }
      });
    };
    table.addEventListener('focusout', function (ev) {
      var tr = ev.target && ev.target.closest ? ev.target.closest('tr[data-kg-row]') : null;
      if (tr && ev.target.hasAttribute('data-kg-cell')) {
        check(tr);
        twice();
      }
    });
    // The details: the supplier (needed: need_supplier), a real date (bad_date), the due date not before the invoice's (due_before_date);
    // the invoice's number is needed only to make it final: a warning here.
    var head = function () {
      var out = [];
      var s = supplier();
      var sbox = document.getElementById('po-supplier-box') || s;
      if (s && sbox && form.querySelector('select[name="supplier_id"]') === s) {
        say(sbox, s.value === '' ? word('v-supplier') : '', false);
        if (s.value === '') {
          out.push(sbox);
        }
      }
      var number = form.querySelector('input[name="invoice_number"]');
      if (number) {
        say(number, number.value.trim() === '' ? word('v-number') : '', true);
      }
      var date = form.querySelector('input[name="invoice_date"]');
      var due = form.querySelector('input[name="due_date"]');
      var real = function (v) { return v === '' || /^\d{4}-\d{2}-\d{2}$/.test(v); };
      each([date, due], function (d) {
        if (d) {
          say(d, real(d.value) ? '' : word('v-date'), false);
          if (!real(d.value)) {
            out.push(d);
          }
        }
      });
      if (date && due && due.value !== '' && date.value !== '' && real(due.value) && real(date.value) && due.value < date.value) {
        say(due, word('v-due'), false);
        out.push(due);
      }
      return out;
    };
    var look = form.querySelector('[data-kg-look]');
    // The page's checks before a save: the list at the top of the form ("2 things need a look", each a link to its box), the cursor on the first.
    var validate = function () {
      var bad = head();
      each(lines(), function (tr) { bad = bad.concat(check(tr)); });
      twice();
      // Words left in the empty line's box are not saved (a Save is not an Add): said, as a warning only (the server takes the Save).
      say(box.input, box.input.value.trim() !== '' ? word('v-item') : '', true);
      if (look) {
        while (look.firstChild) {
          look.removeChild(look.firstChild);
        }
        look.hidden = bad.length === 0;
        if (bad.length > 0) {
          var p = document.createElement('p');
          p.textContent = bad.length === 1 ? word('look-one') : word('look-many').replace('%s', String(bad.length));
          look.appendChild(p);
          var ul = document.createElement('ul');
          each(bad, function (el) {
            var tr = el.closest('tr[data-kg-row], tr[data-kg-empty]');
            var no = tr ? tr.querySelector('[data-kg-no]') : null;
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '#' + el.id;
            a.textContent = (no ? word('look-line').replace('%s', no.textContent.replace(/\D/g, '')) : word('look-top')) + ': ' + problem(el);
            a.addEventListener('click', function (ev) {
              ev.preventDefault();
              goTo(el);
            });
            li.appendChild(a);
            ul.appendChild(li);
          });
          look.appendChild(ul);
        }
      }
      if (bad.length > 0) {
        goTo(bad[0]);
      }
      return bad.length === 0;
    };
    // The next box after (or before) one, in the page's order: every line's boxes, then the empty line's Item box.
    var step = function (from, by) {
      var all = [];
      each(lines(), function (tr) { each(boxesOf(tr), function (b) { all.push(b); }); });
      all.push(box.input);
      var i = all.indexOf(from);
      return i < 0 ? null : all[Math.max(0, Math.min(all.length - 1, i + by))];
    };
    // A new line under the others, its item and figures given; the cursor in its Qty box (a scan: the Item box stays, for the next one).
    var addLine = function (it, qv, pv, stay) {
      if (sending) {
        return null; // a save is on its way (hold)
      }
      if (max > 0 && lines().length >= max) {
        box.say(word('too-many'));
        return null;
      }
      var tr = tpl.content.firstElementChild.cloneNode(true);
      body.insertBefore(tr, empty);
      wire(tr);
      fill(tr, it);
      var qty = cellIn(tr, 'qty');
      var price = cellIn(tr, 'price');
      if (qty) {
        qty.value = qv;
      }
      if (price) {
        price.value = pv;
      }
      flash(tr);
      dirty();
      renumber();
      totals();
      twice();
      if (!stay) {
        goTo(qty || price || box.input);
      }
      return tr;
    };
    var fromLi = function (li) {
      var a = function (name) { return li.getAttribute(name) || ''; };
      return { si: a('data-si'), sku: a('data-sku'), u: a('data-upp'), upp: a('data-upp') || '1', name: a('data-name') || a('data-label'), code: a('data-code'),
        pack: a('data-pack'), their: a('data-their'), price: a('data-price'), stock: a('data-stock'), coming: a('data-coming') };
    };
    // "+ Add new item" (KG21): the form's fields, sent by the one fetch to the page's new-item route (the item and its pack, never the
    // document; its own FormOnce key), and the item made added as a line (or, opened from a line's item box, put in that line); a refusal
    // said in the form with the items to use instead and, for the same name, "it is a different item".
    var newItem = (function () {
      var panel = form.querySelector('details[data-ni-url]');
      var target = null;
      var url = panel ? panel.getAttribute('data-ni-url') || '' : '';
      var send = panel ? panel.querySelector('button[name="action"][value="new_item"]') : null;
      var key = panel ? panel.querySelector('input[data-ni-key]') : null;
      var area = null;
      var clear = function () {
        if (area && area.parentNode) {
          area.parentNode.removeChild(area);
        }
        area = null;
      };
      var api = {
        open: function (typed, tr) {
          if (!panel) {
            return false;
          }
          target = tr || null;
          openPanel(panel, function (p) {
            var digits = /^[0-9 ]{6,64}$/.test(typed || '');
            var to = p.querySelector('input[name="' + (digits ? 'item_barcode' : 'item_name') + '"]');
            if (to && to.value === '' && typed) {
              to.value = digits ? typed.replace(/ /g, '') : typed;
            }
          });
          return true;
        },
      };
      if (!panel || !send || !key || url === '' || !window.FormData) {
        return api;
      }
      send.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (sending) {
          return; // a save is on its way (hold): the item is made after it
        }
        var data = new window.FormData();
        var csrf = form.querySelector('input[name="csrf"]');
        data.append('csrf', csrf ? csrf.value : '');
        data.append('form_key', key.value);
        var s = supplier();
        data.append('supplier_id', s ? s.value : (panel.getAttribute('data-supplier') || ''));
        each(panel.querySelectorAll('input[name^="item_"]'), function (i) {
          if (i.type !== 'checkbox' || i.checked) {
            data.append(i.name, i.value);
          }
        });
        send.disabled = true;
        fragment(url, '[data-ni-fragment]', data)
          .then(function (node) {
            send.disabled = false;
            clear();
            key.value = node.getAttribute('data-next-key') || key.value;
            var li = node.querySelector('ul[data-po-choices] > li[data-label]');
            if (li) {
              each(panel.querySelectorAll('input[name^="item_"]'), function (i) {
                if (i.type === 'checkbox') {
                  if (i.parentNode && i.hasAttribute('data-ni-made')) {
                    i.parentNode.parentNode.removeChild(i.parentNode);
                  }
                } else {
                  i.value = i.name === 'item_upp' ? '1' : '';
                }
              });
              panel.open = false;
              if (target && target.parentNode) {
                swap(target, li);
              } else {
                var it = fromLi(li);
                addLine(it, '1', it.price);
              }
              return;
            }
            // Refused: the words, the items to use instead, and the "it is a different item" box.
            area = document.createElement('div');
            area.className = 'kg-ni-says';
            var err = node.querySelector('[data-ni-error]');
            var p = document.createElement('p');
            p.className = 'error';
            p.setAttribute('role', 'alert');
            p.textContent = err ? err.textContent.trim() : '';
            area.appendChild(p);
            each(node.querySelectorAll('ul[data-ni-others] > li[data-label]'), function (o) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'btn sm';
              b.textContent = (err ? err.getAttribute('data-use') || '' : '') + ': ' + (o.getAttribute('data-label') || '');
              b.addEventListener('click', function () {
                clear();
                panel.open = false;
                if (target && target.parentNode) {
                  swap(target, o);
                } else {
                  var it = fromLi(o);
                  addLine(it, '1', it.price);
                }
              });
              area.appendChild(b);
            });
            var anyway = node.querySelector('[data-ni-anyway]');
            if (anyway && !panel.querySelector('input[name="item_anyway"]')) {
              var label = document.createElement('label');
              label.className = 'choice';
              var box2 = document.createElement('input');
              box2.type = 'checkbox';
              box2.name = 'item_anyway';
              box2.value = '1';
              box2.setAttribute('data-ni-made', '1');
              label.appendChild(box2);
              label.appendChild(document.createTextNode(' ' + anyway.textContent.trim()));
              area.appendChild(label);
            }
            panel.insertBefore(area, send);
          })
          .catch(function () {
            send.disabled = false;
            clear();
            area = document.createElement('div');
            area.className = 'kg-ni-says';
            var p = document.createElement('p');
            p.className = 'error';
            p.setAttribute('role', 'alert');
            p.textContent = word('item-failed');
            area.appendChild(p);
            panel.insertBefore(area, send);
          });
      });
      return api;
    })();
    // "Add several" (ERPNext's "Add multiple"): one search of the supplier's items (the products route), a tick each; "Add N lines" adds
    // the items ticked as lines (1 pack, the supplier's price), the ticks kept while the words change.
    var several = section.querySelector('[data-kg-several]');
    if (several) {
      var sq = several.querySelector('input[data-kg-several-q]');
      var sout = several.querySelector('ul[data-kg-several-list]');
      var sgo = several.querySelector('button[data-kg-several-add]');
      var picked = [];
      var sturn = 0;
      var stimer = 0;
      var count = function () {
        sgo.disabled = picked.length === 0;
        sgo.textContent = (picked.length === 1 ? sgo.getAttribute('data-one') : sgo.getAttribute('data-many') || '').replace('%s', String(picked.length));
      };
      var drawSeveral = function (from) {
        while (sout.firstChild) {
          sout.removeChild(sout.firstChild);
        }
        each(from, function (o) {
          var it = fromLi(o);
          var li = document.createElement('li');
          var label = document.createElement('label');
          label.className = 'choice';
          var t = document.createElement('input');
          t.type = 'checkbox';
          t.checked = picked.some(function (p) { return sameItem(p, it); });
          t.addEventListener('change', function () {
            picked = picked.filter(function (p) { return !sameItem(p, it); });
            if (t.checked) {
              picked.push(it);
            }
            count();
          });
          label.appendChild(t);
          label.appendChild(document.createTextNode(' ' + (o.getAttribute('data-label') || '')));
          li.appendChild(label);
          sout.appendChild(li);
        });
      };
      if (sq && sout && sgo) {
        sq.addEventListener('input', function () {
          window.clearTimeout(stimer);
          var v = sq.value.trim();
          var mine = ++sturn;
          if (v.length < 2) {
            drawSeveral([]);
            return;
          }
          stimer = window.setTimeout(function () {
            fragment(box.base + box.keyFor(v), '[data-po-fragment]')
              .then(function (node) {
                if (mine === sturn) {
                  drawSeveral(node.querySelectorAll('ul[data-po-choices] > li[data-label]'));
                }
              })
              .catch(function () {
                if (mine === sturn) {
                  drawSeveral([]);
                }
              });
          }, 300);
        });
        sq.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') {
            ev.preventDefault(); // the search's Enter never sends the form
          }
        });
        sgo.addEventListener('click', function () {
          if (sending) {
            return; // a save is on its way (hold): the ticks stay for after it
          }
          var first = null;
          each(picked, function (it) {
            var tr = addLine(it, '1', it.price, true);
            first = first || tr;
          });
          picked = [];
          count();
          sq.value = '';
          drawSeveral([]);
          several.hidden = true;
          goTo(first ? cellIn(first, 'qty') : box.input);
        });
        each(section.querySelectorAll('button[data-kg-several-open]'), function (b) {
          b.addEventListener('click', function () {
            several.hidden = !several.hidden;
            if (!several.hidden) {
              sq.focus();
            }
          });
        });
        each(several.querySelectorAll('button[data-kg-several-close]'), function (b) {
          b.addEventListener('click', function () {
            several.hidden = true;
            box.input.focus();
          });
        });
        count();
      }
    }
    each(section.querySelectorAll('button[data-kg-add-row]'), function (b) { b.addEventListener('click', function () { goTo(box.input); }); });
    each(section.querySelectorAll('button[data-kg-new-item]'), function (b) { b.addEventListener('click', function () { newItem.open(box.input.value.trim(), null); }); });
    // The buttons that only work with the script (the page draws them hidden).
    each(section.querySelectorAll('[data-kg-js]'), function (el) { el.hidden = false; });
    // Every new line in the one field, in order (a saved line whose item changed: a new line with its number).
    var serialise = function () {
      var out = [];
      each(body.querySelectorAll('tr[data-kg-new], tr[data-kg-swap]'), function (tr) {
        if (tr.hasAttribute('data-kg-gone') || (tr.hidden && tr.hasAttribute('data-kg-new'))) {
          return;
        }
        var si = tr.getAttribute('data-si') || '';
        var o = si !== '' ? { si: parseInt(si, 10) } : { sku: parseInt(tr.getAttribute('data-sku') || '0', 10) };
        var u = tr.getAttribute('data-u') || '';
        if (si === '' && /^[1-9][0-9]*$/.test(u) && u !== '1') {
          o.u = parseInt(u, 10);
        }
        if (tr.hasAttribute('data-kg-swap') && !tr.hidden) {
          o.line = parseInt(tr.getAttribute('data-kg-line') || '0', 10);
        } else if (tr.hasAttribute('data-kg-replace')) {
          o.line = parseInt(tr.getAttribute('data-kg-replace') || '0', 10); // a changed saved line drawn again after a refusal (KG32)
        } else if (tr.hasAttribute('data-kg-swap')) {
          return; // a saved line changed, then removed: its remove field says it all
        }
        var qty = cellIn(tr, 'qty');
        var price = cellIn(tr, 'price');
        o.q = qty ? qty.value.trim() : '';
        o.p = price ? price.value.trim() : '';
        out.push(o);
      });
      field.value = out.length > 0 ? JSON.stringify(out) : '';
    };
    // Save without leaving the page (KG24): the form sent by the one fetch, a POST to its own address (its CSRF token, FormOnce key, version);
    // the answer is accepted only when it is this page's editor (data-kg-editor: the same kind, the same document or a new one's first
    // save); then the parts it changes are put in place (the messages and the forms around the editor, the head, its hidden fields, the
    // lines, the totals) and the grid starts again on them, the cursor back on the same line and box; a new document's address is the
    // draft's. Anything else (no network, the sign-in page, another page) changes nothing, says so, and the next Save is a plain post.
    var plain = false;
    var sending = false;
    var editor = form.getAttribute('data-kg-editor') || '';
    var swapIn = function (part) {
      var nf = part.querySelector('form[data-kg-editor]');
      var mark = nf ? nf.getAttribute('data-kg-editor') || '' : '';
      var kind = function (m) { return m.split(':')[0]; };
      if (!nf || kind(mark) !== kind(editor) || (editor.split(':')[1] !== 'new' && mark !== editor)) {
        return false;
      }
      var active = document.activeElement;
      var at = -1;
      var cell = null;
      if (active && active.closest) {
        var atr = active.closest('tr[data-kg-row]');
        at = atr ? lines().indexOf(atr) : -1;
        cell = active.getAttribute('data-kg-cell');
      }
      // Around the editor: what comes before it (the head, the messages) and after it (the draft's other forms), as the server drew them.
      var parent = form.parentNode;
      var theirs = nf.parentNode;
      var swapSide = function (before) {
        var mine = [];
        var them = [];
        for (var el = before ? parent.firstChild : form.nextSibling; el && el !== (before ? form : null); el = el.nextSibling) {
          mine.push(el);
        }
        for (var el2 = before ? theirs.firstChild : nf.nextSibling; el2 && el2 !== (before ? nf : null); el2 = el2.nextSibling) {
          them.push(el2);
        }
        each(mine, function (el) { parent.removeChild(el); });
        each(them, function (el) {
          var copy = document.importNode(el, true);
          if (before) {
            parent.insertBefore(copy, form);
          } else {
            parent.appendChild(copy);
          }
          if (copy.querySelectorAll) {
            each(copy.querySelectorAll('form[method="post"]'), sendOnce);
            if (copy.tagName === 'FORM' && copy.getAttribute('method') === 'post') {
              sendOnce(copy);
            }
          }
        });
      };
      swapSide(true);
      swapSide(false);
      // In the editor: its address and marker, its hidden fields, the parts the server draws again.
      form.setAttribute('action', nf.getAttribute('action') || form.getAttribute('action'));
      form.setAttribute('data-kg-editor', mark);
      editor = mark;
      var hiddenOf = function (f) { return Array.prototype.filter.call(f.querySelectorAll('input[type="hidden"][name]'), function (i) { return i.parentNode === f; }); };
      var mineHidden = {};
      each(hiddenOf(form), function (i) { mineHidden[i.name] = i; });
      var anchor = form.querySelector('button.po-default');
      var theirsHidden = {};
      each(hiddenOf(nf), function (i) {
        theirsHidden[i.name] = true;
        if (mineHidden[i.name]) {
          mineHidden[i.name].value = i.value;
        } else {
          form.insertBefore(document.importNode(i, true), anchor || form.firstChild);
        }
      });
      // The fields the server no longer draws go too (a draft grown past what the editor carries has no line_count: the Codex review, finding 7).
      each(Object.keys(mineHidden), function (name) {
        if (!theirsHidden[name] && mineHidden[name].parentNode === form) {
          form.removeChild(mineHidden[name]);
        }
      });
      each(nf.querySelectorAll('[data-kg-swap-part]'), function (theirsPart) {
        var name = theirsPart.getAttribute('data-kg-swap-part');
        var minePart = form.querySelector('[data-kg-swap-part="' + name + '"]');
        if (minePart && (name !== 'header' || theirsPart.querySelector('select[data-po-supplier]') === null)) {
          minePart.parentNode.replaceChild(document.importNode(theirsPart, true), minePart);
        }
      });
      // A head kept as it is (its supplier box) still takes the invoice's "From" list the server drew for the supplier now (KG30).
      var theirFrom = nf.querySelector('select[data-pi-from]');
      var myFrom = form.querySelector('select[data-pi-from]');
      if (theirFrom && myFrom) {
        while (myFrom.firstChild) {
          myFrom.removeChild(myFrom.firstChild);
        }
        each(theirFrom.children, function (o) { myFrom.appendChild(document.importNode(o, true)); });
      }
      // The lines: the server's (saved, and any new line it kept), before the empty line.
      each(body.querySelectorAll('tr[data-kg-row]'), function (tr) { body.removeChild(tr); });
      var theirsBody = nf.querySelector('table[data-kg-grid] > tbody');
      if (theirsBody) {
        each(theirsBody.querySelectorAll('tr[data-kg-row]'), function (tr) { body.insertBefore(document.importNode(tr, true), empty); });
      }
      var theirsTable = nf.querySelector('table[data-kg-grid]');
      if (theirsTable) {
        each(['data-kg-max', 'data-kg-vat'], function (a) {
          if (theirsTable.getAttribute(a) !== null) {
            table.setAttribute(a, theirsTable.getAttribute(a));
          }
        });
      }
      undo = null;
      hideUndo();
      each(body.querySelectorAll('tr[data-kg-row]'), wire);
      each(body.querySelectorAll('tr[data-kg-line][hidden]'), offBoxes);
      renumber();
      tick();
      if (look) {
        look.hidden = true;
      }
      var all = lines();
      var back = at >= 0 && all[Math.min(at, all.length - 1)] ? all[Math.min(at, all.length - 1)] : null;
      goTo(back ? (cellIn(back, cell || '') || boxesOf(back)[0]) : box.input);
      var address = nf.getAttribute('data-kg-address') || '';
      if (address !== '' && window.history && window.history.replaceState && address.charAt(0) === '/') {
        window.history.replaceState(null, '', address);
      }
      kgTitle(part); // the tab's name with it (a new order's first save; a draft's supplier changed)
      document.dispatchEvent(new window.Event('kg-swapped')); // what lives outside the grid and reads the head (the posting time's button)
      return true;
    };
    var toast = kgToast;
    var canSend = function () { return !plain && !!window.FormData && !!window.fetch && !!window.DOMParser && !sending; };
    // The save itself: then(ok) after the answer is in place (ok: saved, no refusal).
    // While a save is on its way the page's boxes are read-only (and no line is added or removed): what the answer puts in place is what
    // was sent, never a later edit lost under it (the Codex review of 04421ad, finding 5).
    // Boxes are made read-only (the cursor stays); what read-only does not stop (a list, a file, a tick, a choice) is switched off, after
    // the form's data was taken (the Codex re-check of 48dc75b, finding 4). Every change by the script (a choice, fill down, a new item, add
    // several, a removal, Undo, the supplier) waits for the answer too (sending, kb.busy).
    var hold = kgHold(form);
    var ajaxSave = function (by, then) {
      serialise();
      var data = new window.FormData(form);
      data.set('action', 'save');
      sending = true;
      hold(true);
      if (by) {
        by.disabled = true;
      }
      toast(word('saving'), false, true);
      fragment(form.getAttribute('action') || '', '[data-kg-editor]', data)
        .then(function (part) {
          sending = false;
          hold(false);
          if (by) {
            by.disabled = false;
          }
          var answered = part.querySelector('form[data-kg-editor]');
          if (answered && answered.hasAttribute('data-kg-conflict')) {
            // Changed in another tab meanwhile (the Codex review, finding 6): the page drawn again as the server has it now (the head too),
            // by a plain post of the same form: refused again as changed, the new lines kept, nothing saved.
            plain = true;
            var again = by || form.querySelector('button[name="action"][value="save"]') || document.querySelector('button[form="' + form.id + '"][name="action"][value="save"]');
            if (form.requestSubmit && again) {
              form.requestSubmit(again);
            } else if (again) {
              again.click();
            }
            return;
          }
          if (!swapIn(part)) {
            throw new Error('not this page');
          }
          var error = form.parentNode.querySelector('p.error[role="alert"]');
          if (error) {
            toast(error.textContent.trim(), true);
          } else {
            toast(word('saved'), false);
            form.removeAttribute('data-dirty');
          }
          if (then) {
            then(!error);
          }
        })
        .catch(function () {
          sending = false;
          hold(false);
          if (by) {
            by.disabled = false;
          }
          plain = true;
          toast(word('save-failed'), true);
        });
    };
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) {
        return; // stopped before it (the posting time half typed, KG35): nothing sent
      }
      serialise();
      var by = ev.submitter;
      var isSave = !!by && by.getAttribute('name') === 'action' && by.getAttribute('value') === 'save';
      if (isSave && !validate()) {
        ev.preventDefault(); // the page's checks first: nothing is sent
        return;
      }
      undo = null;
      hideUndo();
      if (!isSave || !canSend()) {
        return; // Confirm, "make final" and the others are plain posts; after a failed Save, so is the next one
      }
      ev.preventDefault();
      ajaxSave(by, null);
    }, true);
    // The saved lines drawn removed after a refusal: their boxes off too (only their remove field is sent; finding 10).
    each(body.querySelectorAll('tr[data-kg-line][hidden]'), offBoxes);
    renumber();
    tick();
    // What the site's keys do in the lines (kb, KEYS): docGrid is their one place.
    kb.grid = {
      table: table,
      marked: function () { return box.marked(); },
      takeMarked: function () { box.choose(box.marked()); },
      step: function (from, by) { goTo(step(from, by)); }, // Enter in a box: the next box; never the form sent
      // Up / Down: the same box of the line above or below (a line without it is passed; past the last: the Item box).
      vertical: function (from, by) {
        var tr = from.closest('tr');
        var cell = from.getAttribute('data-kg-cell');
        var all = lines();
        var i = all.indexOf(tr) + by;
        while (i >= 0 && i < all.length && !cellIn(all[i], cell)) {
          i += by;
        }
        if (i >= 0 && i < all.length) {
          goTo(cellIn(all[i], cell));
        } else if (by > 0) {
          goTo(box.input);
        }
      },
      toEmpty: function () { goTo(box.input); }, // a new line: the empty line's Item box
      removeAt: function (from) { // the line the cursor is on (not the empty line, nor a line shown as saved)
        var tr = from.closest('tr');
        if (!removable(tr)) {
          return false;
        }
        removeLines([tr], from.getAttribute('data-kg-cell') || 'qty');
        return true;
      },
      canUndo: function () { return undo !== null; },
      undo: function () { undoButton.click(); },
      // Ctrl+D (the "fill down" of a spreadsheet): the line above copied into this one: in the empty line a new line, in a line its item and
      // figures (a saved line's item: replaced in its place, as a change of its item box); the cursor in its Qty box.
      fillDown: function (from) {
        if (sending) {
          return; // a save is on its way (hold)
        }
        var tr = from.closest('tr');
        var all = lines();
        var at = tr === empty ? all.length : all.indexOf(tr);
        var src = at > 0 ? itemOf(all[at - 1]) : null;
        if (!src) {
          return;
        }
        if (tr === empty) {
          box.clear();
          addLine(src, src.q, src.p);
          return;
        }
        if (tr.hasAttribute('data-kg-new') || cellIn(tr, 'item')) {
          var li = document.createElement('li');
          each(['si', 'sku', 'name', 'code', 'pack', 'their'], function (k) { li.setAttribute('data-' + k, src[k]); });
          li.setAttribute('data-upp', src.u || src.upp);
          if (!sameItem(itemOf(tr), src)) {
            swap(tr, li);
          }
        }
        var qty = cellIn(tr, 'qty');
        var price = cellIn(tr, 'price');
        if (qty) {
          qty.value = src.q;
        }
        if (price) {
          price.value = src.p;
        }
        dirty();
        totals();
        goTo(qty || price || from);
      },
      // The draft saved first when it holds anything not saved (or is not made yet), then $fn (the timed route of the posting time, KG27).
      saveThen: function (fn) {
        if (kb.postingBad && kb.postingBad()) {
          return; // the posting time half typed (KG35)
        }
        if (!form.hasAttribute('data-dirty') && (form.getAttribute('data-kg-editor') || '').indexOf(':new') < 0) {
          fn();
          return;
        }
        if (!validate() || !canSend()) {
          return;
        }
        ajaxSave(null, function (ok) {
          if (ok) {
            fn();
          }
        });
      },
      save: function () { // Ctrl+S: the page's Save (a draft is kept), never Confirm or make final
        var save = form.querySelector('button[name="action"][value="save"]') || document.querySelector('button[form="' + form.id + '"][name="action"][value="save"]');
        if (save && !save.disabled) {
          save.click();
        }
      },
    };
    return {
      // A choice of the empty line's box (its fragment's data-* copied to the list's line): a new line, filled in place, under the others.
      // A scan with Enter ($scan): the same new item scanned again is one pack more on its line; the cursor stays in the box, for the next.
      take: function (li, scan) {
        var it = fromLi(li);
        var same = null;
        if (scan) {
          each(lines(), function (tr) {
            if (same === null && tr.hasAttribute('data-kg-new') && sameItem(itemOf(tr), it)) {
              same = tr;
            }
          });
        }
        if (same) {
          var qty = cellIn(same, 'qty');
          var n = qty ? whole(qty.value) : null;
          if (qty && n !== null) {
            qty.value = String(n + 1);
          }
          flash(same);
          dirty();
          totals();
          box.clear();
          box.input.focus();
          return;
        }
        var tr = addLine(it, '1', it.price, scan);
        if (tr) {
          box.clear();
          if (scan) {
            box.input.focus();
          } else {
            goTo(cellIn(tr, 'qty') || cellIn(tr, 'price') || box.input);
          }
        }
      },
      // "+ Add new item" from the empty line's list or Alt+C: the form opened with what was typed.
      addNew: function (typed) { return newItem.open(typed, null); },
      offline: function () { table.classList.add('kg-offline'); }, // the products route failed: Add and its boxes are back, as without the script
    };
  };
  // (the lines' grid ends here)

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
    var answered = null; // the words the choices on show answer (the lines' grid: Enter takes the one item they name)
    var waiting = false; // the lines' grid: Enter came before the answer (a scanner): the one item is taken when it comes
    var offline = false; // the products route failed: Enter is Add again, as without the script
    var turn = 0;
    var say = function (text) {
      note.textContent = text;
      note.hidden = text === '';
    };
    var options = function () { return list.querySelectorAll('li[role="option"]:not([aria-disabled="true"])'); };
    var items = function () { return list.querySelectorAll('li[role="option"][data-label]'); }; // the choices, not the last line
    var keyFor = function (q) { return (supplier ? '&supplier=' + encodeURIComponent(supplier.value) : '') + '&q=' + encodeURIComponent(q); };
    var g = null; // the lines' grid whose empty line this box is (docs/decisions.md KG): a choice fills a new line in place
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
        each(['data-label', 'data-si', 'data-sku', 'data-name', 'data-code', 'data-pack', 'data-upp', 'data-their', 'data-price', 'data-stock', 'data-coming'], function (name) {
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
        if (g) {
          g.take(li); // the lines' grid: a new line, filled in place
          return;
        }
        chosen = li;
        input.value = li.getAttribute('data-label') || '';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
      },
      enter: function (ev, open) {
        if (g && !offline) {
          // The lines' grid: Enter never sends the form; a scan (or words) with Enter takes the one item the products route answers with,
          // asked at once (the scanner's Enter comes before the 300 ms); several: the list; none: said under the box.
          if (ev.ctrlKey || ev.metaKey || ev.altKey || ev.shiftKey) {
            return;
          }
          ev.preventDefault();
          var v = input.value.trim();
          if (v.length < 2) {
            c.close();
            return;
          }
          if (answered === keyFor(v)) {
            var all = items();
            if (all.length === 1) {
              c.close();
              g.take(all[0], true); // a scan: its line, the cursor kept in the box for the next one
            } else {
              c.unshut();
              c.open();
            }
            return;
          }
          waiting = true;
          window.clearTimeout(timer);
          load(v);
          return;
        }
        if (open) {
          c.close(); // nothing marked in the open list: Enter adds what the box says, as without the script
        }
      },
    });
    draw(list.querySelectorAll('li[data-label]')); // the choice the page drew (kept after a refusal) is drawn as any other
    pick();
    var grid = input.closest ? input.closest('table[data-kg-grid]') : null;
    if (grid) {
      g = docGrid(grid, {
        input: input,
        list: list,
        base: base,
        keyFor: keyFor,
        say: say,
        marked: function () { return c.isOpen() ? list.querySelector('li.is-active[role="option"]:not([aria-disabled="true"])') : null; },
        choose: function (li) { c.choose(li); },
        clear: function () { // the line is filled: the box is empty again, ready for the next item
          window.clearTimeout(timer);
          turn++;
          asked = null;
          answered = null;
          waiting = false;
          input.value = '';
          chosen = null;
          si.value = '';
          sku.value = '';
          draw([]);
          say('');
          c.close();
        },
      });
    }
    // Alt+C in the box (Tally's "create on the fly"): "+ Add new item" with what was typed, for who may add one.
    kb.boxes.push({ input: input, addNew: function () {
      var add = list.querySelector('li[data-add="1"]');
      if (!add) {
        draw([]);
        add = list.querySelector('li[data-add="1"]');
      }
      if (!add) {
        return false;
      }
      c.choose(add);
      return true;
    } });
    var load = function (q) {
      var key = keyFor(q);
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
          answered = key;
          var says = node.querySelector('[data-po-says]');
          say(says ? says.textContent.trim() : '');
          if (waiting && g) {
            waiting = false;
            var all = items();
            if (all.length === 1) {
              c.close();
              g.take(all[0], true); // a scan with Enter: the one item, in a new line; the cursor kept in the box for the next one
              return;
            }
            c.unshut();
          }
          c.open(); // (only while the box has the focus)
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          asked = null; // the next key asks again
          waiting = false;
          draw([]);
          say(input.getAttribute('data-failed') || '');
          if (g) {
            offline = true; // Add is back (its words say "press Add"), as without the script
            g.offline();
          }
        });
    };
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      turn++; // the words changed: no answer asked for before them is drawn
      asked = null;
      answered = null;
      waiting = false;
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

  // The supplier invoice's "Posting date" and "Posting time" (docs/decisions.md KG27, KG32, KG33, KG36; pack J's "make it final later"):
  // two fields of the editor's form (submit_date, submit_time, UK). The SERVER decides what the main button does: both typed, it saves the
  // draft and sets the time (a time gone refused under them), never "make final now"; one without the other is refused too; both empty, it
  // asks first (KG36: only "Make final now", post_now_confirmed=1, makes it final now). So the script says it: the button reads "Schedule"
  // while either is typed, the line under them says when the stock goes to the websites, and with both empty the main button opens the
  // question in the page. The button is found each time (a save without reload draws the head again, "kg-swapped").
  each(document.querySelectorAll('input[data-kg-posting-date]'), function (date) {
    var set = date.closest('fieldset');
    var time = set ? set.querySelector('input[data-kg-posting-time]') : null;
    if (!time) {
      return;
    }
    var sched = set.querySelector('[data-kg-sched]');
    var finalButton = function () { return document.querySelector('button[data-kg-final]'); };
    var london = function () {
      try {
        var p = new window.Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/London', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit',
          minute: '2-digit', hour12: false }).formatToParts(new Date());
        var v = {};
        each(p, function (x) { v[x.type] = x.value; });
        return v.year + '-' + v.month + '-' + v.day + 'T' + v.hour + ':' + v.minute;
      } catch (e) {
        return '';
      }
    };
    var typed = function () { return date.value !== '' && time.value !== '' ? date.value + 'T' + time.value.slice(0, 5) : ''; };
    var later = function () { var t = typed(); return t !== '' && t > london(); };
    // Something typed the browser cannot read (a date or time half typed: its value is '', validity.badInput), or one without the other
    // (the Codex re-check of 54cf4cf, finding A; KG35): never "make final now".
    var badInput = function (el) { return !!(el.validity && el.validity.badInput); };
    var bad = function () { return badInput(date) || badInput(time) || (date.value === '') !== (time.value === ''); };
    var empty = function () { return date.value === '' && time.value === '' && !badInput(date) && !badInput(time); };
    var say = function (on) {
      var err = set.querySelector('#kg-posting-error');
      if (on && !err) {
        err = document.createElement('p');
        err.className = 'field-error';
        err.id = 'kg-posting-error';
        set.insertBefore(err, sched || null);
      }
      if (err) {
        err.textContent = on ? set.getAttribute('data-kg-partial') || '' : '';
        err.hidden = !on;
      }
      each([date, time], function (el) {
        if (on) {
          el.setAttribute('aria-invalid', 'true');
          el.setAttribute('aria-describedby', 'kg-posting-error');
        } else {
          el.removeAttribute('aria-invalid');
        }
      });
    };
    kb.postingBad = bad;
    // Any send of the editor's form (a button, Enter, Ctrl+S) with the posting time half there: stopped, said under the fields, nothing sent.
    var form = date.form;
    document.addEventListener('submit', function (ev) {
      if (ev.target !== form || !bad()) {
        return;
      }
      ev.preventDefault();
      if (ev.stopImmediatePropagation) {
        ev.stopImmediatePropagation();
      }
      say(true);
      date.focus();
    }, true);
    // "Make it final now? The stock goes to the websites now." (docs/decisions.md KG36; the Codex re-check of f4358b7: round after round found
    // another step that lost a typed posting time, and the next press of the main button then made the invoice final at once.) The main
    // button with both boxes empty sends nothing: the question the server draws shut in the form (data-kg-ask-final) opens, in the page,
    // never a browser dialog, its Cancel taking the cursor (Enter twice is never "final"). Only its "Make final now" (data-kg-confirm-now:
    // post_now_confirmed=1, as the "..."'s) sends the form; the server refuses a "make final" without it (or a time to come) and draws the
    // same question open, so without the script it is asked too. Cancel, Esc, a time typed or any other send shut it; while a save is on its
    // way "Make final now" waits (pressed again after it).
    var ask = form ? form.querySelector('[data-kg-ask-final]') : null;
    var shutAsk = function () {
      if (ask) {
        ask.hidden = true;
      }
    };
    if (ask) {
      var cancel = ask.querySelector('[data-kg-ask-cancel]');
      var stop = function (ev) {
        ev.preventDefault();
        if (ev.stopImmediatePropagation) {
          ev.stopImmediatePropagation();
        }
      };
      document.addEventListener('submit', function (ev) {
        if (ev.target !== form || ev.defaultPrevented) {
          return;
        }
        var by = ev.submitter || null;
        if (by && by.hasAttribute('data-kg-final') && empty()) {
          stop(ev);
          ask.hidden = false;
          (cancel || ask).focus();
          return;
        }
        if (by && by.hasAttribute('data-kg-confirm-now')) {
          if (kb.busy) {
            stop(ev);
          }
          return;
        }
        shutAsk();
      }, true);
      var back = function (ev) {
        ev.preventDefault();
        shutAsk();
        date.focus();
      };
      if (cancel) {
        cancel.addEventListener('click', back);
      }
      ask.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
          back(ev);
        }
      });
    }
    // "How its goods come in" (pack J) needs nothing here: its choices and its Save belong to this form (form="pi-form", the Save's
    // formaction its own route), so the posting date, time and clock typed go with it as the browser sends them, script or not, and the
    // guard above stops it half typed as every send of this form (docs/decisions.md KG37; KG36's copy into a form of its own is gone).
    var show = function () {
      var b = finalButton();
      if (b) {
        // "Save and make final" only while both boxes are truly empty; anything else is a time (or not yet one): "Schedule".
        b.textContent = empty() ? b.getAttribute('data-kg-final-words') || b.textContent : (sched ? sched.getAttribute('data-schedule') || '' : '');
      }

      if (sched) {
        // "Stock goes to the websites: 07 Nov 2026, 18:00 (UK). Until then it stays a draft." while the time is still to come.
        var shown = '';
        try {
          shown = new Date(date.value + 'T12:00:00Z').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }) + ', ' + time.value.slice(0, 5);
        } catch (e) {
          shown = date.value + ' ' + time.value;
        }
        sched.textContent = (sched.getAttribute('data-words') || '').replace('%s', shown);
        sched.hidden = !later();
      }
    };
    var edited = function () {
      show();
      if (!bad()) {
        say(false); // put right: the words under the fields go (a refusal's too, once the person changes the time)
      }
      if (!empty()) {
        shutAsk(); // a time typed: the main button schedules, the question no longer applies (KG36)
      }
    };
    each(['input', 'change', 'blur'], function (type) {
      date.addEventListener(type, edited);
      time.addEventListener(type, edited);
    });
    document.addEventListener('kg-swapped', show);
    show();
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
  var shrinkOnPick = function (input) {
    var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 2097152;
    input.addEventListener('change', function () {
      var said = input.parentNode ? input.parentNode.querySelector('[data-said]') : null;
      if (said) { said.textContent = ''; }
      var file = input.files && input.files[0];
      if (!file || file.size <= max) {
        return;
      }
      var tooBig = function () {
        tell(input.parentNode, input.getAttribute('data-too-big') || ('This file is too big: at most ' + (max / 1048576).toFixed(0) + ' MB. Choose a smaller one.'));
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
  };
  each(document.querySelectorAll('input[type="file"][data-max-bytes]'), shrinkOnPick);

  // A scanner ends with Enter: in a field marked data-enter="next" (the bench's stamp code) it moves on instead of saving the page.
  var enterNext = function (field) {
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
  };
  each(document.querySelectorAll('[data-enter="next"]'), enterNext);

  // Goods in on one page (docs/decisions.md GV): the script only fills the page's one form and shows what it did; every press works
  // without it (the row's "All fine" sends the form, "Problem" is a link to its panel row, opened by CSS :target). Since 7 Nov 2026 the
  // lines are a standard grid (GW, below: giGrid) and Save is sent without leaving the page, so the form's own parts are wired by giWire(),
  // again after each save that put the server's answer in place.
  var giTones = ['waiting', 'done', 'needs', 'blocked', 'review', 'info', 'off'];
  var giTone = function (el, tone) {
    if (!el) { return; }
    giTones.forEach(function (t) { el.classList.remove(t); });
    el.classList.add(tone);
  };
  var giPanel = function (no) { return document.getElementById('gi-p-' + no); };
  var giOpen = function (no, open) {
    var panel = giPanel(no);
    if (!panel) { return; }
    panel.classList.toggle('open', open);
    each(document.querySelectorAll('a[data-gi-panel][aria-controls="gi-p-' + no + '"]'), function (a) { a.setAttribute('aria-expanded', open ? 'true' : 'false'); });
  };
  // What to do with the items without a stamp (and the supplier's proof) is asked only when some have none, or the pack has no stamp.
  var giStampOnly = function (no) {
    var panel = giPanel(no);
    var row = document.getElementById('line-' + no);
    if (!panel) { return; }
    var count = panel.querySelector('input[data-unstamped]');
    var duty = row ? row.querySelector('select[data-gi-duty]') : null;
    var some = (count && parseInt(count.value, 10) > 0) || (duty && duty.value === 'none');
    each(panel.querySelectorAll('[data-unstamped-only]'), function (el) { el.hidden = !some; });
  };
  // "N lines still to check" in the bar follows the ticks of this page (the server's figure counts every page).
  var giLeft = null;
  var giStart = {};
  var giCount = function () {
    if (!giLeft) { return; }
    var left = parseInt(giLeft.getAttribute('data-gi-left'), 10) || 0;
    Object.keys(giStart).forEach(function (no) {
      var box = document.querySelector('input[name="b_' + no + '_ok"]');
      var now = !!(box && box.checked);
      if (now && !giStart[no]) { left--; }
      if (!now && giStart[no]) { left++; }
    });
    left = Math.max(0, left);
    giLeft.textContent = left === 0 ? (giLeft.getAttribute('data-none') || '') : left === 1 ? (giLeft.getAttribute('data-one') || '')
      : (giLeft.getAttribute('data-many') || '%s').replace('%s', String(left));
  };
  var giSaid = function (text) {
    var said = document.getElementById('gi-scan-said');
    if (said) { said.textContent = text; }
  };
  var giNorm = function (v) { v = String(v || '').trim().toLowerCase(); return /^[0-9]+$/.test(v) ? v.replace(/^0+/, '') : v; };
  // The scan box finding a line of this page by a code (its CW number, supplier code or a barcode): highlighted, its "All fine" or "Open"
  // focused, said under the box. False when no line of this page has it.
  var giFind = function (value) {
    var scan = document.getElementById('gi-scan');
    var want = giNorm(value);
    var hit = null;
    each(document.querySelectorAll('tr.gi-row[data-codes]'), function (row) {
      row.classList.remove('found');
      if (!hit && !row.hidden && (row.getAttribute('data-codes') || '').split(/\s+/).map(giNorm).indexOf(want) >= 0) {
        hit = row;
      }
    });
    if (!hit || want === '') {
      return false;
    }
    hit.classList.add('found');
    if (hit.scrollIntoView) { hit.scrollIntoView({ block: 'center' }); }
    var go = hit.querySelector('button[data-gi-fine], a[data-gi-panel]');
    if (go) { go.focus(); }
    var name = hit.querySelector('.o-name');
    giSaid(((scan && scan.getAttribute('data-found')) || '%s: %s').replace('%s', hit.getAttribute('data-gi-line') || '').replace('%s', name ? name.textContent.trim() : ''));
    if (scan) { scan.value = ''; }
    return true;
  };
  var giWire = function (root) {
    giLeft = document.querySelector('[data-gi-left]');
    giStart = {};
    each(root.querySelectorAll('tr.gi-row[data-gi-line]'), function (row) {
      var no = row.getAttribute('data-gi-line');
      var box = document.querySelector('input[name="b_' + no + '_ok"]');
      giStart[no] = !!(box && box.checked);
      giStampOnly(no);
    });
    each(root.querySelectorAll('tr.gi-panel input[type="checkbox"]'), function (box) {
      if (/^b_[0-9]+_ok$/.test(box.name)) { box.addEventListener('change', giCount); }
    });
    // "Problem" / "Open": the panel opens on its row (the address does not change). The link is in the Tab order: Enter on it opens the panel
    // from the keyboard, the cursor in its first box (docs/decisions.md GW2); Esc in the panel shuts it, the cursor back on the link.
    each(root.querySelectorAll('a[data-gi-panel]'), function (a) {
      a.addEventListener('click', function (ev) {
        var no = (a.getAttribute('aria-controls') || '').replace('gi-p-', '');
        var panel = giPanel(no);
        if (!panel) { return; }
        ev.preventDefault();
        var open = !panel.classList.contains('open');
        giOpen(no, open);
        if (open) {
          var first = panel.querySelector('input:not([type="hidden"]), select, textarea');
          if (first) { first.focus(); }
        }
      });
    });
    // "All fine": the line ticked with no problem, in the page (nothing is sent until Save or Book in). A duty line needs its stamp
    // answer first; "No stamp" is a problem, so its panel opens instead.
    each(root.querySelectorAll('button[data-gi-fine]'), function (b) {
      b.addEventListener('click', function (ev) {
        var no = b.getAttribute('data-gi-fine');
        var row = document.getElementById('line-' + no);
        var box = document.querySelector('input[name="b_' + no + '_ok"]');
        var mark = document.querySelector('input[name="b_' + no + '_fine"]');
        if (!row || !box || !mark) { return; }
        ev.preventDefault();
        var duty = row.querySelector('select[data-gi-duty]');
        var why = row.querySelector('[data-gi-why]');
        if (kb.busy) {
          // A save is on its way: its answer is put in place of the page, so a tick now would be lost without a word (GW15). Said in the row.
          if (why) { why.textContent = b.getAttribute('data-wait') || 'Saving: press All fine again when it is saved.'; }
          return;
        }
        if (duty && duty.value === '') {
          // Refused: said in the row, next to the duty stamp cell, never in a browser dialog (GV11); the duty answer is focused where it is
          // (the page does not scroll, GV15).
          if (why) { why.textContent = b.getAttribute('data-needs-stamp') || 'Choose the duty stamp first.'; }
          duty.focus({ preventScroll: true });
          return;
        }
        if (duty && duty.value === 'none') {
          // "No stamp" is a problem: its panel opens under the row and the row says why; nothing else moves.
          if (why) { why.textContent = b.getAttribute('data-none-stamp') || 'No stamp: write below how many items have none.'; }
          giOpen(no, true);
          return;
        }
        if (why) { why.textContent = ''; }
        // All fine: the line ticked where it is; the page keeps its scroll position (GV15) and the focus stays on the button.
        var x = window.scrollX || window.pageXOffset || 0;
        var y = window.scrollY || window.pageYOffset || 0;
        ['short', 'over', 'damaged', 'wrong', 'unstamped'].forEach(function (k) {
          var f = document.querySelector('input[name="b_' + no + '_' + k + '"]');
          if (f) { f.value = '0'; }
        });
        mark.value = '1';
        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        var cell = row.querySelector('td.gi-check');
        giTone(cell, 'done');
        giTone(row, 'done');
        // The cell's pill takes the words (the status is a pill in its cell since the redesign, RD8); the cell keeps the tone.
        var pill = cell ? (cell.querySelector('.chip') || cell) : null;
        if (pill) { pill.textContent = cell.getAttribute('data-words') || pill.textContent; }
        giOpen(no, false);
        giStampOnly(no);
        if (typeof window.scrollTo === 'function') { window.scrollTo(x, y); }
      });
    });
    // A scanner's next code while a found line's "All fine" or "Open" has the cursor (the scan box puts it there: giFind): its characters go
    // to the scan box, so its Enter is a scan there, never a press of that line's button (docs/decisions.md GW8). Enter and Space stay the
    // button's, for a deliberate press.
    each(root.querySelectorAll('button[data-gi-fine], a[data-gi-panel]'), function (b) {
      b.addEventListener('keydown', function (ev) {
        var scan = document.getElementById('gi-scan');
        if (!scan || ev.defaultPrevented || ev.ctrlKey || ev.metaKey || ev.altKey || typeof ev.key !== 'string' || ev.key.length !== 1 || ev.key === ' ') {
          return;
        }
        ev.preventDefault();
        scan.focus();
        scan.value = ev.key;
        scan.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
    // The duty stamp answer colours its cell; "No stamp" opens the panel (count the items without one).
    each(root.querySelectorAll('select[data-gi-duty]'), function (s) {
      s.addEventListener('change', function () {
        var row = s.closest ? s.closest('tr.gi-row') : null;
        var no = row ? row.getAttribute('data-gi-line') : null;
        giTone(s.parentNode, s.value === '' ? 'waiting' : s.value === 'none' ? 'blocked' : 'done');
        var why = row ? row.querySelector('[data-gi-why]') : null;
        if (why && s.value !== '') { why.textContent = ''; }
        if (no && s.value === 'none') { giOpen(no, true); }
        if (no) { giStampOnly(no); }
      });
    });
    // A problem typed in a panel after "All fine" (or its tick taken off) is what is sent: the line is not "all fine" any more.
    each(root.querySelectorAll('tr.gi-panel'), function (panel) {
      var no = panel.id.replace('gi-p-', '');
      var unmark = function (ev) {
        var mark = document.querySelector('input[name="b_' + no + '_fine"]');
        var t = ev.target;
        if (!mark || !t || !t.name || (t.name === 'b_' + no + '_ok' && t.checked)) { return; }
        if (t.name.indexOf('b_' + no + '_') === 0) { mark.value = ''; }
      };
      panel.addEventListener('input', unmark);
      panel.addEventListener('change', unmark);
      panel.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape' || ev.defaultPrevented || !panel.classList.contains('open')) { return; }
        ev.preventDefault(); // Esc shuts the open panel (GW2); the cursor goes back to the line's "Problem" / "Open"
        giOpen(no, false);
        var link = document.querySelector('a[data-gi-panel][aria-controls="gi-p-' + no + '"]');
        if (link) { link.focus(); }
      });
    });
    each(root.querySelectorAll('tr.gi-panel input[data-unstamped]'), function (count) {
      count.addEventListener('input', function () {
        var panel = count.closest ? count.closest('tr.gi-panel') : null;
        if (panel) { giStampOnly(panel.id.replace('gi-p-', '')); }
      });
    });
  };
  // The scan box's Enter as before, when the grid's dropdown did not take it (someone who did not key the delivery, or no script for the
  // fetch): a code of a line of this page finds it; another code is added by the keyer (the form's first button, Add), or said not to be
  // on this delivery.
  var giScanPlain = function () {
    var giScan = document.getElementById('gi-scan');
    if (!giScan) {
      return;
    }
    giScan.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' || ev.defaultPrevented) {
        return;
      }
      if (giNorm(giScan.value) === '') {
        if (!giScan.hasAttribute('data-adds')) { ev.preventDefault(); }
        return;
      }
      if (giFind(giScan.value)) {
        ev.preventDefault();
        return;
      }
      if (giScan.hasAttribute('data-adds')) {
        return; // the keyer: Enter sends the form with its first button, Add
      }
      ev.preventDefault();
      giSaid((giScan.getAttribute('data-not-here') || '%s').replace('%s', giScan.value.trim()));
      giScan.value = '';
    });
  };
  // One save is one request: PHP takes at most data-max-total bytes of it (post_max_size; past it every field is lost) and data-max-files
  // files (max_file_uploads; past it the later ones are dropped, so one is kept back). Counted as the browser sends it (each field a
  // part with its boundary and header, each file its name and bytes); too much is said in the bar and not sent, so nothing typed is lost
  // (the Codex review of 5c070f8, GV19).
  var giPart = function (name, value) { return 100 + String(name).length + String(value).length; };
  each(document.querySelectorAll('form.gi-form[data-max-total]'), function (form) {
    form.addEventListener('submit', function (ev) {
      var max = parseInt(form.getAttribute('data-max-total'), 10) || 0;
      var maxFiles = parseInt(form.getAttribute('data-max-files'), 10) || 0;
      var total = 0;
      var files = 0;
      each(form.querySelectorAll('input, select, textarea'), function (f) {
        if (!f.name || f.disabled || f.type === 'submit' || f.type === 'button') { return; }
        if (f.type === 'file') {
          if (f.files && f.files[0]) {
            files++;
            total += giPart(f.name, f.files[0].name || '') + 80 + f.files[0].size;
          }
          return;
        }
        if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) { return; }
        total += giPart(f.name, f.value || '');
      });
      if (ev.submitter && ev.submitter.name) { total += giPart(ev.submitter.name, ev.submitter.value || ''); }
      var bar = form.querySelector('.doc-bar') || form;
      if (maxFiles > 0 && files > maxFiles - 1) {
        ev.preventDefault();
        tell(bar, form.getAttribute('data-files-words') || 'Too many files for one save: attach the others after saving.');
      } else if (max > 0 && total > max) {
        ev.preventDefault();
        tell(bar, form.getAttribute('data-total-words') || 'Too big together for one save: attach them one or two at a time.');
      }
    });
  });

  // Goods received as a standard grid (docs/decisions.md GW1-GW; the owner, 6 Nov 2026: "check all purchase order, purchase invoice, sales
  // order like this all form", Goods received first): a delivery's lines worked like the order's and the invoice's (KG), with the same keys
  // (the site's one table of keys, KEYS, calls into kb.grid): Tab and Enter box to box, Up / Down the same box on the line above or below,
  // Ctrl+Enter (Insert) the scan box (the delivery's "new line"), Ctrl+Delete the line the cursor is on (its keyer's, as the server allows;
  // "Line 3 removed." with Undo), Ctrl+S the page's Save (never "Book in"). The keyer's scan box is the site's one dropdown (combo()): a scan
  // with Enter, or a choice, naming one item makes a new line in place (what the delivery's Add would add, asked of its own route), or adds
  // to its line when it is on the delivery already, as Add does; a code of a line of this page still finds that line. The new lines go in
  // ONE field (new_lines) with every send of the form. Save (and Ctrl+S) is sent by the one fetch and the answer put in place (giSwap);
  // anything else is a plain post, as is the next Save after a failure. Without the script nothing of it runs: the page works as before.
  var giComboList = null;
  var giGrid = function (form) {
    var card = form.querySelector('section.gi-lines-card');
    var table = card ? card.querySelector('table.gi-lines[data-kg-grid]') : null;
    var scan = document.getElementById('gi-scan');
    var undoLine = card ? card.querySelector('[data-kg-undo-line]') : null;
    var undoText = undoLine ? undoLine.querySelector('[data-kg-undo-text]') : null;
    var undoButton = undoLine ? undoLine.querySelector('button[data-kg-undo]') : null;
    if (!table || !table.tBodies[0] || !scan || !undoLine || !undoText || !undoButton) {
      return null;
    }
    var body = table.tBodies[0];
    var field = form.querySelector('input[data-kg-field]');
    var tpl = card.querySelector('template[data-kg-row]');
    var keyer = table.hasAttribute('data-kg-keyer') && !!field && !!tpl && !!tpl.content && !!tpl.content.firstElementChild;
    var word = function (name) { return table.getAttribute('data-kg-' + name) || ''; };
    var words = function (name) { return table.getAttribute('data-gi-' + name) || ''; };
    var max = parseInt(word('max'), 10) || 0;
    var maxQty = parseInt(word('max-qty'), 10) || 1000000;
    var count = parseInt(word('count'), 10) || 0;
    var from = parseInt(word('from'), 10) || 1;
    var motion = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var sending = false;
    var plain = false;
    var hold = kgHold(form);
    var whole = function (v) {
      var m = /^([0-9]{1,12})$/.exec(String(v).replace(/,/g, '').trim());
      return m ? parseInt(m[1], 10) : null;
    };
    var dirty = function () { form.setAttribute('data-dirty', '1'); };
    var flash = function (tr) {
      tr.classList.remove('kg-flash');
      if (motion) {
        tr.classList.add('kg-flash'); // a short soft highlight (app.css, KG's)
      }
    };
    var goTo = function (el) {
      if (!el) {
        return;
      }
      el.focus();
      if (el.select && el.tagName === 'INPUT') {
        el.select();
      }
    };
    // The lines on show, in order: the saved ones (a removed one is hidden until it is sent) and the new ones (a removed one is gone).
    var lines = function () {
      return Array.prototype.filter.call(body.querySelectorAll('tr[data-kg-row]'), function (tr) { return !tr.hidden && !tr.hasAttribute('data-kg-gone'); });
    };
    // A box the keys go to: in the page's sight (not in a shut panel, a shut fold or a hidden part) and not switched off.
    var on = function (el) {
      if (!el || el.disabled || el.type === 'hidden') {
        return false;
      }
      for (var p = el.parentNode; p && p !== form && p.nodeType === 1; p = p.parentNode) {
        if (p.hidden || (p.tagName === 'DETAILS' && !p.open)) {
          return false;
        }
        if (p.tagName === 'TR' && p.classList.contains('gi-panel') && !p.classList.contains('open') && !(window.location && window.location.hash === '#' + p.id)) {
          return false;
        }
      }
      return true;
    };
    var cells = function (scope) { return Array.prototype.filter.call(scope.querySelectorAll('[data-kg-cell]'), on); };
    var cellIn = function (tr, cell) { return tr ? tr.querySelector('[data-kg-cell="' + cell + '"]') : null; };
    var tableOf = function (el) { return el && el.closest ? el.closest('table') : null; };
    // The line a box belongs to: its row, the row of its panel, or of its line in the prices fold.
    var lineOf = function (el) {
      var tr = el && el.closest ? el.closest('tr') : null;
      if (!tr) {
        return null;
      }
      if (tr.hasAttribute('data-gi-panel-of')) {
        return document.getElementById('line-' + tr.getAttribute('data-gi-panel-of'));
      }
      if (tr.hasAttribute('data-gi-price')) {
        return document.getElementById('line-' + tr.getAttribute('data-gi-price'));
      }
      return tr.hasAttribute('data-kg-row') ? tr : null;
    };
    // The next box after (or before) one in its table, in the page's order; past the last box of the last line: the scan box.
    var step = function (from, by) {
      var all = cells(tableOf(from) || table);
      var i = all.indexOf(from);
      if (i < 0) {
        return null;
      }
      return i + by >= all.length ? scan : all[Math.max(0, i + by)];
    };
    // The lines' numbers: a saved line its number on the delivery (this page's from the first), closed up over the lines removed; a new
    // line after every line of the delivery (where the save puts it).
    var renumber = function () {
      var saved = 0;
      var added = 0;
      var gone = 0;
      each(body.querySelectorAll('tr[data-kg-line]'), function (tr) {
        if (tr.hidden || tr.hasAttribute('data-kg-gone')) { gone++; }
      });
      each(lines(), function (tr) {
        var n = tr.hasAttribute('data-kg-new') ? count - gone + (++added) : from + (saved++);
        var no = tr.querySelector('[data-kg-no]');
        if (no) { no.textContent = String(n); }
        var price = tr.hasAttribute('data-kg-line') ? form.querySelector('tr[data-gi-price="' + tr.getAttribute('data-kg-line') + '"] [data-kg-no]') : null;
        if (price) { price.textContent = String(n); }
      });
      var wrap = table.closest('.table-wrap');
      if (wrap && lines().length > 0) { wrap.removeAttribute('data-gi-none'); }
      var none = card.querySelector('[data-gi-none-hint]');
      if (none) { none.hidden = lines().length > 0; }
    };
    // A line out: a new one at once; a saved one hidden with its panel and its prices row, their fields switched off and its Got sent as 0
    // (the server's own way to take a line out: GoodsReceipts::saveDraft without it); Undo puts the last removal back.
    var undo = null;
    var hideUndo = function () {
      undoLine.hidden = true;
      undoText.textContent = '';
    };
    var removable = function (tr) {
      if (!keyer || !tr || !tr.hasAttribute('data-kg-row') || tr.hidden || tr.hasAttribute('data-kg-gone')) {
        return false;
      }
      return tr.hasAttribute('data-kg-new') || !!tr.querySelector('input[data-kg-cell="qty"][name]');
    };
    var takeOut = function (tr) {
      var u = { tr: tr, before: tr.nextSibling, parts: [], off: [], flag: null };
      if (tr.hasAttribute('data-kg-new')) {
        tr.setAttribute('data-kg-gone', '1');
        if (tr.parentNode) { tr.parentNode.removeChild(tr); }
        return u;
      }
      var no = tr.getAttribute('data-kg-line') || '';
      u.parts = [tr, giPanel(no), form.querySelector('tr[data-gi-price="' + no + '"]')].filter(function (x) { return !!x; });
      each(u.parts, function (part) {
        each(part.querySelectorAll('input[name], select[name], textarea[name]'), function (el) {
          if (!el.disabled) {
            el.disabled = true;
            u.off.push(el);
          }
        });
        part.hidden = true;
      });
      var flag = document.createElement('input');
      flag.type = 'hidden';
      flag.name = 'line_' + no + '_packs';
      flag.value = '0';
      flag.setAttribute('data-kg-flag', '1');
      tr.appendChild(flag);
      u.flag = flag;
      return u;
    };
    var putBack = function (u) {
      if (u.tr.hasAttribute('data-kg-new')) {
        u.tr.removeAttribute('data-kg-gone');
        if (u.before && u.before.parentNode === body) {
          body.insertBefore(u.tr, u.before);
        } else {
          body.appendChild(u.tr);
        }
        return;
      }
      if (u.flag && u.flag.parentNode) { u.flag.parentNode.removeChild(u.flag); }
      each(u.off, function (el) { el.disabled = false; });
      each(u.parts, function (part) { part.hidden = false; });
    };
    var removeLine = function (tr, cell) {
      if (sending || !removable(tr)) {
        return false;
      }
      var all = lines();
      var at = all.indexOf(tr);
      var next = all[at + 1] || null;
      undo = takeOut(tr);
      undoText.textContent = word('removed').replace('%s', tr.querySelector('[data-kg-no]') ? tr.querySelector('[data-kg-no]').textContent : String(at + 1));
      undoLine.hidden = false;
      dirty();
      renumber();
      goTo(next ? (cellIn(next, cell) || cells(next)[0] || scan) : scan);
      return true;
    };
    undoButton.addEventListener('click', function () {
      if (!undo || sending) {
        return;
      }
      var u = undo;
      undo = null;
      putBack(u);
      hideUndo();
      renumber();
      goTo(cellIn(u.tr, 'qty') || cells(u.tr)[0] || scan);
    });
    // A new line from what the scan box names: its item in its attributes (what the one field sends), its words, its packs.
    // $adds: the line adds to a line of the delivery already there (another page's, GW14): it makes no line, so the limit does not stop it (GW19).
    var addLine = function (it, packs, price, adds) {
      var gone = Array.prototype.filter.call(body.querySelectorAll('tr[data-kg-line]'), function (tr) { return tr.hidden; }).length;
      var made = Array.prototype.filter.call(body.querySelectorAll('tr[data-kg-new]'), function (tr) { return !tr.hasAttribute('data-gi-adds'); }).length;
      if (!adds && max > 0 && count - gone + made >= max) { // the delivery's limit (GoodsReceipts::MAX_LINES), counted on the lines the save makes
        giSaid(word('too-many'));
        return null;
      }
      var tr = tpl.content.firstElementChild.cloneNode(true);
      tr.setAttribute('data-si', it.si);
      tr.setAttribute('data-sku', it.si !== '' ? '' : it.sku);
      tr.setAttribute('data-u', it.si === '' && it.upp > 1 ? String(it.upp) : '');
      tr.setAttribute('data-kg-upp', String(it.upp));
      tr.setAttribute('data-gi-p', price);
      if (adds) {
        tr.setAttribute('data-gi-adds', '1');
      }
      each(['name', 'code', 'pack', 'their'], function (k) { tr.setAttribute('data-' + k, it[k]); });
      var put = function (sel, text) { var el = tr.querySelector(sel); if (el) { el.textContent = text; } };
      put('[data-kg-name]', it.name);
      put('[data-kg-code]', it.code);
      put('[data-kg-pack]', it.pack + (it.their !== '' ? ' · ' + words('their').replace('%s', it.their) : ''));
      var qty = cellIn(tr, 'qty');
      if (qty) { qty.value = String(packs); }
      body.appendChild(tr);
      wire(tr);
      flash(tr);
      dirty();
      renumber();
      return tr;
    };
    var wire = function (tr) {
      var x = tr.querySelector('[data-kg-x]');
      if (x && !x.hasAttribute('data-kg-wired')) {
        x.setAttribute('data-kg-wired', '1');
        x.tabIndex = -1; // Tab goes box to box; the x is a click (or Ctrl+Delete)
        x.addEventListener('click', function (ev) {
          ev.preventDefault();
          removeLine(tr, 'qty');
        });
      }
    };
    each(body.querySelectorAll('tr[data-kg-new]'), wire);
    // What the scan box named, taken (docs/decisions.md GW4): Add's own rules. The first line of this page that is the same purchase as the
    // page has it NOW (the same supplier's pack, or the same item in the same items a pack as its box says: GW11) gets the packs, in its
    // Got box, and the price typed beside the scan box in its price box (GW13); an item whose line is on another page of a big delivery
    // goes in the one field with a plain save, which adds it to that line (GW14); else a new line, the packs a scan is times the Packs box,
    // the Price box's price. The cursor goes to the line's Got box (a scanner's next code typed there is taken as the next scan: step()).
    var scanPacks = function () {
      var p = form.querySelector('input[name="packs"]');
      var n = p ? whole(p.value) : null;
      return n !== null && n > 0 ? n : 1;
    };
    var scanReset = function () {
      var p = form.querySelector('input[name="packs"]');
      var pr = form.querySelector('input[name="price"]');
      if (p) { p.value = '1'; }
      if (pr) { pr.value = ''; }
    };
    var addButton = function () { return form.querySelector('.gi-scan button[name="action"][value="add"]'); };
    var send = function (b) {
      plain = true;
      if (b && form.requestSubmit) {
        form.requestSubmit(b);
      } else if (b) {
        b.click();
      }
    };
    var plainAdd = function () { send(addButton()); };
    // A line's purchase as the page has it now: its supplier's pack, or its item and the items a pack its box says (else as saved).
    var sameAs = function (tr, it) {
      var si = tr.getAttribute('data-si') || '';
      if (it.si !== '' || si !== '') {
        return si === it.si;
      }
      var box = cellIn(tr, 'upp');
      var upp = box ? whole(box.value) : (parseInt(tr.getAttribute('data-kg-upp') || '1', 10) || 1);
      return (tr.getAttribute('data-sku') || '') === it.sku && upp === it.upp;
    };
    var take = function (li, clear) {
      if (sending) {
        return; // a save is on its way (hold)
      }
      if (supplierMoved()) {
        plainAdd(); // a choice of the saved supplier's while another is chosen on the page: never taken; Add, as before (GW16, GW20)
        return;
      }
      var a = function (name) { return li.getAttribute(name) || ''; };
      var it = { si: a('data-si'), sku: a('data-sku'), upp: parseInt(a('data-upp'), 10) || 1, name: a('data-name') || a('data-label'), code: a('data-code'),
        pack: a('data-pack'), their: a('data-their') };
      var packs = (parseInt(a('data-packs'), 10) || 1) * scanPacks();
      var pr = form.querySelector('input[name="price"]');
      var price = pr ? pr.value.trim() : '';
      var row = null;
      each(lines(), function (tr) {
        var got = cellIn(tr, 'qty');
        if (row === null && got && !got.disabled && (tr.hasAttribute('data-kg-new') || got.name) && sameAs(tr, it)) {
          row = tr;
        }
      });
      if (row === null && a('data-line') !== '' && !document.getElementById('line-' + a('data-line'))) {
        // Its line is on another page of this delivery: the chosen item in the one field and a plain save (the server adds it to that line, as
        // Add, and the page after it says so); the search is not sent (GW14).
        var far = addLine(it, packs, price, true);
        if (far) {
          clear();
          send(form.querySelector('button[name="action"][value="save"]'));
        }
        return;
      }
      if (row !== null) {
        var got = cellIn(row, 'qty');
        got.value = String((whole(got.value) || 0) + packs);
        if (price !== '') {
          if (row.hasAttribute('data-kg-new')) {
            row.setAttribute('data-gi-p', price);
          } else {
            var pbox = form.querySelector('input[name="line_' + (row.getAttribute('data-kg-line') || '') + '_price"]');
            if (pbox && !pbox.disabled) {
              pbox.value = price; // the saved line's own price box: Save sends it (Add sets the price typed with it)
            }
          }
        }
        flash(row);
        dirty();
        clear();
        scanReset();
        giSaid(words('more').replace('%s', (row.querySelector('[data-kg-no]') || { textContent: '' }).textContent).replace('%s', it.name).replace('%s', got.value));
        goTo(got);
        return;
      }
      var tr = addLine(it, packs, price);
      if (!tr) {
        return;
      }
      clear();
      scanReset();
      giSaid(words('added').replace('%s', (tr.querySelector('[data-kg-no]') || { textContent: '' }).textContent).replace('%s', it.name)
        + (a('data-note') !== '' ? ' ' + a('data-note') : ''));
      goTo(cellIn(tr, 'qty'));
    };
    // The supplier chosen on the page and not saved yet (an empty delivery's): the scan box's route answers for the saved supplier, so the
    // scan box is Add, as before (the save puts the supplier first, then Add looks for the scan with it: GW16).
    var supplierBox = form.querySelector('select[name="supplier_id"]');
    var supplierMoved = function () {
      var saved = scan.getAttribute('data-gi-supplier') || '';
      return !!supplierBox && saved !== '' && supplierBox.value !== saved;
    };
    // The keyer's scan box: the site's one dropdown, its choices asked of the delivery's own route (what Add would add), drawn as text.
    var box = null;
    if (keyer && window.fetch && window.DOMParser && (scan.getAttribute('data-options') || '').indexOf('/ui/receiving/') === 0) {
      box = (function () {
        var base = scan.getAttribute('data-options') || '';
        if (giComboList && giComboList.parentNode) {
          giComboList.parentNode.removeChild(giComboList); // the list of the box a save without reload replaced
        }
        var list = document.createElement('ul');
        list.id = 'gi-scan-list';
        list.className = 'sm-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', scan.getAttribute('data-label') || '');
        list.hidden = true;
        giComboList = list;
        var timer = 0;
        var turn = 0;
        var asked = null;
        var answered = null;
        var waiting = false;
        var offline = false;
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
            each(['data-label', 'data-si', 'data-sku', 'data-name', 'data-code', 'data-pack', 'data-upp', 'data-their', 'data-packs', 'data-line', 'data-note'], function (name) {
              li.setAttribute(name, o.getAttribute(name) || '');
            });
            var part = function (cls) { var p = o.querySelector('.' + cls); return p ? p.textContent.trim() : ''; };
            twoLines(li, part('sm-opt-name'), part('sm-opt-meta'));
            list.appendChild(li);
          });
          c.mark(-1);
        };
        var clear = function () {
          window.clearTimeout(timer);
          turn++;
          asked = null;
          answered = null;
          waiting = false;
          scan.value = '';
          draw([]);
          c.close();
        };
        var one = function () {
          var all = options();
          if (all.length === 1) {
            c.close();
            take(all[0], clear);
          } else if (all.length > 1) {
            c.unshut();
            c.open();
          }
        };
        var load = function (q) {
          if (asked === q) {
            return;
          }
          asked = q;
          var mine = ++turn;
          giSaid(scan.getAttribute('data-loading') || '');
          fragment(base + 'q=' + encodeURIComponent(q), '[data-gi-fragment]')
            .then(function (node) {
              if (mine !== turn || supplierMoved()) {
                return; // asked before other words, or for the saved supplier while another is chosen now (GW20)
              }
              draw(node.querySelectorAll('ul[data-po-choices] > li[data-label]'));
              answered = q;
              var says = node.querySelector('[data-po-says]');
              giSaid(says ? says.textContent.trim() : '');
              if (waiting) {
                waiting = false;
                one();
                return;
              }
              c.open(); // (only while the box has the focus)
            })
            .catch(function () {
              if (mine !== turn) {
                return;
              }
              asked = null;
              draw([]);
              giSaid(scan.getAttribute('data-failed') || '');
              offline = true; // Enter is Add again, as without the script
              if (waiting) {
                waiting = false;
                plainAdd();
              }
            });
        };
        var c = combo(scan, list, {
          options: options,
          choose: function (li) { take(li, clear); },
          enter: function (ev) {
            if (ev.ctrlKey || ev.metaKey || ev.altKey || ev.shiftKey || offline) {
              return; // offline: Enter is Add (the form's first button), as without the script
            }
            ev.preventDefault(); // Enter in the scan box never sends the form while the grid works
            var v = scan.value.trim();
            if (v === '') {
              c.close();
              return;
            }
            if (giFind(v)) {
              clear(); // a code of a line of this page: that line, as before (nothing added)
              return;
            }
            if (supplierMoved()) {
              plainAdd(); // another supplier chosen, not saved yet: Add, as before (GW16)
              return;
            }
            if (v.length < 2) {
              c.close();
              return;
            }
            if (answered === v) {
              one(); // none: the line under the box already says so
              return;
            }
            waiting = true; // a scanner's Enter comes before the 300 ms: asked at once, the one item taken when it comes
            window.clearTimeout(timer);
            load(v);
          },
        });
        scan.addEventListener('input', function () {
          window.clearTimeout(timer);
          turn++;
          asked = null;
          answered = null;
          waiting = false;
          c.unshut();
          draw([]);
          var v = scan.value.trim();
          if (v.length < 2 || supplierMoved()) {
            return; // one letter finds too much; another supplier chosen and not saved: no list of the saved one's (GW16)
          }
          timer = window.setTimeout(function () { load(v); }, 300);
        });
        // Another supplier chosen in the page's box: the list on show and any answer on its way were the saved supplier's; both go (GW20).
        if (supplierBox) {
          supplierBox.addEventListener('change', function () {
            window.clearTimeout(timer);
            turn++;
            asked = null;
            answered = null;
            waiting = false;
            draw([]);
            c.close();
          });
        }
        var marked = function () { return c.isOpen() ? list.querySelector('li.is-active[role="option"]:not([aria-disabled="true"])') : null; };
        return { marked: marked, take: function () { var m = marked(); if (m) { c.choose(m); } }, scanned: function (v) { scan.value = v; c.close(); answered = null; asked = null; waiting = true; load(v); } };
      })();
    }
    kb.boxes = kb.boxes.filter(function (b) { return document.body.contains(b.input); });
    kb.boxes.push({ input: scan, marked: function () { return box ? box.marked() : null; }, take: function () { if (box) { box.take(); } }, addNew: function () { return false; } });
    // Every send of the form carries the new lines in their one field, in order (docs/decisions.md GW4; KG4's `new_lines`).
    var serialise = function () {
      if (!field) {
        return;
      }
      var out = [];
      each(body.querySelectorAll('tr[data-kg-new]'), function (tr) {
        if (tr.hasAttribute('data-kg-gone')) {
          return;
        }
        var si = tr.getAttribute('data-si') || '';
        var o = si !== '' ? { si: parseInt(si, 10) } : { sku: parseInt(tr.getAttribute('data-sku') || '0', 10) };
        var u = tr.getAttribute('data-u') || '';
        if (si === '' && /^[1-9][0-9]*$/.test(u) && u !== '1') {
          o.u = parseInt(u, 10);
        }
        var qty = cellIn(tr, 'qty');
        o.q = qty ? qty.value.trim() : '';
        o.p = tr.getAttribute('data-gi-p') || '';
        out.push(o);
      });
      field.value = out.length > 0 ? JSON.stringify(out) : '';
    };
    // Save without leaving the page (docs/decisions.md GW7, KG24): the form sent by the one fetch, multipart as the browser sends it (its
    // photos and papers too), a POST to its own address with its CSRF token, FormOnce key, version and stamps; the answer put in place when
    // it is this delivery's page (giSwap), the cursor back on the same line and box, "Saved." said; anything else changes nothing, says so,
    // and the next Save is a plain post. "Book in", "All fine" and the rest are as before.
    var canSend = function () { return !plain && !!window.FormData && !!window.fetch && !!window.DOMParser && !sending; };
    var ajaxSave = function (by) {
      serialise();
      var data = new window.FormData(form);
      data.set('action', 'save');
      sending = true;
      hold(true);
      if (by) {
        by.disabled = true;
      }
      kgToast(word('saving'), false, true);
      fragment(form.getAttribute('action') || '', '[data-kg-editor]', data)
        .then(function (part) {
          sending = false;
          hold(false);
          if (by) {
            by.disabled = false;
          }
          var said = giSwap(form, part);
          if (said === null) {
            throw new Error('not this page');
          }
          kgToast(said.error !== '' ? said.error : (said.notice !== '' ? said.notice : word('saved')), said.error !== '');
          if (said.error === '') {
            form.removeAttribute('data-dirty');
          }
        })
        .catch(function () {
          sending = false;
          hold(false);
          if (by) {
            by.disabled = false;
          }
          plain = true;
          kgToast(word('save-failed'), true);
        });
    };
    // The form's own send (its listeners are the form's, once: giStartup; this grid answers them while it is the page's).
    var submit = function (ev) {
      serialise();
      if (ev.defaultPrevented) {
        return; // stopped before it (a size or count refused in the page): nothing sent
      }
      var by = ev.submitter;
      var value = by && by.getAttribute('name') === 'action' ? by.getAttribute('value') : null;
      // Save, or the form's first button (Add) with nothing in the scan box (Enter in a field of the page): a save without leaving the page.
      var isSave = value === 'save' || (value === 'add' && scan.value.trim() === '');
      if (sending) {
        ev.preventDefault();
        return; // a save is on its way (hold)
      }
      undo = null;
      hideUndo();
      if (!isSave || !canSend()) {
        return; // "Book in", Add, "Save and show the next lines", the choices: plain posts; after a failed Save, so is the next one
      }
      ev.preventDefault();
      ajaxSave(by);
    };
    form.giGrid = { serialise: serialise, submit: submit };
    renumber();
    // What the site's keys do in the lines (kb, KEYS): the lines' card (the scan box, the lines, the prices fold) is the grid's place.
    kb.grid = {
      table: card,
      keys: keyer ? ['next', 'back', 'updown', 'new_line', 'delete_line', 'undo_line', 'fill_down', 'save', 'esc'] : ['next', 'back', 'updown', 'save', 'esc'],
      marked: function () { return box ? box.marked() : null; },
      takeMarked: function () { if (box) { box.take(); } },
      // Enter in a box: the next box (never the form sent). A Got box holding a scanner's code (more digits than any count can have) is the
      // next scan, not a count: the box gets back what it held, the code goes to the scan box.
      step: function (from, by) {
        var v = from.value || '';
        if (box && from.getAttribute('data-kg-cell') === 'qty' && /^[0-9]{8,}$/.test(v.trim()) && parseInt(v, 10) > maxQty) {
          from.value = from.getAttribute('data-gi-was') || '1';
          box.scanned(v.trim());
          scan.focus();
          return;
        }
        goTo(step(from, by));
      },
      // Up / Down: the same box of the line above or below (a select keeps its own); past the last line: the scan box.
      vertical: function (from, by) {
        if (from.tagName === 'SELECT') {
          return false;
        }
        var name = from.getAttribute('data-kg-cell');
        var all = cells(tableOf(from) || table).filter(function (c) { return c.getAttribute('data-kg-cell') === name; });
        var i = all.indexOf(from) + by;
        if (i >= 0 && i < all.length) {
          goTo(all[i]);
        } else if (by > 0) {
          goTo(scan);
        }
        return true;
      },
      toEmpty: function () { goTo(scan); }, // Ctrl+Enter, Insert: the scan box, the delivery's new line
      removeAt: function (from) { return removeLine(lineOf(from), from.getAttribute('data-kg-cell') || 'qty'); },
      canUndo: function () { return undo !== null; },
      undo: function () { undoButton.click(); },
      // Ctrl+D in a Got box: the figure of the line above (a spreadsheet's fill down); elsewhere the browser's.
      fillDown: function (from) {
        var name = from.getAttribute('data-kg-cell');
        if (sending || (name !== 'qty' && name !== 'upp')) {
          return false;
        }
        var all = cells(table).filter(function (c) { return c.getAttribute('data-kg-cell') === name; });
        var i = all.indexOf(from);
        if (i > 0) {
          from.value = all[i - 1].value;
          dirty();
        }
        return true;
      },
      save: function () { // Ctrl+S: the page's Save, never "Book in" or anything that moves stock
        var save = form.querySelector('button[name="action"][value="save"]');
        if (save && !save.disabled) {
          save.click();
        }
      },
    };
    // A Got box remembers what it held when the cursor came in (a scanner's code typed over it gives it back, above).
    card.addEventListener('focusin', function (ev) {
      var t = ev.target;
      if (t && t.getAttribute && t.getAttribute('data-kg-cell') === 'qty') {
        t.setAttribute('data-gi-was', t.value);
      }
    });
    return kb.grid;
  };
  // A save's answer put in place (docs/decisions.md GW7): only this delivery's page (form[data-kg-editor] of the same delivery). Before the
  // form: the messages as the server drew them (the head stays: its More keeps its forms and their guards; its title, status and the line
  // under it are the answer's; every form of this delivery on the page takes the new version). The form: its address and limits and every
  // part, as drawn, wired again (giWire, the grid, a photo's size, a stamp code's Enter); the cursor back on the same line and box, the page
  // where it was. Returns what to say (the answer's refusal, else its notice), or null when the answer is not this page.
  var giSwap = function (form, part) {
    var nf = part.querySelector('form[data-kg-editor]');
    if (!nf || nf.getAttribute('data-kg-editor') !== form.getAttribute('data-kg-editor')) {
      return null;
    }
    var active = document.activeElement;
    var inForm = !!(active && active.closest && form.contains(active));
    var activeId = inForm && active.id ? active.id : '';
    var cell = active && active.getAttribute ? active.getAttribute('data-kg-cell') : null;
    // The line the cursor is on: its row, or the row of its problem panel or of its prices line (GW17), and which of the three it is in.
    var ptr = inForm ? active.closest('tr') : null;
    var part = ptr ? (ptr.hasAttribute('data-gi-panel-of') ? 'panel' : (ptr.hasAttribute('data-gi-price') ? 'price' : (ptr.hasAttribute('data-kg-row') ? 'row' : null))) : null;
    var row = part === 'row' ? ptr : (part ? document.getElementById('line-' + (ptr.getAttribute('data-gi-panel-of') || ptr.getAttribute('data-gi-price'))) : null);
    var named = inForm && /^(b|line)_[0-9]+_[a-z]+$/.test(active.name || '') ? (active.name || '').replace(/^(b|line)_[0-9]+_/, '') : '';
    var prefix = named !== '' ? (active.name || '').replace(/_[0-9]+_.*$/, '') : '';
    var pricesFold = form.querySelector('details.gi-prices');
    var pricesOpen = !!(pricesFold && pricesFold.open);
    var rowsNow = Array.prototype.filter.call(form.querySelectorAll('tr[data-kg-row]'), function (tr) { return !tr.hidden; });
    var at = row ? rowsNow.indexOf(row) : -1;
    var x = window.scrollX || window.pageXOffset || 0;
    var y = window.scrollY || window.pageYOffset || 0;
    var keep = function (el) { return el.nodeType === 1 && el.classList.contains('doc-head'); };
    var parent = form.parentNode;
    var theirs = nf.parentNode;
    var notice = nf.getAttribute('data-gi-notice') || '';
    var mine = [];
    for (var el = parent.firstChild; el && el !== form; el = el.nextSibling) {
      mine.push(el);
    }
    each(mine, function (el) {
      if (!keep(el)) { parent.removeChild(el); }
    });
    var myHead = parent.querySelector('.doc-head');
    var beforeHead = !!myHead;
    for (var el2 = theirs.firstChild; el2 && el2 !== nf; el2 = el2.nextSibling) {
      if (keep(el2)) {
        beforeHead = false;
        continue;
      }
      // In their order: what comes before the head (the crumbs) before it, the rest (the messages) between the head and the form.
      var copy = document.importNode(el2, true);
      parent.insertBefore(copy, beforeHead ? myHead : form);
      if (copy.nodeType === 1) {
        each(copy.querySelectorAll('form[method="post"]'), sendOnce);
        if (copy.tagName === 'FORM' && copy.getAttribute('method') === 'post') {
          sendOnce(copy);
        }
      }
    }
    var theirHead = theirs.querySelector('.doc-head');
    if (myHead && theirHead) {
      each(['h1', '.ph-meta'], function (sel) {
        var a = myHead.querySelector(sel);
        var b = theirHead.querySelector(sel);
        if (a && b) { a.parentNode.replaceChild(document.importNode(b, true), a); }
      });
    }
    var version = nf.querySelector('input[name="version"]');
    var self = form.getAttribute('action') || '';
    var stem = self.replace(/\/lines$/, '/');
    each(document.querySelectorAll('form'), function (f) {
      var fv = f !== form ? f.querySelector('input[name="version"]') : null;
      if (fv && version && (f.getAttribute('action') || '').indexOf(stem) === 0) {
        fv.value = version.value;
      }
    });
    each(['action', 'data-max-total', 'data-max-files', 'data-total-words', 'data-files-words'], function (a) {
      if (nf.getAttribute(a) !== null) { form.setAttribute(a, nf.getAttribute(a)); }
    });
    while (form.firstChild) {
      form.removeChild(form.firstChild);
    }
    each(Array.prototype.slice.call(nf.childNodes), function (n) { form.appendChild(document.importNode(n, true)); });
    giWire(form);
    each(form.querySelectorAll('input[type="file"][data-max-bytes]'), shrinkOnPick);
    each(form.querySelectorAll('[data-enter="next"]'), enterNext);
    giGrid(form);
    giScanPlain();
    // "All keys (?)" of the hint line put in place opens the panel of keys again (GW18).
    if (kbPanel) {
      each(form.querySelectorAll('[data-kb-open]'), function (b) { b.addEventListener('click', kbOpen); });
    }
    // The prices fold stays open when it was; the cursor goes back to the same line (by its place) and the same box of it: in its row, its
    // problem panel (opened again) or its prices line (GW17).
    var foldThen = form.querySelector('details.gi-prices');
    if (pricesOpen && foldThen) {
      foldThen.open = true;
    }
    var rowsThen = Array.prototype.filter.call(form.querySelectorAll('tr[data-kg-row]'), function (tr) { return !tr.hidden; });
    var back = at >= 0 ? rowsThen[Math.min(at, rowsThen.length - 1)] : null;
    var target = null;
    if (back) {
      var no = back.getAttribute('data-kg-line') || '';
      if (part === 'panel' && no !== '') {
        giOpen(no, true);
      }
      if (part === 'price' && foldThen) {
        foldThen.open = true;
      }
      target = named !== '' && no !== '' ? form.querySelector('[name="' + prefix + '_' + no + '_' + named + '"]') : null;
      if (!target && part === 'row') {
        target = back.querySelector('[data-kg-cell="' + (cell || 'qty') + '"]') || back.querySelector('[data-kg-cell]');
      }
    } else if (activeId !== '') {
      target = document.getElementById(activeId);
    }
    if (target && target.focus) {
      target.focus({ preventScroll: true });
    }
    if (typeof window.scrollTo === 'function') { window.scrollTo(x, y); }
    var refused = parent.querySelector('.gi-refused p, ul.error.problems li, p.error[role="alert"]');
    return { notice: notice, error: refused ? refused.textContent.trim() : '' };
  };
  each(document.querySelectorAll('form.gi-form'), function (form) {
    // The form's sends, listened to once (the form stays when a save's answer is put in place; its parts and their grid are new).
    form.addEventListener('submit', function () { if (form.giGrid) { form.giGrid.serialise(); } }, true);
    form.addEventListener('submit', function (ev) { if (form.giGrid) { form.giGrid.submit(ev); } });
    giWire(form);
    giGrid(form);
  });
  giScanPlain();

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
      // The line's "Saved" mark is in its row (its own cell since docs/decisions.md CV6), else beside the box.
      var row = input.closest ? input.closest('tr') : null;
      var state = (row || input.parentNode) ? (row || input.parentNode).querySelector('.save-state') : null;
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

  // Stock > Counts > Next to count (docs/decisions.md CV2): the bar's "N ticked" follows the tick boxes of its form (the page's words,
  // data-words: "%s ticked"); a ticked row takes the ticked tint. Without the script the words stay hidden (nothing would keep them true).
  each(document.querySelectorAll('[data-ticked]'), function (out) {
    var form = out.closest ? out.closest('form') : null;
    if (!form) {
      return;
    }
    var boxes = form.querySelectorAll('input[type="checkbox"][data-tick]');
    var update = function () {
      var n = 0;
      each(boxes, function (b) {
        var tr = b.closest ? b.closest('tr') : null;
        if (tr) { tr.classList.toggle('picked', b.checked); }
        if (b.checked) { n++; }
      });
      out.textContent = (out.getAttribute('data-words') || '%s').replace('%s', String(n));
    };
    each(boxes, function (b) { b.addEventListener('change', update); });
    update();
    out.hidden = false;
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

  // The small menus (the person's menu, Filter and Sort, More, a page's Show): Esc or a click elsewhere closes the open one. A More holding an open step
  // (a reason being typed, or a step the server refused and opened again) stays open: only its own button closes it.
  var menus = 'details.user-menu[open], details.tb-pop[open], details.more[open], details.show-menu[open]';
  var holds = function (m) { return m.classList.contains('more') && m.querySelector('details[open]') !== null; };
  document.addEventListener('click', function (ev) {
    each(document.querySelectorAll(menus), function (m) {
      if (!m.contains(ev.target) && !holds(m)) { m.open = false; }
    });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') {
      return;
    }
    each(document.querySelectorAll(menus), function (m) {
      if (holds(m)) { return; }
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
  // "/" puts the cursor in the page's own search box: the keys' layer's `search` (docs/decisions.md KG17, one listener for the site's keys),
  // which takes the box marked data-page-search first (a folded one, the variant page's "Pick a different product", opened first), unless
  // the person is typing in a field or holds Ctrl, Cmd or Alt. The box says so to a screen reader (aria-keyshortcuts="/").

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

  // Print (the PurchaseOrder and Reports boards; docs/decisions.md DG6): the button is hidden without the script; with it, the browser's print.
  each(document.querySelectorAll('button[data-print]'), function (b) {
    b.hidden = false;
    b.addEventListener('click', function () { window.print(); });
  });

  // New delivery: "receive all as ordered" follows the purchase order chosen.
  each(document.querySelectorAll('select[data-ticks]'), function (s) {
    var box = document.getElementById(s.getAttribute('data-ticks'));
    if (!box) {
      return;
    }
    s.addEventListener('change', function () { box.checked = s.value !== ''; });
  });

  // The quick adjustment screen (docs/decisions.md AG1-AG8; the owner's drawing of 2 Nov 2026: "ERPNext is easier"). The lines are drawn here from the form's
  // data-rows and the row template, and one empty line is always ready at the end. A line's product box is the site's one dropdown (combo), filled from the
  // read-only fragment of the products route (2 characters or more, 300 ms after the last key). Tab or Enter takes the choice marked, else the first one (when
  // the answer is still on its way, as with a scanner, it is taken when it comes), and goes to Units; Tab or Enter in Units goes to the next line, or to After
  // when Units is empty; After sets Units, and Units sets After. Up and Down move between the lines of a column, Left and Right between the cells (in a text
  // box from its ends). Sending puts every line in one field (`lines`): the server checks everything; here a typed word that names no product stops the send.
  // The site's keys work here too (docs/decisions.md AJ1-AJ8; the owner, 6 Nov 2026: "check all purchase order, purchase invoice, sales order like this all
  // form"): this grid is kb.grid for the one table of keys (KEYS): Ctrl+S the page's Save (never "Make it final"), Ctrl+Delete / Alt+R / the x a line out
  // with "Line 3 removed." and Undo (Alt+U, Ctrl+Z when not typing), Ctrl+D the box above (Units, Why, After), Ctrl+Enter / Insert / Alt+N the empty
  // line, the hint line and "All keys (?)". Save (and Ctrl+S) is sent without leaving the page (KG24's one fetch, AJ4): the answer is put in place
  // (swapIn), a new adjustment's first save makes the page its draft's; anything else changes nothing, says so, and the next Save is a plain post.
  each(document.querySelectorAll('form[data-aj-grid]'), function (form) {
    var base = form.getAttribute('data-options') || '';
    var body = form.querySelector('tbody[data-aj-rows]');
    var tpl = form.querySelector('template[data-aj-row]');
    var lines = form.querySelector('input[name="lines"]');
    var alarm = form.querySelector('[data-aj-alarm]');
    var warehouse = form.querySelector('select[name="warehouse"]');
    if (base.indexOf('/ui/stock/adjustments/products?') !== 0 || !body || !tpl || !tpl.content || !lines || !alarm || !warehouse) {
      return;
    }
    var CELLS = ['p', 'u', 'w', 'a'];
    var marker = '[data-aj-fragment]';
    var refreshing = false; // the figures are on their way from another warehouse: After waits for them
    var note = form.querySelector('input[name="note"]');
    var whyAll = form.querySelector('select[name="why"]');
    var rows = [];
    var made = 0;
    var table = form.querySelector('table.aj-table') || body;
    var counters = form.querySelectorAll('[data-aj-count]');
    var undoLine = form.querySelector('[data-kg-undo-line]');
    var undoText = undoLine ? undoLine.querySelector('[data-kg-undo-text]') : null;
    var undoButton = undoLine ? undoLine.querySelector('button[data-kg-undo]') : null;
    var word = function (name) { return form.getAttribute('data-' + name) || ''; };
    var editor = form.getAttribute('data-kg-editor') || '';
    var sending = false; // a save on its way without leaving the page (AJ4): nothing of the lines changes until its answer
    var plain = false; // a save that got no answer of this page: the next one is a plain post
    var undo = null; // the last line removed (AJ2): { r: the line, at: where it was }
    var bulk = false; // many lines drawn at once (the page's first drawing, a save's answer): the numbers and sums once, at the end (AJ7)
    var say = function (text) {
      alarm.textContent = text;
      alarm.hidden = text === '';
    };
    var lookers = 0; // the lines whose product search is on its way: the alarm line says "Looking…" for them
    var number = function (n) { return Number(n).toLocaleString('en-GB'); };
    var units = function (r) {
      var v = r.u.value.trim();
      return /^[+-]?[0-9]{1,8}$/.test(v) ? parseInt(v, 10) : null;
    };
    var blank = function (r) { return r.sku === null && r.p.value.trim() === ''; };
    var count = function () {
      var n = { products: 0, found: 0, gone: 0 };
      each(rows, function (r) {
        var v = units(r);
        if (r.sku !== null && v !== null && v !== 0) {
          n.products += 1;
          n.found += v > 0 ? v : 0;
          n.gone += v < 0 ? -v : 0;
        }
      });
      each(counters, function (el) {
        var v = n[el.getAttribute('data-aj-count')];
        el.textContent = ((v === 1 && el.getAttribute('data-one')) || el.getAttribute('data-text') || '').replace('{n}', number(v));
      });
    };
    // A line's figures from its product and units; `typing`: After is what the person is typing, left as it is.
    var show = function (r, typing) {
      var v = units(r);
      r.tr.classList.toggle('is-up', v !== null && v > 0);
      r.tr.classList.toggle('is-down', v !== null && v < 0);
      // r.at: the warehouse its stock figure is for. Another warehouse on show (a look-up that left it out, or failed after it was chosen there):
      // no stock shown, After off, never the other warehouse's figure (Codex re-checks 1-3, MEDIUM).
      var known = r.sku !== null && r.at === warehouse.value;
      r.tr.classList.toggle('is-below', known && v !== null && r.hand + v < 0);
      r.w.classList.toggle('is-own', r.w.value !== '');
      r.now.textContent = known ? number(r.hand) : '';
      r.a.disabled = !known || refreshing;
      if (!typing) {
        r.a.value = known && v !== null ? number(r.hand + v) : '';
      }
      if (!bulk) {
        count();
      }
    };
    var goTo = function (el) {
      el.focus();
      if (el.select) {
        el.select();
      }
    };
    // A reason may ask for a note (the Reasons page): the box says so, and Make it final waits for it.
    var noted = function (select) {
      var o = select && select.options[select.selectedIndex];
      return !!o && o.getAttribute('data-note') === '1';
    };
    var noteWanted = function () {
      return rows.some(function (r) { return r.sku !== null && (r.w.value !== '' ? noted(r.w) : noted(whyAll)); });
    };
    var noteHint = function () {
      if (note) {
        note.placeholder = form.getAttribute(noteWanted() ? 'data-note-hint' : 'data-optional') || '';
      }
    };
    var renumber = function () {
      each(rows, function (r, i) { r.no.textContent = String(i + 1); });
    };
    var add; // (the lines make lines)
    var hold = function () { // one empty line stays ready at the end
      if (rows.length === 0 || !blank(rows[rows.length - 1])) {
        add({});
      }
    };
    var nextLine = function (r) {
      hold();
      goTo(rows[rows.indexOf(r) + 1].p);
    };
    var move = function (ev, r, field) {
      var i = rows.indexOf(r);
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        var to = rows[i + (ev.key === 'ArrowDown' ? 1 : -1)];
        if (to) {
          goTo(to[field].disabled ? to.u : to[field]);
        }
      } else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') {
        var t = ev.target;
        if (t.tagName === 'INPUT' && !(ev.key === 'ArrowLeft' ? t.selectionStart === 0 : t.selectionEnd === t.value.length)) {
          return;
        }
        var next = r[CELLS[CELLS.indexOf(field) + (ev.key === 'ArrowRight' ? 1 : -1)]];
        if (next && !next.disabled) {
          ev.preventDefault();
          goTo(next);
        }
      }
    };
    // After typed (the count): Units are the difference from the stock on show (AG5, AG6).
    var fromAfter = function (r) {
      var t = r.a.value.replace(/[,\s]/g, '');
      var d = /^-?[0-9]{1,8}$/.test(t) ? parseInt(t, 10) - r.hand : null;
      r.u.value = d === null ? '' : (d > 0 ? '+' + d : String(d));
      show(r, true);
    };
    // A line out at once (its x, Ctrl+Delete, Cmd+Backspace, Alt+R; AJ2): "Line 3 removed." with Undo until the next removal or a send, the cursor in
    // the same box of the line that takes its place; never the empty line at the end, nor while a save is on its way. The line given up for good
    // takes its list with it.
    var forgetUndo = function () {
      if (undo) {
        undo.r.drop();
        undo = null;
      }
      if (undoLine) {
        undoLine.hidden = true;
      }
      if (undoText) {
        undoText.textContent = '';
      }
    };
    var boxOf = function (r, field) {
      return r.sku === null ? r.p : (r[field] && !r[field].disabled ? r[field] : r.u);
    };
    var removeRow = function (r, field) {
      var at = rows.indexOf(r);
      if (sending || at < 0 || (at === rows.length - 1 && blank(r))) {
        return false;
      }
      forgetUndo();
      r.forget();
      rows.splice(at, 1);
      body.removeChild(r.tr);
      form.setAttribute('data-dirty', '1');
      hold();
      renumber();
      count();
      noteHint();
      if (undoText && undoButton) {
        undo = { r: r, at: at };
        undoText.textContent = word('removed').replace('%s', String(at + 1));
        undoLine.hidden = false;
      } else {
        r.drop();
      }
      goTo(boxOf(rows[Math.min(at, rows.length - 1)], field));
      return true;
    };
    var putBack = function () {
      if (!undo || sending) {
        return;
      }
      var u = undo;
      undo = null;
      forgetUndo();
      var at = Math.min(u.at, rows.length);
      body.insertBefore(u.r.tr, rows[at] ? rows[at].tr : null);
      rows.splice(at, 0, u.r);
      form.setAttribute('data-dirty', '1');
      show(u.r);
      renumber();
      noteHint();
      goTo(boxOf(u.r, 'u'));
    };
    if (undoButton) {
      undoButton.addEventListener('click', putBack);
    }
    add = function (d) {
      var tr = tpl.content.firstElementChild.cloneNode(true);
      var cell = function (name) { return tr.querySelector('[data-cell="' + name + '"]'); };
      var list = tr.querySelector('ul');
      list.id = 'aj-list-' + (++made);
      var r = { tr: tr, p: cell('p'), u: cell('u'), w: cell('w'), a: cell('a'), no: tr.querySelector('[data-aj-no]'), code: tr.querySelector('[data-aj-code]'),
        now: tr.querySelector('[data-aj-now]'), sku: null, hand: 0, line: 0, at: warehouse.value }; // a drawn line's figures: the page's warehouse
      body.appendChild(tr);
      rows.push(r);
      var timer = 0;
      var turn = 0;
      var waiting = false; // Tab or Enter came before the answer: the first choice is taken when it comes
      var asking = false; // this line's search is on its way
      var rest = function () {
        if (asking) {
          asking = false;
          lookers--;
        }
      };
      // A search given up (Esc, the empty line's key, its line removed, another warehouse) says nothing more: its "Looking…" goes, unless
      // another line's search is still on its way. Words that are not its own (a refusal, "Failed.") stay (the staging look of 10 Nov 2026:
      // "Looking…" stayed, in red, until the next search).
      var hush = function () {
        if (!asking) {
          return;
        }
        rest();
        if (lookers === 0 && alarm.textContent === (form.getAttribute('data-loading') || '')) {
          say('');
        }
      };
      var options = function () { return list.querySelectorAll('li[role="option"]'); };
      var c = null;
      var draw = function (from, wh) {
        while (list.firstChild) {
          list.removeChild(list.firstChild);
        }
        each(from, function (o, i) {
          var li = document.createElement('li');
          li.id = list.id + '-' + i;
          li.setAttribute('role', 'option');
          li.setAttribute('aria-selected', 'false');
          li.setAttribute('data-wh', wh || ''); // the warehouse its stock is for (Codex re-check 4)
          each(['data-label', 'data-sku', 'data-code', 'data-hand', 'data-units'], function (name) {
            li.setAttribute(name, o.getAttribute(name) || '');
          });
          var part = function (cls) { var p = o.querySelector('.' + cls); return p ? p.textContent.trim() : ''; };
          twoLines(li, part('sm-opt-name'), part('sm-opt-meta'));
          list.appendChild(li);
        });
        c.mark(-1);
      };
      var take = function (li) {
        if (sending) {
          return; // a save is on its way: the line stays as it was sent (AJ4)
        }
        window.clearTimeout(timer);
        turn++;
        waiting = false;
        rest();
        r.sku = parseInt(li.getAttribute('data-sku'), 10);
        r.hand = parseInt(li.getAttribute('data-hand'), 10) || 0;
        r.at = li.getAttribute('data-wh') || warehouse.value; // the warehouse its box asked for
        r.p.value = li.getAttribute('data-label') || '';
        r.code.textContent = li.getAttribute('data-code') || '';
        var scan = parseInt(li.getAttribute('data-units'), 10) || 1;
        if (scan > 1 && r.u.value.trim() === '') {
          r.u.value = '+' + scan; // a case's barcode stands for its units
        }
        draw([]);
        c.close();
        say('');
        show(r);
        noteHint();
        hold();
        goTo(r.u);
      };
      var load = function (q) {
        var mine = ++turn;
        var wh = warehouse.value;
        if (!asking) {
          asking = true;
          lookers++;
        }
        say(form.getAttribute('data-loading') || '');
        fragment(base + 'warehouse=' + encodeURIComponent(wh) + '&q=' + encodeURIComponent(q), marker)
          .then(function (node) {
            if (mine !== turn) {
              return;
            }
            rest();
            draw(node.querySelectorAll('ul[data-aj-choices] > li[data-sku]'), wh);
            var says = node.querySelector('[data-aj-says]');
            say(says ? says.textContent.trim() : '');
            var all = options();
            if (waiting && all.length > 0) {
              take(all[0]);
              return;
            }
            waiting = false;
            c.open(); // (only while the box has the focus)
          })
          .catch(function () {
            if (mine !== turn) {
              return;
            }
            rest();
            waiting = false;
            draw([]);
            say(form.getAttribute('data-failed') || '');
          });
      };
      var commit = function () {
        if (r.sku !== null) {
          goTo(r.u);
          return;
        }
        var all = options();
        if (all.length > 0) {
          take(list.querySelector('li.is-active') || all[0]);
          return;
        }
        var v = r.p.value.trim();
        if (v.length >= 2) {
          waiting = true;
          window.clearTimeout(timer);
          load(v);
        }
      };
      c = combo(r.p, list, {
        options: options,
        choose: take,
        enter: function (ev) {
          if (ev.ctrlKey || ev.metaKey || ev.altKey) {
            return; // Ctrl+Enter: the site's keys (the empty line, AJ1)
          }
          ev.preventDefault();
          commit();
        },
      });
      r.forget = function () { // the search on show was for another warehouse
        window.clearTimeout(timer);
        turn++;
        waiting = false;
        hush();
        draw([]);
        c.close();
      };
      r.drop = function () {
        window.clearTimeout(timer);
        turn++;
        hush();
        c.close();
        if (list.parentNode) {
          list.parentNode.removeChild(list);
        }
      };
      r.p.addEventListener('input', function () {
        window.clearTimeout(timer);
        turn++;
        waiting = false;
        rest();
        r.sku = null;
        r.hand = 0;
        r.line = 0;
        r.code.textContent = '';
        c.unshut();
        draw([]);
        say('');
        show(r);
        var v = r.p.value.trim();
        if (v.length >= 2) {
          timer = window.setTimeout(function () { load(v); }, 300);
        }
      });
      r.p.addEventListener('keydown', function (ev) {
        if (ev.isComposing || ev.defaultPrevented) {
          return; // the list took the key
        }
        if (ev.key === 'Escape') {
          r.forget(); // Esc leaves the box (the keys' layer): a search on its way, or one Enter asked for, chooses nothing after it; the words stay
          return;
        }
        if (ev.key === 'Tab' && !ev.shiftKey && !blank(r)) {
          ev.preventDefault();
          commit();
          return;
        }
        move(ev, r, 'p');
      });
      r.u.addEventListener('input', function () { show(r); });
      r.u.addEventListener('keydown', function (ev) {
        if (!ev.ctrlKey && !ev.metaKey && !ev.altKey && ((ev.key === 'Tab' && !ev.shiftKey) || ev.key === 'Enter')) {
          if (r.sku === null) {
            return; // an empty line: Tab leaves the table
          }
          ev.preventDefault();
          if (r.u.value.trim() === '') {
            goTo(r.a);
          } else {
            nextLine(r);
          }
          return;
        }
        move(ev, r, 'u');
      });
      r.w.addEventListener('keydown', function (ev) { move(ev, r, 'w'); });
      r.w.addEventListener('change', function () {
        show(r);
        noteHint();
      });
      r.a.addEventListener('input', function () { fromAfter(r); });
      r.a.addEventListener('blur', function () { show(r); });
      r.a.addEventListener('keydown', function (ev) {
        if (!ev.ctrlKey && !ev.metaKey && !ev.altKey && ((ev.key === 'Tab' && !ev.shiftKey) || ev.key === 'Enter')) {
          ev.preventDefault();
          nextLine(r);
          return;
        }
        move(ev, r, 'a');
      });
      tr.querySelector('[data-aj-x]').addEventListener('click', function () { removeRow(r, 'u'); });
      if (d.sku) {
        r.sku = d.sku;
        r.line = d.l || 0;
        r.hand = d.hand || 0;
        r.p.value = d.name || '';
        r.code.textContent = d.code || '';
        r.u.value = d.u || '';
        r.w.value = d.r || '';
      }
      show(r);
      if (!bulk) {
        renumber();
        noteHint();
      }
      return r;
    };
    // Many lines at once ($drawn: the page's, or a save's answer): drawn, then numbered and summed once (AJ7).
    var drawAll = function (drawn) {
      bulk = true;
      try {
        each(drawn, function (d) { add(d); });
        hold();
      } finally {
        bulk = false;
      }
      renumber();
      count();
      noteHint();
    };
    var kept = [];
    try {
      kept = JSON.parse(form.getAttribute('data-rows') || '[]');
    } catch (e) {
      kept = [];
    }
    drawAll(kept);
    if (whyAll) {
      whyAll.addEventListener('change', noteHint);
    }
    // Another warehouse: the figures of the lines are that warehouse's.
    // Only the last answer counts, and After waits for it: it is worked out from the figure on show. No answer: back to the warehouse the figures are for.
    var stockTurn = 0;
    var shownWarehouse = warehouse.value;
    // The line kept for Undo is asked about too, so it comes back with this warehouse's stock (Codex round 1, MEDIUM 2).
    var withUndo = function () { return undo ? rows.concat([undo.r]) : rows; };
    warehouse.addEventListener('change', function () {
      var skus = [];
      each(withUndo(), function (r) {
        if (r.sku === null) {
          r.forget();
        } else {
          if (skus.indexOf(r.sku) < 0) {
            skus.push(r.sku); // each product once (the route answers at most StockOps::MAX_LINES)
          }
          r.a.disabled = true;
        }
      });
      if (skus.length === 0) {
        shownWarehouse = warehouse.value;
        return;
      }
      refreshing = true;
      var mine = ++stockTurn;
      var asked = warehouse.value;
      fragment(base + 'warehouse=' + encodeURIComponent(asked) + '&skus=' + skus.join(','), marker)
        .then(function (node) {
          if (mine !== stockTurn) {
            return;
          }
          shownWarehouse = asked;
          refreshing = false;
          var hands = {};
          each(node.querySelectorAll('ul[data-aj-choices] > li[data-sku]'), function (o) {
            hands[o.getAttribute('data-sku')] = parseInt(o.getAttribute('data-hand'), 10) || 0;
          });
          bulk = true;
          each(withUndo(), function (r) {
            if (r.sku !== null && hands[String(r.sku)] !== undefined) {
              r.hand = hands[String(r.sku)];
              r.at = asked;
            } // left out: its figure stays the other warehouse's (show() hides it); chosen meanwhile: its own is this warehouse's already
            show(r);
          });
          bulk = false;
          count();
        })
        .catch(function () {
          if (mine !== stockTurn) {
            return;
          }
          warehouse.value = shownWarehouse;
          refreshing = false;
          each(rows, function (r) {
            if (r.sku === null) {
              r.forget(); // a search for the other warehouse (on its way, or its list on show) is dropped (Codex re-check 4)
            }
          });
          each(rows, function (r) { show(r); });
          say(form.getAttribute('data-failed') || '');
        });
    });
    // Ctrl+Enter in the lines is the empty line (AJ1), even with a product marked in a line's list, which would take it (Codex round 1, LOW 4): that
    // list is shut first (its search dropped; the words stay, to be finished or removed), before the dropdown sees the key.
    form.addEventListener('keydown', function (ev) {
      if (ev.isComposing || ev.key !== 'Enter' || !kbIs(ev, true, false, false) || !table.contains(ev.target)) {
        return; // a word being composed (an input method) keeps its keys (Codex re-check 2, LOW)
      }
      ev.preventDefault();
      toEmpty();
    }, true);
    // Enter in a box of the page never sends it: the two buttons do. Ctrl+Enter in the lines is left to the site's keys (the empty line; AJ1).
    form.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && !(kbIs(ev, true, false, false) && table.contains(ev.target))) {
        ev.preventDefault();
      }
    });
    form.addEventListener('submit', function (ev) {
      if (sending) {
        ev.preventDefault();
        return; // a save is on its way (AJ4)
      }
      var out = [];
      var bad = null;
      each(rows, function (r) {
        if (r.sku !== null) {
          out.push({ sku: r.sku, u: r.u.value.trim(), r: r.w.value, l: r.line });
        } else if (!blank(r) && bad === null) {
          bad = r;
        }
      });
      if (bad !== null) {
        ev.preventDefault();
        say((form.getAttribute('data-choose') || '').replace('{n}', String(rows.indexOf(bad) + 1)));
        goTo(bad.p);
        return;
      }
      if (ev.submitter && ev.submitter.value === 'post' && note && note.value.trim() === '' && noteWanted()) {
        ev.preventDefault();
        say(form.getAttribute('data-note-needed') || '');
        note.focus();
        return;
      }
      lines.value = JSON.stringify(out);
      forgetUndo(); // a send ends the Undo (KG3)
      var by = ev.submitter;
      if (!by || by.getAttribute('name') !== 'action' || by.getAttribute('value') !== 'save' || !canSend()) {
        return; // "Make it final" is a plain post, always (KG15); so is Save after a save that got no answer of this page
      }
      ev.preventDefault();
      ajaxSave(by);
    }, true);
    if (!form.hasAttribute('data-dirty') && !document.querySelector('.error[role="alert"]:not([hidden])')) {
      rows[rows.length - 1].p.focus(); // ready to type
    }
    // Save without leaving the page (AJ4; KG24, GW7): the form sent by the one fetch, a POST to its own address (/new or the draft's /grid) with
    // its token, FormOnce key or version and every line in `lines`; meanwhile the boxes are held (kgHold) and nothing of the lines changes.
    var canSend = function () { return !plain && !sending && /^adj:/.test(editor) && !!window.FormData && !!window.fetch && !!window.DOMParser; };
    var freeze = kgHold(form);
    var ajaxSave = function (by) {
      var data = new window.FormData(form);
      data.set('action', 'save');
      sending = true;
      freeze(true);
      by.disabled = true;
      kgToast(word('saving'), false, true);
      var done = function () {
        sending = false;
        freeze(false);
        by.disabled = false;
      };
      fragment(form.getAttribute('action') || '', '[data-kg-editor]', data)
        .then(function (part) {
          done();
          var said = swapIn(part);
          if (said === null) {
            throw new Error('not this page');
          }
          kgToast(said.error !== '' ? said.error : (said.notice !== '' ? said.notice : word('saved')), said.error !== '');
        })
        .catch(function () {
          done();
          plain = true;
          kgToast(word('save-failed'), true);
        });
    };
    // A save's answer put in place (AJ4, AJ5). Only this grid's editor: a new adjustment's answer is itself again (a refusal) or its draft (the first
    // save); a draft's, the same draft. Changed meanwhile (data-kg-conflict, another tab): nothing changes on the page (what was typed stays to be
    // seen), the server's words are said, and the next Save is a plain post, which draws the record as it is now. Otherwise: around the form, a first
    // save takes everything as the server drew it (the draft's head with its More, its files), wired as at the start; a draft keeps its head (its
    // More's forms and their state) and takes the rest before the form (the crumbs, a refusal's words); every other form of the record on the page
    // takes the new version. In the form: its address, marker and hidden fields as drawn (a draft's version; a refusal's new FormOnce key), its
    // lines as the server has them (a refusal: as typed) and whether it holds something to save; the cursor back on the same line and box; a first
    // save's address in the address bar. Returns what to say: the refusal, else the notice (data-aj-notice: the layout's own is a toast outside the
    // part put in place); null when the answer is not this page.
    var swapIn = function (part) {
      var nf = part.querySelector('form[data-kg-editor]');
      var mark = nf ? nf.getAttribute('data-kg-editor') || '' : '';
      if (!nf || !nf.hasAttribute('data-aj-grid') || !/^adj:([1-9][0-9]{0,17}|new)$/.test(mark) || (editor !== 'adj:new' && mark !== editor)) {
        return null;
      }
      var theirs = nf.parentNode;
      // The page's own words for a browser without the script (<noscript>, AG1) are not a refusal: an answer is read with the script off
      // (DOMParser), so there they are a real paragraph, right before the form (the check of 10 Nov 2026: every good save said them in red).
      var noScript = function (el) { return el.nodeType === 1 && el.tagName === 'NOSCRIPT'; };
      var refused = Array.prototype.filter.call(theirs.querySelectorAll('p.error[role="alert"]'), function (p) {
        return !nf.contains(p) && !p.closest('noscript');
      })[0];
      var error = refused ? refused.textContent.trim() : '';
      if (nf.hasAttribute('data-kg-conflict')) {
        plain = true;
        return { error: error !== '' ? error : word('save-failed'), notice: '' };
      }
      var active = document.activeElement;
      var at = -1;
      var cell = null;
      each(rows, function (r, i) {
        if (at < 0 && active && r.tr.contains(active)) {
          at = i;
          cell = active.getAttribute('data-cell');
        }
      });
      var parent = form.parentNode;
      var whole = mark !== editor;
      var wire = function (copy) {
        if (copy.nodeType !== 1) {
          return;
        }
        var forms = Array.prototype.slice.call(copy.querySelectorAll('form[method="post"]'));
        if (copy.tagName === 'FORM' && copy.getAttribute('method') === 'post') {
          forms.push(copy);
        }
        each(forms, function (f) {
          sendOnce(f);
          if (f.hasAttribute('data-leaves')) {
            leaving(f);
          }
        });
      };
      var isHead = function (el) { return el.nodeType === 1 && el.classList.contains('doc-head'); };
      var mine = [];
      for (var el = parent.firstChild; el && el !== form; el = el.nextSibling) {
        mine.push(el);
      }
      each(mine, function (el) {
        if (whole || !isHead(el)) {
          parent.removeChild(el);
        }
      });
      var myHead = null;
      for (var h = parent.firstChild; h && h !== form; h = h.nextSibling) {
        if (isHead(h)) {
          myHead = h;
        }
      }
      var beforeHead = !!myHead;
      for (var el2 = theirs.firstChild; el2 && el2 !== nf; el2 = el2.nextSibling) {
        if (noScript(el2)) {
          continue; // not for this page: the script is on
        }
        if (myHead && isHead(el2)) {
          beforeHead = false; // in their order: the crumbs before the head kept, the rest (a refusal's words) between it and the form
          continue;
        }
        var copy = document.importNode(el2, true);
        parent.insertBefore(copy, beforeHead ? myHead : form);
        wire(copy);
      }
      if (whole) {
        var after = [];
        for (var el3 = form.nextSibling; el3; el3 = el3.nextSibling) {
          after.push(el3);
        }
        each(after, function (el) { parent.removeChild(el); });
        for (var el4 = nf.nextSibling; el4; el4 = el4.nextSibling) {
          if (noScript(el4)) {
            continue;
          }
          var copy2 = document.importNode(el4, true);
          parent.appendChild(copy2);
          wire(copy2);
        }
      }
      each(['action', 'data-kg-address', 'data-aj-notice'], function (a) {
        if (nf.getAttribute(a) !== null) {
          form.setAttribute(a, nf.getAttribute(a));
        } else {
          form.removeAttribute(a);
        }
      });
      form.setAttribute('data-kg-editor', mark);
      editor = mark;
      var hiddenOf = function (f) { return Array.prototype.filter.call(f.querySelectorAll('input[type="hidden"][name]'), function (i) { return i.parentNode === f; }); };
      var mineHidden = {};
      each(hiddenOf(form), function (i) { mineHidden[i.name] = i; });
      var theirsHidden = {};
      each(hiddenOf(nf), function (i) {
        theirsHidden[i.name] = true;
        if (i.name === 'lines') {
          return; // the lines go in at the next send
        }
        if (mineHidden[i.name]) {
          mineHidden[i.name].value = i.value;
        } else {
          form.insertBefore(document.importNode(i, true), lines);
        }
      });
      each(Object.keys(mineHidden), function (name) {
        if (!theirsHidden[name] && name !== 'lines') {
          form.removeChild(mineHidden[name]); // a draft has no FormOnce key
        }
      });
      var version = form.querySelector('input[name="version"]');
      var stem = (form.getAttribute('data-kg-address') || '') + '/';
      if (version && stem.charAt(0) === '/' && stem !== '/') {
        each(document.querySelectorAll('form'), function (f) {
          var fv = f !== form ? f.querySelector('input[name="version"]') : null;
          if (fv && (f.getAttribute('action') || '').indexOf(stem) === 0) {
            fv.value = version.value;
          }
        });
      }
      // The details as the server has them: what it saved. The reason for every line is the one it reads the lines by (a draft's first line's own
      // reason becomes it: Codex round 1, MEDIUM 1); the warehouse is the one sent, even when a look-up of its figures failed meanwhile and put the
      // box back (MEDIUM 3); a look-up still on its way is for the page before this answer.
      each(nf.querySelectorAll('section.aj-top [name]'), function (theirsField) {
        var mineField = form.querySelector('section.aj-top [name="' + theirsField.getAttribute('name') + '"]');
        if (mineField && (mineField.tagName === 'SELECT' || mineField.tagName === 'INPUT')) {
          mineField.value = theirsField.value;
        }
      });
      stockTurn++;
      refreshing = false;
      shownWarehouse = warehouse.value;
      var drawn = [];
      try {
        drawn = JSON.parse(nf.getAttribute('data-rows') || '[]');
      } catch (e) {
        drawn = [];
      }
      forgetUndo();
      each(rows, function (r) {
        r.drop();
        if (r.tr.parentNode === body) {
          body.removeChild(r.tr);
        }
      });
      rows.splice(0, rows.length);
      drawAll(drawn);
      if (nf.hasAttribute('data-dirty')) {
        form.setAttribute('data-dirty', '1');
      } else {
        form.removeAttribute('data-dirty');
      }
      if (at >= 0 && rows.length > 0) {
        goTo(boxOf(rows[Math.min(at, rows.length - 1)], cell || 'u'));
      }
      var address = form.getAttribute('data-kg-address') || '';
      if (whole && address.charAt(0) === '/' && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', address);
      }
      kgTitle(part); // the tab's name with it (a first save: "New adjustment" becomes the draft's)
      return { error: error, notice: error === '' ? nf.getAttribute('data-aj-notice') || '' : '' };
    };
    // What the site's keys do in these lines (kb, KEYS; AJ1): the cells keep their own Tab / Enter / arrows (AG5: they are not data-kg-cell, so
    // the table's next and updown pass them by); the keys that need the lines call in here.
    kb.grid = {
      table: table,
      keys: ['next', 'back', 'updown', 'new_line', 'delete_line', 'undo_line', 'fill_down', 'save', 'esc'],
      own: function (t) { return t.hasAttribute('data-cell') && rowOf(t) !== null; }, // Esc leaves one of these boxes (the list, when open, shuts first)
      marked: function () { return null; },
      takeMarked: function () {},
      step: function () {},
      vertical: function () { return false; },
      toEmpty: function () { toEmpty(); }, // Ctrl+Enter, Insert, Alt+N: the empty line's Product box
      removeAt: function (from) {
        var r = rowOf(from);
        return r ? removeRow(r, from.getAttribute('data-cell') || 'u') : false;
      },
      canUndo: function () { return undo !== null; },
      undo: putBack,
      // Ctrl+D (a spreadsheet's fill down): the box above into this one: Units, Why, or After (its Units then from this line's own stock). In
      // Product, the browser's own; on the first line nothing to copy.
      fillDown: function (from) {
        var r = rowOf(from);
        var name = from.getAttribute('data-cell');
        if (!r || (name !== 'u' && name !== 'w' && name !== 'a')) {
          return false;
        }
        var above = rows[rows.indexOf(r) - 1];
        if (sending || !above) {
          return true;
        }
        if (name === 'u') {
          r.u.value = above.u.value;
          show(r);
        } else if (name === 'w') {
          r.w.value = above.w.value;
          show(r);
          noteHint();
        } else {
          if (r.sku === null || above.sku === null || r.a.disabled) {
            return true;
          }
          r.a.value = above.a.value;
          fromAfter(r);
        }
        form.setAttribute('data-dirty', '1');
        goTo(from);
        return true;
      },
      save: function () { // Ctrl+S: the page's Save, never "Make it final" (KG15)
        var save = form.querySelector('button[name="action"][value="save"]');
        if (save && !save.disabled) {
          save.click();
        }
      },
    };
    // The empty line's Product box (Ctrl+Enter, Insert, Alt+N): a product box left first drops its search still on its way and shuts its list, so
    // neither a marked choice nor a late answer takes a product there (Codex round 1 LOW, re-check LOW); its words stay, to finish or remove.
    var toEmpty = function () {
      var at = document.activeElement;
      var from = at ? rowOf(at) : null;
      if (from && at === from.p) {
        from.forget();
      }
      hold();
      goTo(rows[rows.length - 1].p);
    };
    var rowOf = function (el) {
      var found = null;
      each(rows, function (r) {
        if (found === null && r.tr.contains(el)) {
          found = r;
        }
      });
      return found;
    };
  });

  // A family's "Add items" picker (the owner, 5 Nov 2026: "i need a drop down with search and multicheck option"; docs/decisions.md FP1-FP11).
  // The picker is ONE POST form of the family's /pick route, drawn hidden: shown here (without the script, or without fetch, the pasted list
  // under it is the way, open as drawn; here it is folded unless it holds a refused list). Typing (2 letters or more, 300 ms after the last
  // key; at once with Enter, which never sends the form: a scanner's Enter) asks the family's find route (the read-only fragment at
  // data-options) for the items the words name; its list replaces the one shown when it comes, and an answer asked for before newer words is
  // never drawn. A tick copies the item into the form's ticked list, and the form's ONE field `items` holds the ticked ids in that list's
  // order (one field however many are ticked: a field each could pass PHP's max_input_vars, FP11): the ticks stay while the words change,
  // and only `items` is sent (a tick in the list has no name). Untick there or in the list. "Tick all shown" ticks every item
  // shown that may be ticked; "Untick all" unticks every one. At most data-max (Families::bulkMax) are ticked: a tick past it, or a "Tick all
  // shown" that would pass it, is refused with the page's words (data-too-many). The count and the button say how many; the button is off at
  // none. The form holds something to keep (data-dirty: the page's guard asks before another form or a link leaves) while, and only while,
  // something is ticked. Every word comes from the page.
  each(document.querySelectorAll('form[data-fp]'), function (form) {
    var base = form.getAttribute('data-options') || '';
    var input = form.querySelector('input[data-fp-q]');
    var says = form.querySelector('[data-fp-says]');
    var out = form.querySelector('[data-fp-results]');
    var list = form.querySelector('ul[data-fp-ticked]');
    var empty = form.querySelector('[data-fp-empty]');
    var count = form.querySelector('[data-fp-count]');
    var warn = form.querySelector('[data-fp-warn]');
    var all = form.querySelector('button[data-fp-all]');
    var none = form.querySelector('button[data-fp-none]');
    var go = form.querySelector('button[data-fp-go]');
    var items = form.querySelector('input[type="hidden"][data-fp-items]');
    if (base.indexOf('/ui/items/families/') !== 0 || base.indexOf('/find?') < 0 || !input || !says || !out || !list || !empty || !count || !warn || !all
      || !none || !go || !items || !window.fetch || !window.DOMParser) {
      return;
    }
    var max = parseInt(form.getAttribute('data-max') || '', 10) || 1;
    var word = function (name) { return form.getAttribute('data-' + name) || ''; };
    var fill = function (text, v) { return text.replace('%s', String(v)); };
    var ticked = {}; // an item's id => its <li> in the ticked list
    var has = function (id) { return Object.prototype.hasOwnProperty.call(ticked, id); };
    var n = 0;
    var timer = 0;
    var turn = 0;
    var alarm = function (text) {
      warn.textContent = text;
      warn.hidden = text === '';
    };
    var boxes = function () { return out.querySelectorAll('input[type="checkbox"][data-fp-box]'); };
    var clear = function () {
      while (out.firstChild) {
        out.removeChild(out.firstChild);
      }
      out.removeAttribute('aria-busy');
    };
    // Everything follows the ticked list: what the form sends, the count, the button, the empty line, the ticks in the list shown, Tick
    // all shown, and whether the form holds something to keep.
    var redraw = function () {
      var ids = [];
      each(list.querySelectorAll('li[data-sku]'), function (li) { ids.push(li.getAttribute('data-sku')); });
      items.value = ids.join(',');
      count.textContent = fill(word('ticked-text'), n);
      go.textContent = fill(word('go-text'), n);
      go.disabled = n === 0;
      empty.hidden = n > 0;
      if (n > 0) {
        form.setAttribute('data-dirty', '1');
      } else {
        form.removeAttribute('data-dirty');
      }
      var free = 0;
      each(boxes(), function (b) {
        b.checked = has(b.value);
        free += !b.disabled && !b.checked ? 1 : 0;
      });
      all.disabled = free === 0;
    };
    var add = function (id, code, name) {
      if (has(id) || !/^[1-9][0-9]{0,17}$/.test(id)) {
        return;
      }
      var li = document.createElement('li');
      li.setAttribute('data-sku', id);
      var part = function (cls, text) {
        var s = document.createElement('span');
        s.className = cls;
        s.textContent = text;
        return s;
      };
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'btn sm ghost';
      x.setAttribute('data-fp-untick', '');
      x.setAttribute('aria-label', fill(word('untick-label'), code));
      x.textContent = word('untick-text');
      li.appendChild(part('fp-t-name', name));
      li.appendChild(document.createTextNode(' '));
      li.appendChild(part('fp-t-code', code));
      li.appendChild(document.createTextNode(' '));
      li.appendChild(x);
      list.appendChild(li);
      ticked[id] = li;
      n++;
    };
    var drop = function (id) {
      if (!has(id)) {
        return;
      }
      var li = ticked[id];
      if (li.parentNode) {
        li.parentNode.removeChild(li);
      }
      delete ticked[id];
      n--;
    };
    // The ticked items the page drew (a refused picker brings them back).
    each(list.querySelectorAll('li[data-sku]'), function (li) {
      var id = li.getAttribute('data-sku') || '';
      if (has(id)) {
        li.parentNode.removeChild(li);
        return;
      }
      ticked[id] = li;
      n++;
    });
    var load = function (q) {
      var mine = ++turn;
      out.setAttribute('aria-busy', 'true');
      says.textContent = word('loading');
      fragment(base + 'q=' + encodeURIComponent(q), '[data-fp-fragment]')
        .then(function (node) {
          if (mine !== turn) {
            return;
          }
          var found = node.querySelector('ul[data-fp-choices]');
          var line = node.querySelector('[data-fp-says]');
          clear();
          if (found) {
            out.appendChild(found);
          }
          says.textContent = line ? line.textContent.trim() : '';
          redraw();
        })
        .catch(function () {
          if (mine !== turn) {
            return;
          }
          clear();
          says.textContent = word('failed');
          redraw();
        });
    };
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      turn++; // the words changed: no answer asked for before them is drawn
      var v = input.value.trim();
      if (v.length < 2) {
        clear();
        says.textContent = v === '' ? '' : word('short');
      } else {
        timer = window.setTimeout(function () { load(v); }, 300);
      }
      redraw(); // words typed are no tick: the form holds something to keep only while something is ticked
    });
    input.addEventListener('change', redraw); // nor when the box is left (the page's marker counts a change as an edit)
    input.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') {
        return;
      }
      ev.preventDefault(); // Enter in the search never sends the form: it looks the words up now
      window.clearTimeout(timer);
      var v = input.value.trim();
      if (v.length >= 2) {
        load(v);
      }
    });
    out.addEventListener('change', function (ev) {
      var b = ev.target;
      if (!b || !b.hasAttribute || !b.hasAttribute('data-fp-box') || b.disabled) {
        return;
      }
      alarm('');
      if (b.checked && !has(b.value)) {
        if (n >= max) {
          alarm(word('too-many'));
        } else {
          add(b.value, b.getAttribute('data-cw') || '', b.getAttribute('data-name') || '');
        }
      } else if (!b.checked) {
        drop(b.value);
      }
      redraw();
    });
    all.addEventListener('click', function () {
      var free = [];
      each(boxes(), function (b) {
        if (!b.disabled && !has(b.value)) {
          free.push(b);
        }
      });
      alarm('');
      if (n + free.length > max) {
        alarm(word('too-many'));
        return;
      }
      each(free, function (b) { add(b.value, b.getAttribute('data-cw') || '', b.getAttribute('data-name') || ''); });
      redraw();
    });
    none.addEventListener('click', function () {
      each(Object.keys(ticked), drop);
      alarm('');
      redraw();
    });
    list.addEventListener('click', function (ev) {
      var b = ev.target && ev.target.closest ? ev.target.closest('button[data-fp-untick]') : null;
      var li = b ? b.closest('li[data-sku]') : null;
      if (!li) {
        return;
      }
      // The focus goes to the next untick in the list (else the one before, else the search), never lost with the row.
      var near = li.nextElementSibling || li.previousElementSibling;
      var next = near ? near.querySelector('button[data-fp-untick]') : null;
      drop(li.getAttribute('data-sku') || '');
      alarm('');
      redraw();
      (next || input).focus();
    });
    form.addEventListener('submit', function (ev) {
      if (n === 0 || n > max) {
        ev.preventDefault();
        alarm(n === 0 ? '' : word('too-many'));
      }
    });
    form.hidden = false;
    var paste = form.parentNode ? form.parentNode.querySelector('details.fp-paste[data-fold]') : null;
    if (paste) {
      paste.open = false; // the second way, folded (without the script it is the way, open)
    }
    // Opening "Add items" puts the cursor in the search box.
    var fold = form.closest('details');
    var head = fold ? fold.querySelector('summary') : null;
    if (head) {
      head.addEventListener('click', function () {
        window.setTimeout(function () {
          if (fold.open) {
            input.focus();
          }
        }, 0);
      });
    }
    redraw();
  });

  // A short page (a new purchase order with a line or two) left the bottom bar half way up the screen (seen 2 Nov 2026): the free space
  // under the page goes above the bar, so the bar stands at the foot of the screen as on a long page. A page that scrolls, or one with
  // something drawn under its bar (a stock operation's files), is not touched.
  var footBars = document.querySelectorAll('.work .doc-bar');
  var footBar = footBars.length > 0 ? footBars[footBars.length - 1] : null;
  var work = footBar ? footBar.closest('.work') : null;
  if (footBar && work) {
    var lift = function () {
      footBar.style.marginTop = '';
      footBar.style.marginBottom = '';
      if (footBar.offsetParent === null) {
        return; // not drawn
      }
      // Measured on the screen, set in the page, drawn at --zoom (app.css), as the lists' place() does.
      var zoom = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--zoom')) || 1;
      var pad = parseFloat(window.getComputedStyle(work).paddingBottom) || 0;
      var end = work.getBoundingClientRect().bottom;
      var free = window.innerHeight - end;
      if (free > 1 && Math.abs(end - pad * zoom - footBar.getBoundingClientRect().bottom) < 2) {
        // The work area's own bottom padding moves above the bar too: flush with the foot of the screen, and still nothing to scroll.
        footBar.style.marginTop = (parseFloat(window.getComputedStyle(footBar).marginTop) || 0) + free / zoom + pad + 'px';
        footBar.style.marginBottom = -pad + 'px';
      }
    };
    lift();
    window.addEventListener('resize', lift);
    if (window.ResizeObserver) {
      new window.ResizeObserver(lift).observe(footBar.closest('.page') || work); // a fold opened, a line added
    }
  }
})();
