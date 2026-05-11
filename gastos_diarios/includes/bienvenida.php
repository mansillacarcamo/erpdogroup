<?php
// ============================================================
//  Modal de bienvenida tras login
//  Aparece 1 vez por sesion: cuando $_SESSION['bienvenida_pendiente']=true
// ============================================================
if (empty($_SESSION['bienvenida_pendiente'])) return;
unset($_SESSION['bienvenida_pendiente']);

$nombreUsr = trim($_SESSION['user_nombre'] ?? '') ?: ($_SESSION['user_usuario'] ?? 'Usuario');
$primerNombre = explode(' ', $nombreUsr)[0];

$hora = (int)date('G');
if     ($hora >= 5  && $hora < 12) { $saludo = 'Buenos días';   $ico = 'bi-sunrise'; }
elseif ($hora >= 12 && $hora < 19) { $saludo = 'Buenas tardes'; $ico = 'bi-sun'; }
else                                { $saludo = 'Buenas noches'; $ico = 'bi-moon-stars'; }

$frases = [
    'El éxito es la suma de pequeños esfuerzos repetidos día tras día.',
    'No cuentes los días, haz que los días cuenten.',
    'La disciplina es el puente entre las metas y los logros.',
    'Cada día es una nueva oportunidad para crecer y mejorar.',
    'El trabajo en equipo divide las tareas y multiplica los resultados.',
    'Los grandes logros nacen de pequeños pasos constantes.',
    'Tu actitud determina tu altitud.',
    'La excelencia no es un acto, sino un hábito.',
    'Hoy es el mejor día para dar lo mejor de ti.',
    'El compromiso transforma una promesa en realidad.',
    'Lo que haces hoy puede mejorar todos tus mañanas.',
    'La constancia vence lo que la dicha no alcanza.',
    'Las grandes obras se construyen un ladrillo a la vez.',
    'La calidad no es un accidente, es siempre el resultado del esfuerzo inteligente.',
    'Quien hace bien su trabajo, abre puertas que no se ven.',
];
// Frase determinista del dia (cambia c/dia, no aleatoria por carga)
$idx = (int)date('z') % count($frases);
$frase = $frases[$idx];
?>

<!-- Modal de bienvenida -->
<div class="modal fade" id="modalBienvenida" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content welcome-card">
      <button type="button" class="welcome-close" data-bs-dismiss="modal" aria-label="Cerrar">
        <i class="bi bi-x-lg"></i>
      </button>
      <div class="welcome-head">
        <div class="welcome-ico"><i class="bi <?= h($ico) ?>"></i></div>
        <div class="welcome-greeting">
          <div class="welcome-salute"><?= h($saludo) ?>,</div>
          <div class="welcome-name"><?= h($primerNombre) ?></div>
        </div>
      </div>
      <div class="welcome-divider"></div>
      <div class="welcome-quote">
        <i class="bi bi-quote welcome-q-icon"></i>
        <p class="welcome-frase"><?= h($frase) ?></p>
      </div>
      <div class="welcome-foot">
        <div class="welcome-sign-line">Atentamente,</div>
        <div class="welcome-brand">
          <img src="img/logo.png" alt="DO Group" onerror="this.style.display='none'">
          <span>DO Group</span>
        </div>
      </div>
      <div class="welcome-cta">
        <button class="btn btn-welcome" data-bs-dismiss="modal">
          <i class="bi bi-arrow-right-circle me-1"></i>Comenzar mi día
        </button>
      </div>
    </div>
  </div>
</div>

<style>
#modalBienvenida .modal-dialog{ max-width: 460px; }
#modalBienvenida .welcome-card{
  position:relative; overflow:hidden;
  background: linear-gradient(160deg,#ffffff 0%, #fff7ed 55%, #ffedd5 100%);
  border:0; border-radius:18px;
  box-shadow: 0 24px 60px rgba(217,119,6,.25), 0 8px 20px rgba(0,0,0,.10);
  padding: 28px 26px 22px;
}
#modalBienvenida .welcome-card::before{
  content:''; position:absolute; top:-60px; right:-60px;
  width:200px; height:200px; border-radius:50%;
  background: radial-gradient(circle, rgba(255,140,66,.20) 0%, transparent 70%);
  pointer-events:none;
}
#modalBienvenida .welcome-card::after{
  content:''; position:absolute; bottom:-80px; left:-80px;
  width:240px; height:240px; border-radius:50%;
  background: radial-gradient(circle, rgba(217,119,6,.14) 0%, transparent 70%);
  pointer-events:none;
}
#modalBienvenida .welcome-close{
  position:absolute; top:12px; right:14px;
  width:32px; height:32px; border-radius:50%;
  border:0; background: rgba(0,0,0,.05); color:#475569;
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; transition:all .2s;
  z-index:2;
}
#modalBienvenida .welcome-close:hover{ background: rgba(0,0,0,.10); color:#0f172a; transform: rotate(90deg); }

#modalBienvenida .welcome-head{
  display:flex; align-items:center; gap:16px;
  position:relative; z-index:1;
}
#modalBienvenida .welcome-ico{
  width:62px; height:62px; border-radius:16px;
  display:inline-flex; align-items:center; justify-content:center;
  background: linear-gradient(135deg,#ff8c42 0%,#d97706 100%);
  color:#fff; font-size:1.7rem;
  box-shadow: 0 8px 22px rgba(217,119,6,.40);
  flex-shrink:0;
}
#modalBienvenida .welcome-greeting{ line-height:1.15; }
#modalBienvenida .welcome-salute{
  font-size:.95rem; color:#78350f; font-weight:600; letter-spacing:.2px;
}
#modalBienvenida .welcome-name{
  font-size:1.6rem; color:#0f172a; font-weight:800;
  margin-top:2px; word-break: break-word;
}
#modalBienvenida .welcome-divider{
  height:1px; margin: 20px 0 16px;
  background: linear-gradient(90deg, transparent, rgba(217,119,6,.30), transparent);
  position:relative; z-index:1;
}
#modalBienvenida .welcome-quote{
  position:relative; padding: 4px 6px 0 36px; z-index:1;
}
#modalBienvenida .welcome-q-icon{
  position:absolute; top:-6px; left:0;
  font-size:2.2rem; color:#d97706; opacity:.55; line-height:1;
}
#modalBienvenida .welcome-frase{
  font-size:1.02rem; line-height:1.45; color:#1f2937;
  font-weight:500; font-style:italic; margin:0;
}
#modalBienvenida .welcome-foot{
  margin-top:22px; position:relative; z-index:1;
}
#modalBienvenida .welcome-sign-line{
  font-size:.82rem; color:#64748b; font-style:italic;
}
#modalBienvenida .welcome-brand{
  display:inline-flex; align-items:center; gap:10px;
  margin-top:6px;
  font-size:1.15rem; font-weight:800; color:#0f172a; letter-spacing:.5px;
}
#modalBienvenida .welcome-brand img{
  width:36px; height:36px; object-fit:contain; border-radius:6px;
}
#modalBienvenida .welcome-cta{
  margin-top:18px; text-align:center; position:relative; z-index:1;
}
#modalBienvenida .btn-welcome{
  background: linear-gradient(135deg,#ff8c42 0%,#d97706 100%);
  color:#fff; border:0; padding:11px 26px; border-radius:10px;
  font-weight:700; letter-spacing:.3px;
  box-shadow: 0 8px 18px rgba(217,119,6,.35);
  transition: all .2s;
}
#modalBienvenida .btn-welcome:hover{
  transform: translateY(-2px);
  box-shadow: 0 12px 24px rgba(217,119,6,.45);
  color:#fff;
}

/* Animacion de entrada */
#modalBienvenida.show .welcome-card{
  animation: welcomePop .45s cubic-bezier(.34,1.56,.64,1);
}
@keyframes welcomePop{
  0%   { transform: scale(.85) translateY(20px); opacity:0; }
  100% { transform: scale(1) translateY(0); opacity:1; }
}
</style>

<script>
(function(){
  var el = document.getElementById('modalBienvenida');
  if (!el || typeof bootstrap === 'undefined') return;
  // Esperar a que Bootstrap este disponible y abrir el modal
  function abrir(){
    try { new bootstrap.Modal(el, {backdrop:'static', keyboard:true}).show(); }
    catch(e) { setTimeout(abrir, 200); }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', abrir);
  } else {
    setTimeout(abrir, 100);
  }
})();
</script>
