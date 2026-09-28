<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

// Transforma o texto colado em uma lista de IDs de vídeo. Não chama o modelo.
exigir_post();
$entrada = ler_json()['entrada'] ?? '';
if (!is_string($entrada) || trim($entrada) === '') {
    falhar('Cole pelo menos um link de vídeo ou de canal.');
}
if (mb_strlen($entrada) > 3000) {
    falhar('O texto colado está grande demais. Cole só os links.');
}

$itens = yt_interpretar_entrada($entrada);
if (count($itens) > MAX_LINKS) {
    falhar('Cole até ' . MAX_LINKS . ' links por vez.', 400, 'muitos_links');
}

$videos = [];
$avisos = [];
$canais = [];

foreach ($itens as $it) {
    if ($it['tipo'] === 'invalido') {
        $avisos[] = "Ignorado \"{$it['entrada']}\": {$it['motivo']}.";
        continue;
    }
    if ($it['tipo'] === 'video') {
        $videos[] = $it['id'];
        continue;
    }
    try {
        $c = yt_videos_do_canal($it, CANAL_VIDEOS);
        $canais[] = ['nome' => $c['canal'], 'videos' => count($c['videos'])];
        if (!$c['videos']) {
            $avisos[] = "O canal {$c['canal']} não tem vídeos públicos.";
        }
        array_push($videos, ...$c['videos']);
    } catch (ErroUsuario $e) {
        if ($e->codigo === 'cota_youtube') {
            throw $e;
        }
        $avisos[] = $e->getMessage();
    }
}

$videos = array_values(array_unique($videos));
if (count($videos) > MAX_VIDEOS) {
    $avisos[] = 'Foram encontrados ' . count($videos) . ' vídeos; a análise usa os ' . MAX_VIDEOS . ' primeiros.';
    $videos = array_slice($videos, 0, MAX_VIDEOS);
}
if (!$videos) {
    falhar($avisos ? implode(' ', $avisos) : 'Nenhum vídeo encontrado nos links colados.', 400, 'sem_videos');
}

json_out(['ok' => true, 'videos' => $videos, 'canais' => $canais, 'avisos' => $avisos]);
