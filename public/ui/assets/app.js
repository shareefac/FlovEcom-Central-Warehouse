/* Central Warehouse staff screens: four small conveniences, nothing else. Loaded as an external file (CSP: no inline script). */
(function () {
  'use strict';

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
