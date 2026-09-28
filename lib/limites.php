<?php
declare(strict_types=1);

function ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', IP_SALT . $ip);
}

/** Barra a chamada antes de gastar cota do YouTube ou tokens. Só roda quando não há cache. */
function checar_limites(): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM yta_uso WHERE ip_hash = ? AND criado_em > (NOW() - INTERVAL 1 DAY)');
    $st->execute([ip_hash()]);
    if ((int) $st->fetchColumn() >= LIMITE_IP_DIA) {
        throw new ErroUsuario(
            'Você chegou ao limite de análises novas das últimas 24 horas. Vídeos que já foram analisados continuam abrindo normalmente.',
            429,
            'limite_visitante'
        );
    }

    $total = (int) db()->query("SELECT COUNT(*) FROM yta_uso WHERE acao <> 'radar' AND criado_em > (NOW() - INTERVAL 1 DAY)")->fetchColumn();
    if ($total >= LIMITE_GLOBAL_DIA) {
        throw new ErroUsuario(
            'O limite diário de análises do site foi atingido. Vídeos que já foram analisados continuam abrindo normalmente.',
            429,
            'limite_global'
        );
    }
}

function registrar_uso(string $acao, array $usage, ?string $modelo = null): void
{
    $st = db()->prepare('INSERT INTO yta_uso (ip_hash, acao, modelo, tokens_entrada, tokens_saida) VALUES (?, ?, ?, ?, ?)');
    $st->execute([
        ip_hash(),
        $acao,
        $modelo,
        (int) ($usage['input_tokens'] ?? 0),
        (int) ($usage['output_tokens'] ?? 0),
    ]);
}

/* ---------- Contadores do painel de consumo ---------- */

/** Dia da cota do YouTube: zera à meia-noite do Pacífico. */
function dia_cota(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}

/** Contador nunca pode derrubar a requisição: se a tabela não existir, só registra no log. */
function contar(string $coluna, int $valor = 1): void
{
    static $permitidas = ['unidades', 'chamadas', 'acertos_cache'];
    if (!in_array($coluna, $permitidas, true)) {
        return;
    }
    try {
        db()->prepare("INSERT INTO yta_cota (dia, {$coluna}) VALUES (?, ?) ON DUPLICATE KEY UPDATE {$coluna} = {$coluna} + VALUES({$coluna})")
            ->execute([dia_cota(), $valor]);
    } catch (Throwable $e) {
        error_log('[youtube-analyzer] contador ' . $coluna . ': ' . $e->getMessage());
    }
}

/** Custo de cada endpoint da YouTube Data API em unidades de cota. */
function custo_youtube(string $endpoint): int
{
    return $endpoint === 'search' ? 100 : 1;
}
