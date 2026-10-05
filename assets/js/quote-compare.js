window.ispagT = window.ispagT || function (s) { return s; }; // traductions des textes JS (voir includes/js-strings.php)
/**
 * Comparaison d'une offre fournisseur (analysée par Mistral) avec les cuves de la commande d'achat.
 * Appelé par dropzone.js (plugin Project Manager) quand l'analyse « analyze_and_confirm_data » d'un document est terminée :
 *   window.ispagQuoteCompare(resultatMistral, purchaseId, dealId)
 * Une fenêtre propose, pour la cuve de l'offre choisie, la cuve de la commande la plus proche (modifiable) ; les champs et le prix net
 * cochés sont enregistrés (ISPAG_Achat_Quote_Compare).
 */
(function ($) {
  'use strict';
  const T = ispagT;
  const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  const fmt = function (n) { return n == null || n === '' ? '—' : Number(n).toLocaleString('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  const num = function (v) { if (v == null || v === '') return null; const x = parseFloat(String(v).replace(/['\s’]/g, '').replace(',', '.')); return isNaN(x) ? null : x; };

  const CSS = '#ispag-quote-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:100002;display:flex;align-items:center;justify-content:center;padding:16px}' +
    '#ispag-quote-modal .qc-box{background:#fff;border-radius:12px;max-width:860px;width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.3)}' +
    '#ispag-quote-modal .qc-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb}' +
    '#ispag-quote-modal .qc-head h3{margin:0;font-size:18px}#ispag-quote-modal .qc-x{border:0;background:none;font-size:24px;cursor:pointer;line-height:1}' +
    '#ispag-quote-modal .qc-body{padding:16px 20px;overflow:auto}' +
    '#ispag-quote-modal .qc-pick{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px}#ispag-quote-modal .qc-pick label{display:flex;flex-direction:column;font-size:12px;color:#6b7280;gap:4px;flex:1;min-width:240px}' +
    '#ispag-quote-modal select{width:100%}' +
    '#ispag-quote-modal table{width:100%;border-collapse:collapse;font-size:13px}#ispag-quote-modal th,#ispag-quote-modal td{padding:8px 10px;border-bottom:1px solid #eef0f2;text-align:left}' +
    '#ispag-quote-modal th{font-size:11px;text-transform:uppercase;color:#6b7280}#ispag-quote-modal tr.is-diff td{background:#fff8e1}#ispag-quote-modal tr.is-same td{color:#6b7280}' +
    '#ispag-quote-modal .qc-score{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e6f4ea;color:#1e7b34}' +
    '#ispag-quote-modal .qc-score.mid{background:#fff3cd;color:#8a6d00}#ispag-quote-modal .qc-score.low{background:#fde8ea;color:#b32d2e}' +
    '#ispag-quote-modal .qc-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #e5e7eb}' +
    '#ispag-quote-modal .qc-msg{color:#b32d2e;font-size:13px;margin-top:8px}';

  function scoreBadge(s) { return '<span class="qc-score ' + (s >= 70 ? '' : (s >= 40 ? 'mid' : 'low')) + '">' + s + ' %</span>'; }

  function open(data, purchaseId, dealId) {
    if (!document.getElementById('ispag-quote-css')) $('<style id="ispag-quote-css">').text(CSS).appendTo('head');
    $('#ispag-quote-modal').remove();
    const quotes = data.quotes || [], tanks = data.tanks || [], cur = data.currency || 'CHF';
    const $m = $('<div id="ispag-quote-modal"><div class="qc-box"><div class="qc-head"><h3></h3><button type="button" class="qc-x" aria-label="Close">&times;</button></div><div class="qc-body"></div><div class="qc-foot"></div></div></div>').appendTo('body');
    $m.find('h3').text(T('Compare the quote with the order'));
    const $body = $m.find('.qc-body'), $foot = $m.find('.qc-foot');
    const close = function () { $m.remove(); };
    $m.on('click', '.qc-x, .qc-cancel', close).on('click', function (e) { if (e.target === $m[0]) close(); });

    if (!quotes.length) { $body.html('<p>' + esc(T('No tank was found in the document.')) + '</p>'); $foot.html('<button type="button" class="ispag-btn ispag-btn-grey-outlined qc-cancel">' + esc(T('Close')) + '</button>'); return; }
    if (!tanks.length) { $body.html('<p>' + esc(T('This order has no tank to compare with.')) + '</p>'); $foot.html('<button type="button" class="ispag-btn ispag-btn-grey-outlined qc-cancel">' + esc(T('Close')) + '</button>'); return; }

    const qOpts = quotes.map(function (q, i) { return '<option value="' + i + '">' + esc((i + 1) + '. ' + (q.title || T('Tank')) + (q.diameter ? ' — Ø' + q.diameter : '') + (q.volume ? ' — ' + q.volume + ' L' : '')) + '</option>'; }).join('');
    $body.html('<div class="qc-pick">' +
      '<label>' + esc(T('Tank in the quote')) + '<select id="qc-quote">' + qOpts + '</select></label>' +
      '<label>' + esc(T('Tank of the order')) + ' <span id="qc-score"></span><select id="qc-tank"></select></label></div>' +
      '<table><thead><tr><th style="width:34px"></th><th>' + esc(T('Field')) + '</th><th>' + esc(T('In the order')) + '</th><th>' + esc(T('In the quote')) + '</th></tr></thead><tbody id="qc-rows"></tbody></table>' +
      '<div class="qc-msg" id="qc-msg" style="display:none"></div>');
    $foot.html('<button type="button" class="ispag-btn ispag-btn-grey-outlined qc-cancel">' + esc(T('Cancel')) + '</button><button type="button" class="ispag-btn ispag-btn-red-outlined" id="qc-import">' + esc(T('Import the selection')) + '</button>');

    function fillTanks(qi) {
      const q = quotes[qi], scores = q.scores || {};
      const $t = $m.find('#qc-tank').empty();
      // la cuve la plus proche d'abord dans la liste
      const order = tanks.map(function (t, i) { return i; }).sort(function (a, b) { return (scores[b] || 0) - (scores[a] || 0); });
      order.forEach(function (ti) {
        const t = tanks[ti];
        $t.append($('<option>').val(ti).text(t.title + ' — ' + (scores[ti] || 0) + ' %'));
      });
      $t.val(q.suggested != null ? q.suggested : order[0]);
    }

    function render() {
      const q = quotes[+$m.find('#qc-quote').val()], t = tanks[+$m.find('#qc-tank').val()], fields = data.fields || {};
      const sc = (q.scores || {})[+$m.find('#qc-tank').val()] || 0;
      $m.find('#qc-score').html(scoreBadge(sc));
      let rows = '';
      Object.keys(fields).forEach(function (k) {
        const newV = q[k], oldV = t.values[k];
        if ((newV == null || newV === '') && (oldV == null || oldV === '')) return;
        const a = num(newV), b = num(oldV);
        const same = (a != null && b != null) ? Math.abs(a - b) < 0.0001 : String(newV == null ? '' : newV).toLowerCase() === String(oldV == null ? '' : oldV).toLowerCase();
        const can = newV != null && newV !== '';
        rows += '<tr class="' + (same ? 'is-same' : 'is-diff') + '"><td><input type="checkbox" class="qc-field" data-key="' + esc(k) + '" data-val="' + esc(newV) + '"' + (can && !same ? ' checked' : '') + (can ? '' : ' disabled') + '></td>' +
          '<td>' + esc(fields[k]) + '</td><td>' + esc(oldV === '' || oldV == null ? '—' : oldV) + '</td><td>' + esc(can ? newV : '—') + '</td></tr>';
      });
      const np = q.net_price, hasP = np != null;
      rows += '<tr class="' + (hasP && Math.abs(np - t.net_price) < 0.005 ? 'is-same' : 'is-diff') + '"><td><input type="checkbox" id="qc-price" data-val="' + (hasP ? esc(np) : '') + '"' + (hasP && Math.abs(np - t.net_price) >= 0.005 ? ' checked' : '') + (hasP ? '' : ' disabled') + '></td>' +
        '<td><strong>' + esc(T('Net unit price')) + '</strong> (' + esc(cur) + ')</td><td>' + fmt(t.net_price) + '</td><td>' + (hasP ? fmt(np) : '—') + '</td></tr>';
      $m.find('#qc-rows').html(rows);
      $m.find('#qc-msg').hide();
    }

    $m.on('change', '#qc-quote', function () { fillTanks(+this.value); render(); });
    $m.on('change', '#qc-tank', render);
    fillTanks(0); render();

    $m.on('click', '#qc-import', function () {
      const q = quotes[+$m.find('#qc-quote').val()], t = tanks[+$m.find('#qc-tank').val()];
      const fields = {};
      $m.find('.qc-field:checked').each(function () { fields[$(this).data('key')] = $(this).attr('data-val'); });
      const price = $m.find('#qc-price:checked').attr('data-val');
      if (!Object.keys(fields).length && !price) { $m.find('#qc-msg').text(T('Select at least one line to import.')).show(); return; }
      const $b = $(this).prop('disabled', true).text(T('Saving…'));
      $.post(ajaxurl, { action: 'ispag_achat_quote_import', nonce: (window.ispagVars || {}).quote_nonce, purchase_id: purchaseId, deal_id: dealId || 0, line_id: t.line_id, fields: fields, net_price: price || '' }, function (resp) {
        if (resp && resp.success) {
          close();
          $('#articles').removeData('loaded').removeAttr('data-loaded');
          $(document).trigger('ispag:achat-reload-articles');
        } else {
          $m.find('#qc-msg').text((resp && resp.data && resp.data.message) || T('Error')).show();
          $b.prop('disabled', false).text(T('Import the selection'));
        }
      }).fail(function () { $m.find('#qc-msg').text(T('Network error.')).show(); $b.prop('disabled', false).text(T('Import the selection')); });
    });
  }

  /** Point d'entrée (dropzone.js) : envoie le résultat de Mistral à la comparaison puis ouvre la fenêtre. */
  window.ispagQuoteCompare = function (mistralResult, purchaseId, dealId, done) {
    $.post(ajaxurl, { action: 'ispag_achat_quote_compare', nonce: (window.ispagVars || {}).quote_nonce, purchase_id: purchaseId, result: typeof mistralResult === 'string' ? mistralResult : JSON.stringify(mistralResult) }, function (resp) {
      if (typeof done === 'function') done();
      if (resp && resp.success) open(resp.data, purchaseId, dealId);
      else alert((resp && resp.data && resp.data.message) || T('Error'));
    }).fail(function () { if (typeof done === 'function') done(); alert(T('Network error.')); });
  };
})(jQuery);
