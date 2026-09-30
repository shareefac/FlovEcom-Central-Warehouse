/* Central Warehouse staff screens: two small conveniences, nothing else. Loaded as an external file (CSP: no inline script). */
(function () {
  'use strict';

  // The decision form: show the two-person note as soon as units per item is not 1.
  var units = document.getElementById('units');
  var note = document.getElementById('note-units');
  if (units && note) {
    var sync = function () {
      var v = units.value.trim();
      note.hidden = v === '' || v === '1';
    };
    units.addEventListener('input', sync);
    units.addEventListener('change', sync);
    sync();
  }

  // A form that changes something is sent once: the button is disabled on the first submit.
  var forms = document.querySelectorAll('form[method="post"]');
  Array.prototype.forEach.call(forms, function (form) {
    form.addEventListener('submit', function () {
      var buttons = form.querySelectorAll('button[type="submit"]');
      window.setTimeout(function () {
        Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
      }, 0);
    });
  });

  // Coming back with the back button must not leave a disabled button behind.
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      Array.prototype.forEach.call(document.querySelectorAll('button[type="submit"]'), function (b) { b.disabled = false; });
    }
  });
})();
