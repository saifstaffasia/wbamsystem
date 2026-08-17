/* Device intake: scan-first flow.
   IMEI field autofocused → type model → pick from list → option dropdowns appear
   → price → Save + print. Total target: under 60 seconds per phone. */
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var chosen = null;
  var t;

  $('wi-model').addEventListener('input', function () {
    clearTimeout(t);
    var term = this.value.trim();
    if (term.length < 2) { $('wi-model-list').innerHTML = ''; return; }
    t = setTimeout(function () {
      wbamFetch('models?term=' + encodeURIComponent(term)).then(function (models) {
        $('wi-model-list').innerHTML = models.map(function (m, i) {
          return '<button type="button" class="wbam-opt" data-i="' + i + '">' + m.title + '</button>';
        }).join('');
        $('wi-model-list')._models = models;
      }).catch(function () {});
    }, 250);
  });

  $('wi-model-list').addEventListener('click', function (e) {
    var b = e.target.closest('.wbam-opt');
    if (!b) return;
    chosen = this._models[+b.dataset.i];
    $('wi-model').value = chosen.title;
    $('wi-product-id').value = chosen.product_id;
    $('wi-model-title').value = chosen.title;
    this.innerHTML = '';
    // Build a dropdown per product option (Colour / Storage / Condition …).
    var html = '';
    Object.keys(chosen.options).forEach(function (name) {
      html += '<label>' + name + ' <select class="wi-opt" data-name="' + name + '">'
            + chosen.options[name].map(function (v) { return '<option>' + v + '</option>'; }).join('')
            + '</select></label>';
    });
    $('wi-options').innerHTML = html;
  });

  $('wi-save').addEventListener('click', function () {
    var status = $('wi-status');
    if (!$('wi-product-id').value) { status.textContent = 'Pick the model from the list first.'; return; }
    var selected = {};
    document.querySelectorAll('.wi-opt').forEach(function (s) { selected[s.dataset.name] = s.value; });
    var body = {
      imei: $('wi-imei').value,
      product_id: +$('wi-product-id').value,
      model_title: $('wi-model-title').value,
      selected: selected,
      purchase_price: parseFloat($('wi-price').value || '0'),
      branch_id: +(document.querySelector('[name=branch_id]').value),
      source: $('wi-source').value,
      source_ref: $('wi-ref').value,
      payout_method: $('wi-payout').value,
      battery_health: $('wi-battery').value,
      checkmend_ref: $('wi-checkmend').value,
      notes: $('wi-notes').value
    };
    this.disabled = true;
    status.textContent = 'Saving…';
    var btn = this;
    wbamFetch('intake', { method: 'POST', body: JSON.stringify(body) })
      .then(function (res) {
        status.textContent = '';
        var u = res.unit;
        $('wi-done').innerHTML =
          '<div class="notice notice-success"><p><b>' + u.unit_code + '</b> saved — ' +
          u.model_title + ' ' + u.variant_title + ' · £' + (+u.purchase_price).toFixed(2) +
          ' · <a class="button" target="_blank" href="' + u.label_url + '">Print label</a></p></div>';
        window.open(u.label_url, '_blank'); // auto-print page
        // Reset for the next phone (keep model & options for batch intake).
        $('wi-imei').value = ''; $('wi-price').value = ''; $('wi-ref').value = '';
        $('wi-battery').value = ''; $('wi-checkmend').value = ''; $('wi-notes').value = '';
        $('wi-imei').focus();
        btn.disabled = false;
      })
      .catch(function (err) {
        status.textContent = '⚠ ' + err.message;
        btn.disabled = false;
      });
  });
})();
