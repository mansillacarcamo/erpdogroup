document.addEventListener('DOMContentLoaded', function() {
  var body   = document.getElementById('cotItemsBody');
  var btnAdd = document.getElementById('btnAgregarCotItem');
  var selMoneda = document.querySelector('select[name="moneda"]');
  var inputTC   = document.getElementById('cotTipoCambioInput');
  var wrapTC    = document.getElementById('cotTipoCambioWrap');

  /* ── Config por moneda ─────────────────────────────────── */
  var MONEDA_CONFIG = {
    'CLP': { dec: 0, sim: '$',   miles: '.', dec_sep: ',' },
    'USD': { dec: 2, sim: 'US$', miles: '.', dec_sep: ',' },
    'EUR': { dec: 2, sim: '€',   miles: '.', dec_sep: ',' },
    'UF':  { dec: 4, sim: 'UF ', miles: '.', dec_sep: ',' },
  };

  function getMoneda() { return selMoneda ? (selMoneda.value || 'CLP') : 'CLP'; }
  function getCfg()    { return MONEDA_CONFIG[getMoneda()] || MONEDA_CONFIG['CLP']; }

  /* ── Formateo ──────────────────────────────────────────── */
  function formatMoney(v) {
    var cfg = getCfg();
    var n = Math.round(v * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);
    var neg = n < 0; if (neg) n = -n;
    var fixed = n.toFixed(cfg.dec);
    var parts = fixed.split('.');
    var intPart = parts[0];
    var decPart = parts[1] || '';
    var out = '';
    for (var i = intPart.length - 1, c = 0; i >= 0; i--, c++) {
      if (c > 0 && c % 3 === 0) out = cfg.miles + out;
      out = intPart[i] + out;
    }
    if (decPart) out += cfg.dec_sep + decPart;
    return (neg ? '-' : '') + cfg.sim + out;
  }

  function formatInput(v) {
    var cfg = getCfg();
    var n = Math.round(v * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);
    if (n === 0) return '';
    var neg = n < 0; if (neg) n = -n;
    var fixed = n.toFixed(cfg.dec);
    var parts = fixed.split('.');
    var intPart = parts[0];
    var decPart = parts[1] ? parts[1].replace(/0+$/, '') : '';
    var out = '';
    for (var i = intPart.length - 1, c = 0; i >= 0; i--, c++) {
      if (c > 0 && c % 3 === 0) out = '.' + out;
      out = intPart[i] + out;
    }
    if (decPart) out += ',' + decPart;
    return (neg ? '-' : '') + out;
  }

  function getNumChile(el) {
    if (!el) return 0;
    var str = String(el.value).trim().replace(/[^\d.,\-]/g, '');
    var neg = str.charAt(0) === '-'; if (neg) str = str.slice(1);
    var lc = str.lastIndexOf(',');
    var intRaw, decRaw = '';
    if (lc === -1) { intRaw = str.replace(/\./g, ''); }
    else { intRaw = str.slice(0, lc).replace(/\./g, ''); decRaw = str.slice(lc + 1).replace(/\D/g, ''); }
    intRaw = intRaw.replace(/^0+(?=\d)/, '') || '0';
    var n = decRaw ? parseFloat(intRaw + '.' + decRaw) : parseFloat(intRaw);
    if (neg) n = -n;
    return isNaN(n) ? 0 : n;
  }

  /* ── Recalcular ────────────────────────────────────────── */
  function recalcular() {
    var cfg = getCfg();
    var p = Math.pow(10, cfg.dec);
    var subtotal = 0;
    document.querySelectorAll('.cot-item-row').forEach(function(row) {
      var cant   = getNumChile(row.querySelector('.cot-cant'));
      var precio = getNumChile(row.querySelector('.cot-precio'));
      var tot = Math.round(cant * precio * p) / p;
      subtotal += tot;
      row.querySelector('.cot-total').textContent = formatMoney(tot);
    });
    subtotal = Math.round(subtotal * p) / p;

    var gastosPct   = parseFloat(document.getElementById('gastosInput')?.value || 8)  || 0;
    var utilidadPct = parseFloat(document.getElementById('utilInput')?.value   || 10) || 0;
    var gastosMonto   = Math.round(subtotal * (gastosPct   / 100) * p) / p;
    var utilidadMonto = Math.round(subtotal * (utilidadPct / 100) * p) / p;
    var totalNeto     = Math.round((subtotal + gastosMonto + utilidadMonto) * p) / p;
    var iva           = Math.round(totalNeto * 0.19 * p) / p;
    var total         = Math.round((totalNeto + iva) * p) / p;

    document.getElementById('cotSubtotal').textContent   = formatMoney(subtotal);
    if (document.getElementById('cotGastos'))    document.getElementById('cotGastos').textContent    = formatMoney(gastosMonto);
    if (document.getElementById('cotUtilidad'))  document.getElementById('cotUtilidad').textContent  = formatMoney(utilidadMonto);
    if (document.getElementById('cotTotalNeto')) document.getElementById('cotTotalNeto').textContent = formatMoney(totalNeto);
    document.getElementById('cotIva').textContent        = formatMoney(iva);
    document.getElementById('cotTotalFinal').textContent = formatMoney(total);
  }

  /* ── Máscara ───────────────────────────────────────────── */
  function attachMaskChile(el) {
    if (!el) return;
    el.addEventListener('input', function() {
      var raw = el.value.replace(/[^\d.,\-]/g, '');
      var neg = raw.charAt(0) === '-'; if (neg) raw = raw.slice(1);
      var commaIdx = raw.lastIndexOf(',');
      var intRaw, decRaw = '';
      if (commaIdx === -1) { intRaw = raw.replace(/\./g, ''); }
      else { intRaw = raw.slice(0, commaIdx).replace(/\./g, ''); decRaw = raw.slice(commaIdx + 1).replace(/\D/g, '').slice(0, 4); }
      intRaw = intRaw.replace(/^0+(?=\d)/, '') || '';
      if (!intRaw && !decRaw) { el.value = neg ? '-' : ''; return; }
      if (!intRaw) intRaw = '0';
      var out = '';
      for (var i = intRaw.length - 1, c = 0; i >= 0; i--, c++) {
        if (c > 0 && c % 3 === 0) out = '.' + out;
        out = intRaw[i] + out;
      }
      if (commaIdx !== -1) out += ',' + decRaw;
      el.value = (neg ? '-' : '') + out;
      try { el.setSelectionRange(el.value.length, el.value.length); } catch(e) {}
    });
    el.addEventListener('blur', function() {
      var n = getNumChile(el);
      el.value = n === 0 ? '0' : formatInput(n);
    });
    el.addEventListener('focus', function() {
      if (getNumChile(el) === 0) el.value = '';
    });
    if (el.value !== '') el.value = formatInput(getNumChile(el));
  }

  /* ── Bind fila ─────────────────────────────────────────── */
  function bindRow(row) {
    row.querySelector('.cot-cant').addEventListener('input', recalcular);
    row.querySelector('.cot-precio').addEventListener('input', recalcular);
    attachMaskChile(row.querySelector('.cot-cant'));
    attachMaskChile(row.querySelector('.cot-precio'));
    row.querySelector('.btn-remove-cot').addEventListener('click', function() {
      if (document.querySelectorAll('.cot-item-row').length > 1) { row.remove(); recalcular(); }
    });
  }

  document.querySelectorAll('.cot-item-row').forEach(bindRow);

  /* ── Indicadores del día ───────────────────────────────── */
  var _indicadores = null;
  function cargarIndicadores(cb) {
    if (_indicadores) { cb(_indicadores); return; }
    fetch('api_indicadores.php')
      .then(function(r){ return r.json(); })
      .then(function(d){ _indicadores = d; cb(d); })
      .catch(function(){ cb(null); });
  }

  /* ── Cambio de moneda ──────────────────────────────────── */
  function actualizarMoneda() {
    var m = getMoneda();
    if (wrapTC) wrapTC.style.display = m === 'CLP' ? 'none' : '';

    if (m !== 'CLP' && inputTC) {
      cargarIndicadores(function(ind) {
        if (!ind) return;
        var mapa = { 'USD': ind.dolar, 'EUR': ind.euro, 'UF': ind.uf };
        var entry = mapa[m];
        if (entry && entry.valor > 0) {
          var fmt = entry.valor.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          inputTC.value = fmt;
          var ref = document.getElementById('cotTcRefFecha');
          if (ref && entry.fecha) {
            var d = new Date(entry.fecha);
            ref.textContent = 'Valor al ' + d.toLocaleDateString('es-CL', { day:'2-digit', month:'2-digit', year:'numeric' });
          }
        }
      });
    }

    document.querySelectorAll('.cot-precio, .cot-cant').forEach(function(el) {
      var n = getNumChile(el);
      el.value = n === 0 ? '0' : formatInput(n);
    });
    recalcular();
  }

  if (selMoneda) selMoneda.addEventListener('change', actualizarMoneda);
  if (inputTC)   inputTC.addEventListener('input', recalcular);
  var gastosInp = document.getElementById('gastosInput');
  var utilInp   = document.getElementById('utilInput');
  if (gastosInp) gastosInp.addEventListener('input', recalcular);
  if (utilInp)   utilInp.addEventListener('input', recalcular);

  actualizarMoneda();

  /* ── Agregar fila ──────────────────────────────────────── */
  if (btnAdd) {
    btnAdd.addEventListener('click', function(ev) {
      ev.preventDefault();
      var tr = document.createElement('tr');
      tr.className = 'cot-item-row';
      tr.innerHTML =
        '<td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm cot-cant" value="1" autofocus></td>' +
        '<td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px"><option value="UN" selected>UN</option><option value="M2">M2</option><option value="M3">M3</option><option value="GL">GL</option><option value="UNIT">UNIT</option><option value="HRS">HRS</option><option value="DÍA">DÍA</option><option value="MES">MES</option></select></td>' +
        '<td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descripción del producto o servicio" required></td>' +
        '<td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm cot-precio money-input" value="0"></td>' +
        '<td><span class="cot-total fw-bold">$0</span></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-cot"><i class="bi bi-trash"></i></button></td>';
      body.appendChild(tr);
      bindRow(tr);
      var ic = tr.querySelector('input[name="item_cant[]"]');
      if (ic) {
        ic.focus(); ic.select();
        requestAnimationFrame(function(){
          requestAnimationFrame(function(){
            try { ic.focus(); ic.select(); } catch(e){}
          });
        });
      }
    });
  }

  recalcular();
});
