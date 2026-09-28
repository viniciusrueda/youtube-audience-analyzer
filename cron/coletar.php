<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

/*
 * Chamado pelo cron do Hostinger via HTTP, com token:
 *   curl -s "https://SEU_DOMINIO/youtube-analyzer/cron/coletar.php?token=SEU_TOKEN"
 * Cada chamada processa um nicho pendente do dia. Quando todos estão prontos, não gasta nada.
 */
set_time_limit(120);
ignore_user_abort(true);

$token = $_GET['token'] ?? '';
if (CRON_TOKEN === '' || !is_string($token) || !hash_equals(CRON_TOKEN, $token)) {
    falhar('Não autorizado.', 403, 'token');
}

$dia = radar_hoje();
$nicho = radar_proximo($dia);
if (!$nicho) {
    json_out(['ok' => true, 'dia' => $dia, 'feito' => 'nada pendente hoje']);
}

try {
    $res = radar_coletar($nicho);
    json_out(['ok' => true, 'dia' => $dia] + $res);
} catch (Throwable $e) {
    error_log('[youtube-analyzer] radar ' . $nicho['slug'] . ': ' . $e->getMessage());
    json_out(['ok' => false, 'dia' => $dia, 'nicho' => $nicho['slug'], 'erro' => $e->getMessage()], 500);
}
