<?php
/**
 * Webhook GitHub → Auto-deploy en Benzahosting (cPanel)
 *
 * Flujo:
 *   1. GitHub envía POST a https://gestion.dogroup.cl/tools/webhook_deploy.php
 *   2. Este endpoint valida la firma HMAC con un secret compartido.
 *   3. Si es push a `main`, hace `git pull` en el clon del cPanel
 *      y un rsync hacia public_html/gestion.dogroup.cl/.
 *   4. Resultado loggeado en webhook_deploy.log para auditoría.
 *
 * REQUISITOS:
 *   - Archivo /home/dogroupc/webhook_secret.txt con un secret aleatorio (FUERA de public_html).
 *   - shell_exec() habilitado en PHP (la mayoría de los cPanel lo permiten).
 *   - El repo clonado en /home/dogroupc/repositories/erpdogroup (ya configurado).
 *
 * CONFIGURACIÓN EN GITHUB:
 *   Settings → Webhooks → Add webhook
 *     Payload URL:  https://gestion.dogroup.cl/tools/webhook_deploy.php
 *     Content type: application/json
 *     Secret:       (el mismo que pusiste en webhook_secret.txt)
 *     Events:       Just the push event
 */

// ===== Configuración =====
const REPO_PATH      = '/home/dogroupc/repositories/erpdogroup';
const DEPLOY_PATH    = '/home/dogroupc/public_html/gestion.dogroup.cl';
const SECRET_FILE    = '/home/dogroupc/webhook_secret.txt';
const LOG_FILE       = __DIR__ . '/webhook_deploy.log';
const DEPLOY_BRANCH  = 'refs/heads/main';

// ===== Helpers =====
function logMsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function reply(int $code, string $body): void {
    http_response_code($code);
    echo $body;
    exit;
}

// ===== Verificar secret en disco =====
if (!is_file(SECRET_FILE)) {
    logMsg('ERROR: falta SECRET_FILE en ' . SECRET_FILE);
    reply(500, 'Servidor mal configurado: falta secret');
}
$secret = trim((string)@file_get_contents(SECRET_FILE));
if ($secret === '') {
    logMsg('ERROR: SECRET_FILE está vacío');
    reply(500, 'Servidor mal configurado: secret vacío');
}

// ===== Leer payload y firma =====
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if ($sigHeader === '' || strpos($sigHeader, 'sha256=') !== 0) {
    logMsg('REJECTED: falta X-Hub-Signature-256');
    reply(403, 'Sin firma');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sigHeader)) {
    logMsg('REJECTED: firma inválida desde IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    reply(403, 'Firma inválida');
}

// ===== Filtrar evento =====
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    logMsg('PING recibido OK desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    reply(200, 'pong');
}
if ($event !== 'push') {
    logMsg('Evento ignorado: ' . $event);
    reply(204, '');
}

$data = json_decode($payload, true);
$ref = $data['ref'] ?? '';
if ($ref !== DEPLOY_BRANCH) {
    logMsg('Push a branch ignorado: ' . $ref);
    reply(200, 'Branch ignorado');
}

// ===== Ejecutar deploy =====
if (!function_exists('shell_exec')) {
    logMsg('ERROR: shell_exec deshabilitado en este PHP');
    reply(500, 'shell_exec no disponible');
}

$commitSha = $data['after'] ?? '?';
$pusher    = $data['pusher']['name'] ?? '?';
logMsg("Push a main por {$pusher}. Commit: {$commitSha}. Iniciando deploy…");

// 1) git fetch + reset hard al origin/main (sobrescribe cualquier cambio local del clon)
$cmdPull = 'cd ' . escapeshellarg(REPO_PATH)
    . ' && git fetch --all 2>&1'
    . ' && git reset --hard origin/main 2>&1';
$pullOut = (string)shell_exec($cmdPull);
logMsg("git pull output:\n" . $pullOut);

// 2) rsync del repo al deploy path (mismas exclusiones que .cpanel.yml)
$rsyncCmd = '/usr/bin/rsync -av --delete'
    . ' --exclude=.git/ --exclude=.gitignore --exclude=.cpanel.yml'
    . ' --exclude=ocdogroup.db --exclude=ocdogroup.db-journal'
    . ' --exclude=ocdogroup.db-wal --exclude=ocdogroup.db-shm'
    . ' --exclude=uploads/ --exclude=backups/ --exclude=smtp_config.php'
    . ' --exclude=*.zip --exclude=*.ps1'
    . ' --exclude=response.html --exclude=resp.html'
    . ' --exclude=_test_*.php --exclude=_migrar_*.php'
    . ' --exclude=tools/webhook_deploy.log'
    . ' ' . escapeshellarg(REPO_PATH . '/')
    . ' ' . escapeshellarg(DEPLOY_PATH . '/')
    . ' 2>&1';
$rsyncOut = (string)shell_exec($rsyncCmd);
logMsg("rsync output:\n" . $rsyncOut);

logMsg("DEPLOY OK · commit {$commitSha}");
reply(200, 'Deploy completado: ' . substr($commitSha, 0, 7));
