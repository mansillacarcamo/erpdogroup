function iniciarSelectorCliente(selectId, campos, apiUrl) {
  var api = apiUrl || 'api_clientes.php';
  var select = document.getElementById(selectId);
  if (!select) return;

  fetch(api + '?action=listar')
    .then(function(r) { return r.json(); })
    .then(function(items) {
      items.forEach(function(c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.nombre + (c.rut ? ' — ' + c.rut : '');
        select.appendChild(opt);
      });
    });

  select.addEventListener('change', function() {
    var id = this.value;
    if (!id) {
      Object.keys(campos).forEach(function(k) {
        var el = document.getElementById(campos[k]);
        if (el) el.value = '';
      });
      return;
    }
    fetch(api + '?action=obtener&id=' + id)
      .then(function(r) { return r.json(); })
      .then(function(c) {
        if (!c || !c.id) return;
        var map = {
          nombre: c.nombre || '',
          rut: c.rut || '',
          direccion: c.direccion || '',
          ciudad: c.ciudad || '',
          telefono: c.telefono || '',
          email: c.email || '',
          contacto: c.contacto || ''
        };
        Object.keys(campos).forEach(function(k) {
          var el = document.getElementById(campos[k]);
          if (el && map[k] !== undefined) el.value = map[k];
        });
      });
  });
}
