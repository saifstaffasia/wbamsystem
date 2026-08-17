/* Shared helpers: REST wrapper + the "Refresh from Shopify" button. */
window.wbamFetch = function (path, opts) {
  opts = opts || {};
  opts.headers = Object.assign({ 'X-WP-Nonce': WBAM.nonce, 'Content-Type': 'application/json' }, opts.headers || {});
  return fetch(WBAM.rest + path, opts).then(function (r) {
    return r.json().then(function (j) {
      if (!r.ok) throw new Error((j && (j.message || j.code)) || ('HTTP ' + r.status));
      return j;
    });
  });
};

document.addEventListener('click', function (e) {
  var b = e.target.closest('.wbam-refresh');
  if (!b) return;
  b.disabled = true;
  var old = b.textContent;
  b.textContent = 'Refreshing…';
  fetch(b.dataset.rest, { method: 'POST', headers: { 'X-WP-Nonce': b.dataset.nonce } })
    .then(function (r) { if (!r.ok) throw 0; location.reload(); })
    .catch(function () { b.textContent = 'Failed — try again'; b.disabled = false; setTimeout(function(){ b.textContent = old; }, 4000); });
});
