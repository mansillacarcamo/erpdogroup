// === Formato CLP en inputs de monto (puntos de miles) ===
function formatCLP(num) {
  if (num === '' || num === null || isNaN(num)) return '';
  // Agrega puntos cada 3 dígitos desde la derecha
  return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function aplicarFormatoCLP(input) {
  // Guarda posición del cursor
  const pos = input.selectionStart || 0;
  const largoAntes = input.value.length;

  let v = input.value.replace(/\D/g, '');
  if (!v) { input.value = ''; return; }
  // Evita ceros a la izquierda
  v = String(parseInt(v, 10));
  input.value = formatCLP(v);

  // Restaura cursor aproximado
  const largoDespues = input.value.length;
  const nuevaPos = pos + (largoDespues - largoAntes);
  try { input.setSelectionRange(nuevaPos, nuevaPos); } catch(e){}
}

document.addEventListener('input', e => {
  if (e.target.classList && e.target.classList.contains('input-clp')) {
    aplicarFormatoCLP(e.target);
  }
});

// Formatea inputs CLP que ya tengan valor al cargar la pagina
function formatearTodosCLP() {
  document.querySelectorAll('.input-clp').forEach(inp => {
    if (inp.value) aplicarFormatoCLP(inp);
  });
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', formatearTodosCLP);
} else {
  formatearTodosCLP();
}

// === Preview de imagen al subir ===
document.addEventListener('change', e => {
  if (e.target.matches('input[type="file"][data-preview]')) {
    const target = document.querySelector(e.target.dataset.preview);
    if (!target) return;
    target.innerHTML = '';
    [...e.target.files].forEach(f => {
      if (!f.type.startsWith('image/')) {
        target.insertAdjacentHTML('beforeend',
          `<div class="badge bg-secondary me-1 mb-1"><i class="bi bi-file-earmark"></i> ${f.name}</div>`);
        return;
      }
      const url = URL.createObjectURL(f);
      target.insertAdjacentHTML('beforeend',
        `<img src="${url}" class="thumb me-1 mb-1">`);
    });
  }
});

// === Confirmaciones ===
document.addEventListener('click', e => {
  const t = e.target.closest('[data-confirm]');
  if (t && !confirm(t.dataset.confirm)) e.preventDefault();
});

// === Normalizacion de nombre de usuario en vivo ===
document.addEventListener('input', e => {
  if (e.target.classList && e.target.classList.contains('input-user')) {
    const pos = e.target.selectionStart || 0;
    const val = e.target.value.toLowerCase().replace(/[^a-z0-9._]/g, '');
    if (val !== e.target.value) {
      e.target.value = val;
      try { e.target.setSelectionRange(pos, pos); } catch(ex){}
    }
  }
});
