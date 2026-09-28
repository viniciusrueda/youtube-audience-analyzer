<?php
declare(strict_types=1);

/**
 * Único ponto que conversa com os modelos. Tenta cada item de LLM_CADEIA em ordem
 * e devolve o primeiro que responder: ['dados' => array, 'usage' => [...], 'modelo' => string].
 * Erro de sobrecarga ou cota passa pro próximo; erro de configuração também, mas fica no log.
 */
function llm_json(string $sistema, string $mensagem, int $maxTokens = 2200): array
{
    $falhas = [];
    foreach (LLM_CADEIA as $item) {
        [$provedor, $modelo] = array_pad(explode(':', $item, 2), 2, '');
        try {
            $r = match ($provedor) {
                'groq'   => groq_chamar($modelo, $sistema, $mensagem, $maxTokens),
                'gemini' => gemini_chamar($modelo, $sistema, $mensagem, $maxTokens),
                default  => throw new RuntimeException("Provedor desconhecido: {$provedor}"),
            };
            $r['modelo'] = $modelo;
            return $r;
        } catch (ErroUsuario $e) {
            throw $e; // conteúdo bloqueado: outro modelo não resolve
        } catch (Throwable $e) {
            $falhas[] = "{$item}: " . $e->getMessage();
            error_log("[youtube-analyzer] {$item} falhou: " . $e->getMessage());
        }
    }

    $sobrecarga = (bool) array_filter($falhas, fn($f) => preg_match('~HTTP (429|5\d\d)|Falha de rede~', $f));
    if ($sobrecarga) {
        throw new ErroUsuario('Os modelos gratuitos estão no limite agora. Tente de novo em alguns minutos.', 503, 'llm_ocupado');
    }
    throw new RuntimeException('Nenhum modelo respondeu: ' . implode(' | ', $falhas));
}

/* ---------- Groq (API compatível com OpenAI) ---------- */

function groq_chamar(string $modelo, string $sistema, string $mensagem, int $maxTokens): array
{
    $corpo = [
        'model'                 => $modelo,
        'messages'              => [
            ['role' => 'system', 'content' => $sistema],
            ['role' => 'user', 'content' => $mensagem],
        ],
        'temperature'           => 0.4,
        'max_completion_tokens' => $maxTokens,
        'response_format'       => ['type' => 'json_object'],
    ];
    if (str_starts_with($modelo, 'openai/gpt-oss')) {
        $corpo['reasoning_effort'] = 'low'; // menos tokens de raciocínio = cabe no limite por minuto
    }

    $r = http_request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
        'Authorization: Bearer ' . GROQ_API_KEY,
        'content-type: application/json',
    ], json_encode($corpo, JSON_UNESCAPED_UNICODE), 30);

    if ($r['status'] !== 200) {
        throw new RuntimeException("HTTP {$r['status']} " . ($r['json']['error']['message'] ?? ''));
    }
    $escolha = $r['json']['choices'][0] ?? [];
    if (($escolha['finish_reason'] ?? '') === 'length') {
        throw new RuntimeException('resposta cortada por max_completion_tokens');
    }

    $dados = extrair_json((string) ($escolha['message']['content'] ?? ''));
    if ($dados === null) {
        throw new RuntimeException('resposta não veio em JSON válido');
    }
    $uso = $r['json']['usage'] ?? [];
    return [
        'dados' => $dados,
        'usage' => [
            'input_tokens'  => (int) ($uso['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($uso['completion_tokens'] ?? 0),
        ],
    ];
}

/* ---------- Gemini ---------- */

function gemini_chamar(string $modelo, string $sistema, string $mensagem, int $maxTokens): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo) . ':generateContent';
    $corpo = json_encode([
        'systemInstruction' => ['parts' => [['text' => $sistema]]],
        'contents'          => [['role' => 'user', 'parts' => [['text' => $mensagem]]]],
        'generationConfig'  => [
            'responseMimeType' => 'application/json',
            'temperature'      => 0.4,
            'maxOutputTokens'  => max($maxTokens, 8192), // modelos com "thinking" gastam parte disso raciocinando
        ],
    ], JSON_UNESCAPED_UNICODE);

    $r = http_request('POST', $url, ['x-goog-api-key: ' . GEMINI_API_KEY, 'content-type: application/json'], $corpo, 40);
    if ($r['status'] !== 200) {
        throw new RuntimeException("HTTP {$r['status']} " . ($r['json']['error']['message'] ?? ''));
    }

    $cand = $r['json']['candidates'][0] ?? null;
    if (!$cand) {
        $bloqueio = $r['json']['promptFeedback']['blockReason'] ?? 'sem candidato';
        throw new ErroUsuario("O modelo recusou analisar este conteúdo ({$bloqueio}).", 422, 'llm_bloqueado');
    }
    if (($cand['finishReason'] ?? '') === 'MAX_TOKENS') {
        throw new RuntimeException('resposta cortada por maxOutputTokens');
    }

    $texto = '';
    foreach ($cand['content']['parts'] ?? [] as $parte) {
        if (empty($parte['thought'])) {
            $texto .= $parte['text'] ?? '';
        }
    }
    $dados = extrair_json($texto);
    if ($dados === null) {
        throw new RuntimeException('resposta não veio em JSON válido');
    }
    $uso = $r['json']['usageMetadata'] ?? [];
    return [
        'dados' => $dados,
        'usage' => [
            'input_tokens'  => (int) ($uso['promptTokenCount'] ?? 0),
            'output_tokens' => (int) (($uso['candidatesTokenCount'] ?? 0) + ($uso['thoughtsTokenCount'] ?? 0)),
        ],
    ];
}

function extrair_json(string $t): ?array
{
    $t = trim((string) preg_replace('~^```(?:json)?\s*|\s*```$~i', '', trim($t)));
    $ini = strpos($t, '{');
    $fim = strrpos($t, '}');
    if ($ini === false || $fim === false || $fim < $ini) {
        return null;
    }
    $j = json_decode(substr($t, $ini, $fim - $ini + 1), true);
    return is_array($j) ? $j : null;
}
