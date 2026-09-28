<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// Etapa "map": um vídeo por requisição, então nenhuma chamada fica gigante e o front mostra progresso.
set_time_limit(180);

$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match(YT_ID_RE, $id)) {
    falhar('ID de vídeo inválido.');
}

$chave = 'v:' . $id;
if ($hit = cache_get($chave)) {
    contar('acertos_cache');
    json_out(['ok' => true, 'cache' => true] + $hit);
}

checar_limites();

$video = yt_video($id);
$comentarios = yt_comentarios($id, MAX_COMENTARIOS);

$resposta = llm_json(SISTEMA_VIDEO, prompt_video($video, $comentarios['itens']));
$resultado = montar_resultado($video, $comentarios, $resposta['dados']);
$resultado['modelo'] = $resposta['modelo']; // pode ser o modelo reserva

cache_set($chave, $resultado);
registrar_uso('video', $resposta['usage'], $resposta['modelo']);

json_out(['ok' => true, 'cache' => false] + $resultado);
