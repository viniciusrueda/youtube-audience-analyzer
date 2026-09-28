<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require __DIR__ . '/http.php';
require __DIR__ . '/db.php';
require __DIR__ . '/limites.php';
require __DIR__ . '/youtube.php';
require __DIR__ . '/llm.php';
require __DIR__ . '/analise.php';
require __DIR__ . '/radar.php';
require __DIR__ . '/canais.php';

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ErroUsuario) {
        falhar($e->getMessage(), $e->status, $e->codigo);
    }
    error_log('[youtube-analyzer] ' . $e->getMessage());
    falhar('Erro interno ao processar a análise. Tente de novo em instantes.', 500, 'interno');
});
