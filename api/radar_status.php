<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// Só leitura do banco: não gasta cota de nenhuma API.
json_out(['ok' => true] + radar_status());
