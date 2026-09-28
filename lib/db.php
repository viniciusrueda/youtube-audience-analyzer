<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

function cache_get(string $chave, int $horas = CACHE_TTL_HORAS): ?array
{
    $st = db()->prepare(
        'SELECT payload FROM yta_cache
          WHERE chave = ? AND criado_em > (NOW() - INTERVAL ' . (int) $horas . ' HOUR)'
    );
    $st->execute([$chave]);
    $payload = $st->fetchColumn();
    if ($payload === false) {
        return null;
    }
    $j = json_decode($payload, true);
    return is_array($j) ? $j : null;
}

function cache_set(string $chave, array $dados): void
{
    $st = db()->prepare(
        'INSERT INTO yta_cache (chave, payload, criado_em) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), criado_em = NOW()'
    );
    $st->execute([$chave, json_encode($dados, JSON_UNESCAPED_UNICODE)]);
}
