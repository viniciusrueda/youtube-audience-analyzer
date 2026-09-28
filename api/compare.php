<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// Etapa "reduce": recebe só os resumos já prontos (do cache), nunca os comentários brutos de novo.
set_time_limit(180);
exigir_post();

$ids = ler_json()['ids'] ?? [];
$ids = is_array($ids) ? $ids : [];
$ids = array_values(array_unique(array_filter($ids, fn($x) => is_string($x) && preg_match(YT_ID_RE, $x))));
if (count($ids) < 2 || count($ids) > MAX_VIDEOS) {
    falhar('A comparação precisa de 2 a ' . MAX_VIDEOS . ' vídeos já analisados.');
}

$itens = [];
foreach ($ids as $id) {
    $hit = cache_get('v:' . $id);
    if (!$hit) {
        falhar('A análise de um dos vídeos expirou. Rode a análise de novo.', 409, 'analise_expirada');
    }
    $itens[] = $hit;
}

$ordenados = $ids;
sort($ordenados);
$chave = 'cmp:' . sha1(implode(',', $ordenados));
if ($hit = cache_get($chave)) {
    contar('acertos_cache');
    json_out(['ok' => true, 'cache' => true] + $hit);
}

checar_limites();

$resposta = llm_json(SISTEMA_COMPARACAO, prompt_comparacao($itens), 1500);
$resultado = montar_comparacao($resposta['dados'], $ids);
$resultado['modelo'] = $resposta['modelo'];

cache_set($chave, $resultado);
registrar_uso('comparacao', $resposta['usage'], $resposta['modelo']);

json_out(['ok' => true, 'cache' => false] + $resultado);
