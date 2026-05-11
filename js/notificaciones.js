// Sistema de notificaciones en tiempo real - DOGroup
(function() {
  let intervalo = null;
  let ultimoTotal = -1;
  let panelVisible = false;

  function init() {
    const btn = document.getElementById('notif-btn');
    const panel = document.getElementById('notif-panel');
    if (!btn) return;

    // Botón toggle panel
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      panelVisible = !panelVisible;
      panel.style.display = panelVisible ? 'block' : 'none';
      if (panelVisible) {
        cargarDetalle();
        marcarLeidas();
      }
    });

    // Cerrar al hacer click fuera
    document.addEventListener('click', function(e) {
      if (panelVisible && !panel.contains(e.target) && e.target !== btn) {
        panelVisible = false;
        panel.style.display = 'none';
      }
    });

    // Polling cada 15 segundos
    actualizar();
    intervalo = setInterval(actualizar, 15000);
  }

  function actualizar() {
    fetch('api_notificaciones.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        const total = data.total || 0;

        if (badge) {
          badge.textContent = total;
          badge.style.display = total > 0 ? 'flex' : 'none';
        }

        // Sonido/vibración si hay notificaciones nuevas
        if (total > ultimoTotal && ultimoTotal >= 0 && total > 0) {
          mostrarToast('Nueva notificación', `Tienes ${total} pendiente(s)`);
        }
        ultimoTotal = total;
      })
      .catch(() => {});
  }

  function cargarDetalle() {
    const lista = document.getElementById('notif-lista');
    if (!lista) return;
    lista.innerHTML = '<div class="notif-loading">Cargando...</div>';

    fetch('api_notificaciones.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data.items || data.items.length === 0) {
          lista.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash"></i><p>Sin notificaciones pendientes</p></div>';
          return;
        }
        lista.innerHTML = data.items.map(item => `
          <div class="notif-item" onclick="irA('${item.tipo}', ${item.doc_id})">
            <span class="notif-tipo notif-tipo-${item.tipo.toLowerCase()}">${item.tipo}</span>
            <div class="notif-info">
              <strong>N° ${item.ref}</strong>
              <small>${item.msg || 'Pendiente de revisión'}</small>
            </div>
            <small class="notif-fecha">${formatFecha(item.fecha)}</small>
          </div>
        `).join('');

        // Resumen
        const resumen = document.getElementById('notif-resumen');
        if (resumen) {
          resumen.innerHTML = `
            ${data.oc_pendientes > 0 ? `<span class="badge bg-danger">${data.oc_pendientes} OC</span>` : ''}
            ${data.cot_pendientes > 0 ? `<span class="badge bg-primary">${data.cot_pendientes} Cotizaciones</span>` : ''}
            ${data.no_leidas > 0 ? `<span class="badge bg-warning text-dark">${data.no_leidas} sin leer</span>` : ''}
          `;
        }
      });
  }

  function marcarLeidas() {
    fetch('api_marcar_leidas.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'tipo=todas'
    }).then(() => actualizar());
  }

  function irA(tipo, id) {
    if (tipo === 'OC') window.location.href = `ver.php?id=${id}`;
    else window.location.href = `cotizaciones/ver_cotizacion.php?id=${id}`;
  }

  function formatFecha(f) {
    if (!f) return '';
    const d = new Date(f);
    const diff = Math.floor((Date.now() - d) / 60000);
    if (diff < 1) return 'Ahora';
    if (diff < 60) return `hace ${diff} min`;
    if (diff < 1440) return `hace ${Math.floor(diff/60)}h`;
    return d.toLocaleDateString('es-CL');
  }

  function mostrarToast(titulo, mensaje) {
    const toast = document.createElement('div');
    toast.className = 'notif-toast';
    toast.innerHTML = `<strong>${titulo}</strong><br><small>${mensaje}</small>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 100);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 4000);
  }

  // Inicializar cuando el DOM esté listo
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
