document.addEventListener('DOMContentLoaded', function() {
  var body   = document.getElementById('itemsBody');
  var btnAdd = document.getElementById('btnAgregarItem');
  var selMoneda   = document.querySelector('select[name="moneda"]');
  var inputTC     = document.getElementById('tipoCambioInput');
  var wrapTC      = document.getElementById('tipoCambioWrap');
  var labelSimbolo = document.getElementById('labelSimbolo');

  /* ── Config por moneda ─────────────────────────────────── */
  var MONEDA_CONFIG = {
    'CLP': { dec: 0, sim: '$',   miles: '.', dec_sep: ',' },
    'USD': { dec: 2, sim: 'US$', miles: '.', dec_sep: ',' },
    'EUR': { dec: 2, sim: '€',   miles: '.', dec_sep: ',' },
    'UF':  { dec: 4, sim: 'UF ', miles: '.', dec_sep: ',' },
  };

  function getMoneda() {
    return selMoneda ? (selMoneda.value || 'CLP') : 'CLP';
  }
  function getCfg() {
    return MONEDA_CONFIG[getMoneda()] || MONEDA_CONFIG['CLP'];
  }
  function getTipoCambio() {
    if (!inputTC) return 1;
    var v = parseFloat(String(inputTC.value).replace(',', '.')) || 1;
    return v > 0 ? v : 1;
  }

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

  function getNum(el) {
    if (!el) return 0;
    var v = String(el.value).replace(/\./g, '').replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  /* ── Recalcular totales ────────────────────────────────── */
  function recalcular() {
    var cfg = getCfg();
    var neto = 0;
    document.querySelectorAll('.item-row').forEach(function(row) {
      var cant   = getNum(row.querySelector('.item-cant'));
      var precio = getNum(row.querySelector('.item-precio'));
      var dcto   = getNum(row.querySelector('.item-dcto'));
      var sub    = cant * precio;
      if (dcto > 0) sub = sub - (sub * dcto / 100);
      // Redondear al decimal de la moneda
      sub = Math.round(sub * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);
      neto += sub;
      row.querySelector('.item-total').textContent = formatMoney(sub);
    });
    neto = Math.round(neto * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);
    var iva   = Math.round(neto * 0.19 * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);
    var total = Math.round((neto + iva) * Math.pow(10, cfg.dec)) / Math.pow(10, cfg.dec);

    document.getElementById('neto').textContent       = formatMoney(neto);
    document.getElementById('iva').textContent        = formatMoney(iva);
    document.getElementById('totalFinal').textContent = formatMoney(total);

    // Actualizar etiqueta de símbolo de moneda si existe
    if (labelSimbolo) labelSimbolo.textContent = selMoneda ? selMoneda.value : 'CLP';
  }

  /* ── Máscara de inputs ─────────────────────────────────── */
  function attachMoneyMask(el) {
    if (!el) return;
    el.addEventListener('blur', function() {
      var n = getNum(el);
      el.value = n === 0 ? '0' : formatInput(n);
    });
    el.addEventListener('focus', function() {
      var n = getNum(el);
      if (n === 0) el.value = '';
    });
    if (el.value !== '' && el.value !== '0') {
      el.value = formatInput(getNum(el));
    }
  }

  /* ── Bind fila ─────────────────────────────────────────── */
  function bindRow(row) {
    row.querySelector('.item-cant').addEventListener('input', recalcular);
    row.querySelector('.item-precio').addEventListener('input', recalcular);
    row.querySelector('.item-dcto').addEventListener('input', recalcular);
    attachMoneyMask(row.querySelector('.item-precio'));
    row.querySelector('.btn-remove-item').addEventListener('click', function() {
      if (document.querySelectorAll('.item-row').length > 1) {
        row.remove();
        recalcular();
      }
    });
  }

  document.querySelectorAll('.item-row').forEach(bindRow);

  /* ── Indicadores del día (caché en memoria) ────────────── */
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
    var esCLP = m === 'CLP';
    if (wrapTC) wrapTC.style.display = esCLP ? 'none' : '';

    // Auto-rellenar tipo de cambio con el valor del día
    if (!esCLP && inputTC) {
      cargarIndicadores(function(ind) {
        if (!ind) return;
        var mapa = { 'USD': ind.dolar, 'EUR': ind.euro, 'UF': ind.uf };
        var entry = mapa[m];
        if (entry && entry.valor > 0) {
          var fmt = entry.valor.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          inputTC.value = fmt;
          // Mostrar referencia de fecha
          var ref = document.getElementById('tcRefFecha');
          if (ref && entry.fecha) {
            var d = new Date(entry.fecha);
            ref.textContent = 'Valor al ' + d.toLocaleDateString('es-CL', { day:'2-digit', month:'2-digit', year:'numeric' });
          }
        }
      });
    }

    document.querySelectorAll('.item-precio, .item-cant').forEach(function(el) {
      var n = getNum(el);
      el.value = n === 0 ? '0' : formatInput(n);
    });
    recalcular();
  }

  if (selMoneda) selMoneda.addEventListener('change', actualizarMoneda);
  if (inputTC)   inputTC.addEventListener('input', recalcular);

  actualizarMoneda();

  /* ── Agregar fila ──────────────────────────────────────── */
  if (btnAdd) {
    btnAdd.addEventListener('click', function(ev) {
      ev.preventDefault();
      var tr = document.createElement('tr');
      tr.className = 'item-row';
      tr.innerHTML =
        '<td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm item-cant" value="1" autofocus></td>' +
        '<td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px"><option value="UN" selected>UN</option><option value="M2">M2</option><option value="M3">M3</option><option value="GL">GL</option><option value="UNIT">UNIT</option><option value="HRS">HRS</option><option value="DÍA">DÍA</option><option value="MES">MES</option></select></td>' +
        '<td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descripción del producto o servicio" required></td>' +
        '<td><input type="text" inputmode="decimal" name="item_dcto[]" class="form-control form-control-sm item-dcto" value="0"></td>' +
        '<td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm item-precio money-input" value="0"></td>' +
        '<td><span class="item-total fw-bold">$0</span></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-item"><i class="bi bi-trash"></i></button></td>';
      body.appendChild(tr);
      bindRow(tr);
      var ic = tr.querySelector('input[name="item_cant[]"]');
      if (!ic) return;

      var guardActivo = true;
      var guard = function(e){
        if (!guardActivo) return;
        if (e.target !== ic) {
          try { ic.focus(); ic.select(); } catch(err){}
        }
      };
      document.addEventListener('focusin', guard, true);
      setTimeout(function(){
        guardActivo = false;
        document.removeEventListener('focusin', guard, true);
      }, 250);

      ic.focus(); ic.select();
      requestAnimationFrame(function(){
        try { ic.focus(); ic.select(); } catch(e){}
        requestAnimationFrame(function(){
          try { ic.focus(); ic.select(); } catch(e){}
        });
      });
    });
  }

  recalcular();
});
