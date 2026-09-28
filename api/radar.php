<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// Só leitura do banco: não gasta cota de nenhuma API.
$slug = $_GET['nicho'] ?? null;
$slug = is_string($slug) && preg_match('~^[a-z0-9-]{1,40}$~', $slug) ? $slug : null;
header('Cache-Control: public, max-age=300'); // muda no máximo uma vez a cada 15 minutos
json_out(['ok' => true] + radar_painel($slug));
