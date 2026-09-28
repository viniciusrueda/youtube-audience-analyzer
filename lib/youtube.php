<?php
declare(strict_types=1);

const YT_BASE = 'https://www.googleapis.com/youtube/v3/';
const YT_ID_RE = '~^[A-Za-z0-9_-]{11}$~';

function yt_get(string $endpoint, array $params): array
{
    $params['key'] = YT_API_KEY;
    contar('unidades', custo_youtube($endpoint)); // a cota é gasta mesmo quando a chamada falha
    contar('chamadas');
    $r = http_request('GET', YT_BASE . $endpoint . '?' . http_build_query($params));
    if ($r['status'] === 200 && is_array($r['json'])) {
        return $r['json'];
    }

    $motivo = $r['json']['error']['errors'][0]['reason'] ?? 'desconhecido';
    if ($motivo === 'commentsDisabled') {
        throw new ErroUsuario('Comentários desativados.', 403, 'comentarios_desativados');
    }
    if (in_array($motivo, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
        throw new ErroUsuario(
            'A cota diária da API do YouTube acabou. Ela renova todo dia entre 4h e 5h (horário de Brasília).',
            503,
            'cota_youtube'
        );
    }
    throw new RuntimeException("YouTube {$endpoint} respondeu {$r['status']}: {$motivo}");
}

/* ---------- Entrada: texto colado -> itens ---------- */

function yt_interpretar_entrada(string $texto): array
{
    $partes = preg_split('~[\s,;]+~u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_map('yt_interpretar_item', $partes);
}

function yt_interpretar_item(string $original): array
{
    $s = trim($original);
    $exibir = mb_substr($s, 0, 60);
    $invalido = fn(string $motivo) => ['tipo' => 'invalido', 'entrada' => $exibir, 'motivo' => $motivo];
    $canal = fn(string $por, string $valor) => ['tipo' => 'canal', 'por' => $por, 'valor' => $valor, 'entrada' => $exibir];

    if (preg_match(YT_ID_RE, $s)) {
        return ['tipo' => 'video', 'id' => $s];
    }
    if (preg_match('~^@([^/?#\s]+)$~u', $s, $m)) {
        return $canal('forHandle', $m[1]);
    }

    if (!preg_match('~^https?://~i', $s)) {
        $s = 'https://' . $s;
    }
    $u = parse_url($s);
    if (!$u || empty($u['host'])) {
        return $invalido('não parece um link');
    }

    $host = strtolower((string) preg_replace('~^(www\.|m\.|music\.)~i', '', $u['host']));
    $caminho = rawurldecode($u['path'] ?? '');
    $id = null;

    if ($host === 'youtu.be') {
        $id = substr(ltrim($caminho, '/'), 0, 11);
    } elseif ($host === 'youtube.com') {
        if ($caminho === '/watch') {
            parse_str($u['query'] ?? '', $q);
            $id = is_string($q['v'] ?? null) ? $q['v'] : null;
        } elseif (preg_match('~^/(?:shorts|live|embed|v)/([A-Za-z0-9_-]{11})~', $caminho, $m)) {
            $id = $m[1];
        } elseif (preg_match('~^/@([^/]+)~u', $caminho, $m)) {
            return $canal('forHandle', $m[1]);
        } elseif (preg_match('~^/channel/(UC[A-Za-z0-9_-]{22})~', $caminho, $m)) {
            return $canal('id', $m[1]);
        } elseif (preg_match('~^/user/([^/]+)~', $caminho, $m)) {
            return $canal('forUsername', $m[1]);
        } elseif (str_starts_with($caminho, '/c/')) {
            return $invalido('links no formato /c/ não têm busca direta na API; use o link do canal com @');
        }
    } else {
        return $invalido('não é um link do YouTube');
    }

    if ($id !== null && preg_match(YT_ID_RE, $id)) {
        return ['tipo' => 'video', 'id' => $id];
    }
    return $invalido('não encontrei o ID do vídeo nesse link');
}

/* ---------- Canal -> vídeos recentes ---------- */

/**
 * Usa a playlist de uploads do canal (1 unidade de cota) em vez de search.list (100 unidades).
 */
function yt_videos_do_canal(array $item, int $quantidade): array
{
    $chave = 'ch:' . sha1($item['por'] . ':' . mb_strtolower($item['valor']));
    if ($hit = cache_get($chave)) {
        return $hit;
    }

    $r = yt_get('channels', ['part' => 'snippet,contentDetails', $item['por'] => $item['valor']]);
    $canal = $r['items'][0] ?? null;
    if (!$canal) {
        throw new ErroUsuario("Canal não encontrado: {$item['entrada']}.", 404, 'canal_nao_encontrado');
    }

    $ids = [];
    $uploads = $canal['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    if ($uploads) {
        $p = yt_get('playlistItems', ['part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => $quantidade]);
        foreach ($p['items'] ?? [] as $it) {
            $vid = $it['contentDetails']['videoId'] ?? '';
            if (preg_match(YT_ID_RE, $vid)) {
                $ids[] = $vid;
            }
        }
    }

    $saida = ['canal' => $canal['snippet']['title'] ?? '', 'canal_id' => $canal['id'], 'videos' => $ids];
    cache_set($chave, $saida);
    return $saida;
}

/* ---------- Vídeo e comentários ---------- */

function yt_video(string $id): array
{
    $r = yt_get('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => $id]);
    $v = $r['items'][0] ?? null;
    if (!$v) {
        throw new ErroUsuario('Vídeo não encontrado, privado ou removido.', 404, 'video_nao_encontrado');
    }

    $sn = $v['snippet'] ?? [];
    $st = $v['statistics'] ?? [];
    $th = $sn['thumbnails'] ?? [];
    $thumb = ($th['maxres'] ?? $th['standard'] ?? $th['high'] ?? $th['medium'] ?? $th['default'] ?? [])['url'] ?? null;
    $num = fn(string $k) => isset($st[$k]) ? (int) $st[$k] : null; // curtidas podem vir ocultas

    return [
        'id'           => $id,
        'titulo'       => $sn['title'] ?? '',
        'canal'        => $sn['channelTitle'] ?? '',
        'canal_id'     => $sn['channelId'] ?? '',
        'publicado_em' => $sn['publishedAt'] ?? null,
        'descricao'    => mb_substr($sn['description'] ?? '', 0, 2000),
        'tags'         => array_slice($sn['tags'] ?? [], 0, 15),
        'duracao_seg'  => yt_duracao($v['contentDetails']['duration'] ?? ''),
        'thumb'        => $thumb,
        'views'        => $num('viewCount'),
        'likes'        => $num('likeCount'),
        'comentarios'  => $num('commentCount'),
    ];
}

function yt_duracao(string $iso): ?int
{
    if ($iso === '') {
        return null;
    }
    try {
        $d = new DateInterval($iso);
        return $d->d * 86400 + $d->h * 3600 + $d->i * 60 + $d->s;
    } catch (Exception) {
        return null;
    }
}

function yt_comentarios(string $id, int $maximo): array
{
    try {
        $r = yt_get('commentThreads', [
            'part'       => 'snippet',
            'videoId'    => $id,
            'order'      => 'relevance',
            'maxResults' => min($maximo, 100),
            'textFormat' => 'plainText',
        ]);
    } catch (ErroUsuario $e) {
        if ($e->codigo === 'comentarios_desativados') {
            return ['desativados' => true, 'indisponiveis' => false, 'itens' => []];
        }
        throw $e; // cota estourada tem que subir
    } catch (RuntimeException $e) {
        error_log('[youtube-analyzer] comentários de ' . $id . ': ' . $e->getMessage());
        return ['desativados' => false, 'indisponiveis' => true, 'itens' => []];
    }

    $itens = [];
    foreach ($r['items'] ?? [] as $it) {
        $c = $it['snippet']['topLevelComment']['snippet'] ?? null;
        if (!$c) {
            continue;
        }
        $texto = trim((string) preg_replace('~\s+~u', ' ', $c['textDisplay'] ?? ''));
        if ($texto === '') {
            continue;
        }
        $itens[] = [
            'texto'     => mb_substr($texto, 0, COMENTARIO_MAX_CHARS),
            'likes'     => (int) ($c['likeCount'] ?? 0),
            'respostas' => (int) ($it['snippet']['totalReplyCount'] ?? 0),
        ];
    }
    return ['desativados' => false, 'indisponiveis' => false, 'itens' => $itens];
}

/* ---------- Radar: busca por nicho e estatísticas em lote ---------- */

/** search.list custa 100 unidades; por isso só o cron chama, uma vez por nicho por dia. */
function yt_buscar_populares(string $consulta, int $dias, int $quantidade): array
{
    $r = yt_get('search', [
        'part'              => 'id',
        'type'              => 'video',
        'q'                 => $consulta,
        'order'             => 'viewCount',
        'regionCode'        => 'BR',
        'relevanceLanguage' => 'pt',
        'publishedAfter'    => gmdate('Y-m-d\TH:i:s\Z', time() - $dias * 86400),
        'maxResults'        => min($quantidade, 50),
        'safeSearch'        => 'moderate',
    ]);
    $ids = [];
    foreach ($r['items'] ?? [] as $it) {
        $id = $it['id']['videoId'] ?? '';
        if (preg_match(YT_ID_RE, $id)) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/** videos.list aceita até 50 IDs por chamada, custando 1 unidade. */
function yt_videos_lote(array $ids): array
{
    $saida = [];
    foreach (array_chunk($ids, 50) as $lote) {
        $r = yt_get('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $lote)]);
        foreach ($r['items'] ?? [] as $v) {
            $sn = $v['snippet'] ?? [];
            $st = $v['statistics'] ?? [];
            $num = fn(string $k) => isset($st[$k]) ? (int) $st[$k] : null;
            $saida[] = [
                'id'           => $v['id'],
                'titulo'       => mb_substr($sn['title'] ?? '', 0, 200),
                'canal'        => mb_substr($sn['channelTitle'] ?? '', 0, 120),
                'canal_id'     => $sn['channelId'] ?? '',
                'publicado_em' => $sn['publishedAt'] ?? null,
                'descricao'    => mb_substr($sn['description'] ?? '', 0, 3000),
                'tags'         => array_slice($sn['tags'] ?? [], 0, 30),
                'duracao_seg'  => yt_duracao($v['contentDetails']['duration'] ?? ''),
                'views'        => $num('viewCount'),
                'likes'        => $num('likeCount'),
                'comentarios'  => $num('commentCount'),
            ];
        }
    }
    return $saida;
}
