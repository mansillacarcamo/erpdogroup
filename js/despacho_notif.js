/**
 * Notificaciones en tiempo real - Módulo Despacho
 * Cubre: receptor interno, admin_despacho, conductor, externo (Carchek)
 */
(function () {
  const API = 'api_notif_despacho_interno.php';
  const INTERVALO = 15000; // 15 segundos

  let ultimoTotal = -1;

  function init() {
    // Crear badge flotante en el título de la sección despacho si no existe
    const btnDesp = document.getElementById('despacho-notif-btn');
    if (btnDesp) {
      btnDesp.addEventListener('click', function (e) {
        e.stopPropagation();
        const panel = document.getElementById('despacho-notif-panel');
        if (!panel) return;
        const visible = panel.style.display === 'block';
        panel.style.display = visible ? 'none' : 'block';
        if (!visible) cargarPanel();
      });
      document.addEventListener('click', function (e) {
        const panel = document.getElementById('despacho-notif-panel');
        const btn   = document.getElementById('despacho-notif-btn');
        if (panel && panel.style.display === 'block' && !panel.contains(e.target) && e.target !== btn) {
          panel.style.display = 'none';
        }
      });
    }

    actualizar();
    setInterval(actualizar, INTERVALO);
  }

  function actualizar() {
    fetch(API, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        const total = data.total || 0;

        // Badge en sidebar (si existe el enlace de despacho)
        const badge = document.getElementById('despacho-badge');
        if (badge) {
          badge.textContent = total;
          badge.style.display = total > 0 ? 'inline-flex' : 'none';
        }

        // Badge en botón campana del módulo
        const btnBadge = document.getElementById('despacho-notif-count');
        if (btnBadge) {
          btnBadge.textContent = total;
          btnBadge.style.display = total > 0 ? 'inline-flex' : 'none';
        }

        // Toast si hay nuevas
        if (total > ultimoTotal && ultimoTotal >= 0 && total > 0) {
          const modo = data.modo || 'interno';
          let msg = '';
          if (modo === 'interno' || modo === 'admin') {
            msg = `${data.tickets_pendientes || total} ticket(s) de despacho esperando validación`;
          } else if (modo === 'externo') {
            msg = `${total} ticket(s) nuevos para revisar`;
          } else if (modo === 'conductor') {
            msg = `${total} ticket(s) validados hoy`;
          }
          if (msg) mostrarToastDespacho('🚛 Despacho', msg);
        }
        ultimoTotal = total;

        // Actualizar tabla si estamos en la página principal de tickets
        if (typeof actualizarTablaTickets === 'function') {
          actualizarTablaTickets(data);
        }
      })
      .catch(() => {});
  }

  function cargarPanel() {
    const lista = document.getElementById('despacho-notif-lista');
    if (!lista) return;
    lista.innerHTML = '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:13px">Cargando...</div>';

    fetch(API, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data.items || data.items.length === 0) {
          lista.innerHTML = '<div style="padding:24px;text-align:center;color:#9ca3af;font-size:13px"><i class="bi bi-truck" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>Sin tickets pendientes</div>';
          return;
        }
        lista.innerHTML = data.items.map(item => {
          const esPend   = item.tipo_notif === 'ticket_pendiente';
          const esRevis  = item.tipo_notif === 'ticket_revisado';
          const colorBg  = esPend ? '#fef3c7' : (esRevis ? '#d1fae5' : '#f3f4f6');
          const colorTxt = esPend ? '#92400e' : (esRevis ? '#065f46' : '#374151');
          const icono    = esPend ? 'bi-hourglass-split' : (esRevis ? 'bi-check-circle-fill' : 'bi-truck');
          const label    = esPend ? 'Pendiente' : (esRevis ? 'Validado' : item.estado || '');
          return `
            <div onclick="window.location.href='tickets_despacho_ver.php?id=${item.id}'"
                 style="display:flex;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(0,0,0,.06);cursor:pointer;transition:background .15s"
                 onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
              <span style="flex-shrink:0;background:${colorBg};color:${colorTxt};font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;height:fit-content;margin-top:2px">
                <i class="bi ${icono}" style="font-size:10px"></i> ${label}
              </span>
              <div style="flex:1;font-size:13px;line-height:1.4">
                <strong>PPU ${item.ppu || '—'}</strong> · ${item.tipo_material || ''}
                <small style="display:block;color:#6b7280">${item.obra_nombre || ''} ${item.conductor_nombre ? '· ' + item.conductor_nombre : ''}</small>
              </div>
              <small style="flex-shrink:0;color:#9ca3af;font-size:11px">${formatHora(item.enviado_en || item.validado_en || item.fecha)}</small>
            </div>`;
        }).join('');
      });
  }

  function formatHora(f) {
    if (!f) return '';
    const d = new Date(f);
    const diff = Math.floor((Date.now() - d) / 60000);
    if (isNaN(diff)) return f;
    if (diff < 1) return 'Ahora';
    if (diff < 60) return `hace ${diff} min`;
    if (diff < 1440) return `hace ${Math.floor(diff / 60)}h`;
    return d.toLocaleDateString('es-CL');
  }

  function mostrarToastDespacho(titulo, mensaje) {
    const t = document.createElement('div');
    t.className = 'notif-toast';
    t.innerHTML = `<strong>${titulo}</strong><br><small>${mensaje}</small>`;
    document.body.appendChild(t);
    setTimeout(() => t.classList.add('show'), 100);
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 5000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
