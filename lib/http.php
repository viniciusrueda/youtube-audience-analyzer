<?php
declare(strict_types=1);

/** Erro com mensagem que pode ir direto pro usuário. */
class ErroUsuario extends RuntimeException
{
    public function __construct(string $mensagem, public int $status = 400, public string $codigo = 'erro')
    {
        parent::__construct($mensagem);
    }
}

function json_out(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!headers_sent() && !array_filter(headers_list(), fn($h) => stripos($h, 'Cache-Control:') === 0)) {
        header('Cache-Control: no-store');
    }
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function falhar(string $mensagem, int $status = 400, string $codigo = 'erro'): void
{
    json_out(['ok' => false, 'erro' => $mensagem, 'codigo' => $codigo], $status);
}

function exigir_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        falhar('Use POST.', 405, 'metodo');
    }
}

function ler_json(): array
{
    $bruto = file_get_contents('php://input', false, null, 0, 20000);
    $j = json_decode($bruto ?: '', true);
    return is_array($j) ? $j : [];
}

function http_request(string $metodo, string $url, array $headers = [], ?string $corpo = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $bruto = curl_exec($ch);
    $erro = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($bruto === false) {
        throw new RuntimeException("Falha de rede em {$url}: {$erro}");
    }
    return ['status' => $status, 'json' => json_decode($bruto, true), 'bruto' => $bruto];
}
