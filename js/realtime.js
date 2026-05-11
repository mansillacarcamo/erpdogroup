/**
 * realtime.js — Actualización en tiempo real para tickets de despacho
 * Funciona para: conductor, Carchek y sistema interno
 * Usa polling cada 8s (compatible con cualquier hosting sin SSE persistente)
 */
(function () {
    'use strict';

    var INTERVALO  = 8000;   // 8 segundos
    var lastHash   = '';
    var lastId     = 0;
    var timer      = null;
    var callbacks  = {};

    /* ── API pública ── */
    window.RealtimeTickets = {

        /**
         * Iniciar polling
         * @param {Object} opts  { onUpdate, onNuevoTicket, onValidado, modo }
         */
        init: function (opts) {
            callbacks = opts || {};
            lastHash  = '';
            lastId    = 0;
            this.poll();
            timer = setInterval(function () {
                window.RealtimeTickets.poll();
            }, INTERVALO);
        },

        poll: function () {
            fetch('api_sse_tickets.php?last_id=' + lastId + '&hash=' + encodeURIComponent(lastHash), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (env) {
                // El endpoint SSE retorna directamente el JSON del evento
                if (!env || !env.hash) return;
                if (env.hash === lastHash) return; // sin cambios

                var esNuevo = env.max_id > lastId;
                var cambios = env.cambios || [];

                // Actualizar badges y contadores en UI
                actualizarUI(env);

                // Callbacks
                if (typeof callbacks.onUpdate === 'function') {
                    callbacks.onUpdate(env);
                }
                if (esNuevo && typeof callbacks.onNuevoTicket === 'function') {
                    callbacks.onNuevoTicket(env);
                }
                if (cambios.length > 0 && env.validados > 0 && typeof callbacks.onValidado === 'function') {
                    callbacks.onValidado(env, cambios);
                }

                lastHash = env.hash;
                if (env.max_id > lastId) lastId = env.max_id;
            })
            .catch(function () { /* silencio en errores de red */ });
        },

        stop: function () {
            if (timer) { clearInterval(timer); timer = null; }
        }
    };

    /* ── Actualizar elementos UI automáticamente ── */
    function actualizarUI(data) {

        // Badges globales
        set('rt-validados',  data.validados);
        set('rt-enviados',   data.enviados);
        set('rt-borradores', data.borradores);
        set('rt-total',      data.total);
        set('rt-hora',       data.ts);

        // Badge de pendientes en la campana de notificaciones Carchek
        var badgePend = document.getElementById('badge-pendientes');
        if (badgePend && data.enviados !== undefined) {
            badgePend.textContent = data.enviados;
            badgePend.style.display = data.enviados > 0 ? 'flex' : 'none';
        }

        // Badge del despacho en el sidebar del sistema interno
        var badgeDesp = document.getElementById('despacho-badge');
        if (badgeDesp && data.enviados !== undefined) {
            badgeDesp.textContent = data.enviados;
            badgeDesp.style.display = data.enviados > 0 ? 'inline-flex' : 'none';
        }

        // Conductor: actualizar tarjetas de tickets si están en la página
        if (data.mis_tickets && data.mis_tickets.length > 0) {
            actualizarTarjetasConductor(data.mis_tickets);
        }

        // Carchek: actualizar lista de pendientes si está visible
        if (data.pendientes) {
            actualizarPendientesCarchek(data.pendientes);
        }

        // Dot de conexión activa
        var dots = document.querySelectorAll('.rt-dot');
        dots.forEach(function (d) { d.style.background = '#22c55e'; });
    }

    /* ── Conductor: actualizar badges de estado en sus tarjetas ── */
    function actualizarTarjetasConductor(misTickets) {
        misTickets.forEach(function (t) {
            var card = document.querySelector('[data-ticket-id="' + t.id + '"]');
            if (!card) return;

            // Actualizar clase de estado
            card.classList.remove('borrador', 'enviado', 'validado');
            card.classList.add(t.estado);

            // Actualizar badge
            var badge = card.querySelector('.ticket-badge');
            if (badge) {
                var textos = {
                    'borrador': '<i class="bi bi-pencil"></i> Borrador',
                    'enviado':  '<i class="bi bi-hourglass-split"></i> Enviado',
                    'validado': '<i class="bi bi-check-circle-fill"></i> Validado'
                };
                var clases = {
                    'borrador': 'badge-borrador',
                    'enviado':  'badge-enviado',
                    'validado': 'badge-validado'
                };
                badge.className = 'ticket-badge ' + (clases[t.estado] || '');
                badge.innerHTML = textos[t.estado] || t.estado;
            }

            // Si fue validado: ocultar botones de acción y mostrar mensaje
            if (t.estado === 'validado') {
                var actions = card.querySelector('.ticket-actions');
                if (actions) {
                    actions.innerHTML = '<div style="margin-top:10px;font-size:.78rem;color:#16a34a;display:flex;align-items:center;gap:5px"><i class="bi bi-check-circle-fill"></i> Validado por ' + (t.validado_por_nombre || 'Carchek') + '</div>';
                }
                // Actualizar borde
                card.style.borderLeftColor = '#16a34a';
                // Toast al conductor
                mostrarToastRT('✅ Ticket validado', 'PPU ' + (t.ppu || '') + ' fue validado por Carchek');
            }
        });
    }

    /* ── Carchek: actualizar lista de pendientes ── */
    function actualizarPendientesCarchek(pendientes) {
        var lista = document.getElementById('lista-pendientes');
        if (!lista) return;

        var idsActuales = new Set(pendientes.map(function (p) { return p.id; }));

        // Quitar los que ya fueron validados
        document.querySelectorAll('.pend-item').forEach(function (item) {
            var token = item.dataset.token;
            var yaValidado = !pendientes.find(function (p) { return p.token === token; });
            if (yaValidado && !item.classList.contains('procesado')) {
                item.classList.add('procesado');
                item.style.opacity = '0.4';
                item.style.pointerEvents = 'none';
                item.style.textDecoration = 'line-through';
            }
        });

        // Actualizar badge
        var badge = document.getElementById('badge-pendientes');
        if (badge) {
            badge.textContent = pendientes.length;
            badge.style.display = pendientes.length > 0 ? '' : 'none';
        }
    }

    /* ── Toast de notificación ── */
    function mostrarToastRT(titulo, msg) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(20px);background:#1b2838;color:#fff;padding:12px 18px;border-radius:12px;font-size:.85rem;z-index:9999;opacity:0;transition:all .3s;text-align:center;min-width:220px;box-shadow:0 4px 20px rgba(0,0,0,.3)';
        t.innerHTML = '<strong>' + titulo + '</strong><br><small>' + msg + '</small>';
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(0)'; }, 100);
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 4000);
    }

    /* ── Helper ── */
    function set(id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
    }

})();
