<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

/*
 * Comparador de canais. Custo por canal novo: 3 a 4 unidades de cota do YouTube.
 * O modelo roda uma vez por combinação de canais, com cache de 12 horas.
 */
set_time_limit(180);
exigir_post();

$entrada = ler_json()['entrada'] ?? '';
if (!is_string($entrada) || trim($entrada) === '') {
    falhar('Cole os links de ' . CANAIS_MIN . ' a ' . CANAIS_MAX . ' canais.');
}
if (mb_strlen($entrada) > 3000) {
    falhar('O texto colado está grande demais. Cole só os links.');
}

$itens = yt_interpretar_entrada($entrada);
$avisos = [];
$ids = [];
foreach ($itens as $it) {
    if ($it['tipo'] === 'invalido') {
        $avisos[] = "Ignorado \"{$it['entrada']}\": {$it['motivo']}.";
        continue;
    }
    try {
        $ids[] = canal_resolver_id($it);
    } catch (ErroUsuario $e) {
        if ($e->codigo === 'cota_youtube') {
            throw $e;
        }
        $avisos[] = $e->getMessage();
    }
}
$ids = array_values(array_unique($ids));
if (count($ids) < CANAIS_MIN) {
    falhar(trim('A comparação precisa de pelo menos ' . CANAIS_MIN . ' canais diferentes. ' . implode(' ', $avisos)), 400, 'poucos_canais');
}
if (count($ids) > CANAIS_MAX) {
    $avisos[] = 'Foram encontrados ' . count($ids) . ' canais; a comparação usa os ' . CANAIS_MAX . ' primeiros.';
    $ids = array_slice($ids, 0, CANAIS_MAX);
}

// Dados e métricas (cache por canal)
$canais = [];
foreach ($ids as $cid) {
    $c = canal_dados($cid);
    if (count($c['videos']) < 3) {
        $avisos[] = "O canal {$c['nome']} tem poucos vídeos públicos e ficou de fora.";
        continue;
    }
    $c['metricas'] = canal_metricas($c);
    $canais[] = $c;
}
if (count($canais) < CANAIS_MIN) {
    falhar(trim('Sobraram menos de ' . CANAIS_MIN . ' canais com vídeos suficientes. ' . implode(' ', $avisos)), 400, 'poucos_canais');
}

// Leitura do modelo (cache pela combinação, na ordem colada)
$chave = 'cc:' . sha1(implode(',', array_column($canais, 'id')));
$leitura = cache_get($chave, CANAIS_CACHE_HORAS);
$doCache = $leitura !== null;
if ($doCache) {
    contar('acertos_cache');
}
if (!$doCache) {
    try {
        checar_limites();
        $r = llm_json(SISTEMA_CANAIS, prompt_canais($canais), 2200);
        $leitura = montar_leitura_canais($r['dados'], $canais) + ['modelo' => $r['modelo']];
        cache_set($chave, $leitura);
        registrar_uso('canais', $r['usage'], $r['modelo']);
    } catch (ErroUsuario $e) {
        // Sem o modelo, os números continuam valendo: a página mostra tudo menos a leitura
        $leitura = null;
        $avisos[] = 'A leitura dos títulos ficou indisponível: ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('[youtube-analyzer] leitura de canais: ' . $e->getMessage());
        $leitura = null;
        $avisos[] = 'A leitura dos títulos ficou indisponível agora. Os números abaixo não dependem dela.';
    }
}

// O front não precisa da lista completa de vídeos, só do necessário pros gráficos
$saida = array_map(fn($c) => [
    'id'           => $c['id'],
    'nome'         => $c['nome'],
    'handle'       => $c['handle'],
    'avatar'       => $c['avatar'],
    'criado_em'    => $c['criado_em'],
    'inscritos'    => $c['inscritos'],
    'views_total'  => $c['views_total'],
    'videos_total' => $c['videos_total'],
    'metricas'     => $c['metricas'],
], $canais);

json_out(['ok' => true, 'cache' => $doCache, 'canais' => $saida, 'leitura' => $leitura, 'avisos' => $avisos]);
