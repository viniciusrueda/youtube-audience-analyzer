<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

/*
 * Painel interno de consumo. Acesso: /youtube-analyzer/painel/?token=SEU_TOKEN (o mesmo do cron).
 * Só lê o banco.
 */
$token = $_GET['token'] ?? '';
if (CRON_TOKEN === '' || !is_string($token) || !hash_equals(CRON_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não autorizado.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$h = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES);
$n = fn($x) => number_format((float) $x, 0, ',', '.');
$COTA_YT = 10000;

function consulta(string $sql, array $p = []): array
{
    try {
        $st = db()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [['_erro' => $e->getMessage()]];
    }
}

$hoje = dia_cota();
$cota = consulta('SELECT dia, unidades, chamadas, acertos_cache FROM yta_cota WHERE dia >= DATE_SUB(?, INTERVAL 13 DAY) ORDER BY dia DESC', [$hoje]);
$cotaHoje = 0;
foreach ($cota as $c) {
    if (($c['dia'] ?? '') === $hoje) {
        $cotaHoje = (int) $c['unidades'];
    }
}

$uso = consulta(
    "SELECT DATE(criado_em) dia,
            SUM(acao = 'video') video, SUM(acao = 'comparacao') comparacao, SUM(acao = 'canais') canais, SUM(acao = 'radar') radar,
            SUM(tokens_entrada) tin, SUM(tokens_saida) tout, COUNT(DISTINCT CASE WHEN acao <> 'radar' THEN ip_hash END) visitantes
       FROM yta_uso WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
      GROUP BY DATE(criado_em) ORDER BY dia DESC"
);
$uso24 = consulta("SELECT COUNT(*) n FROM yta_uso WHERE acao <> 'radar' AND criado_em > NOW() - INTERVAL 1 DAY")[0]['n'] ?? 0;
$modelos = consulta(
    "SELECT COALESCE(modelo, 'não registrado') modelo, COUNT(*) chamadas, SUM(tokens_entrada + tokens_saida) tokens
       FROM yta_uso WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY modelo ORDER BY chamadas DESC"
);
$radar = consulta(
    "SELECT c.dia, SUM(c.status = 'ok') ok, SUM(c.status = 'erro') erro, SUM(c.status = 'pendente') pendente, SUM(c.tentativas) tentativas,
            SUM(c.status = 'ok' AND c.temas_json = '[]') sem_temas
       FROM yta_radar_coleta c WHERE c.dia >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY c.dia ORDER BY c.dia DESC"
);
$errosRadar = consulta(
    "SELECT c.dia, n.nome, c.tentativas, c.erro FROM yta_radar_coleta c JOIN yta_radar_nicho n ON n.id = c.nicho_id
      WHERE c.erro IS NOT NULL AND c.dia >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY c.dia DESC, n.ordem LIMIT 20"
);
$cache = consulta("SELECT LEFT(chave, LOCATE(':', chave) - 1) tipo, COUNT(*) n FROM yta_cache GROUP BY tipo ORDER BY n DESC");

$pct = min(100, $cotaHoje / $COTA_YT * 100);
$tiposCache = ['v' => 'análises de vídeo', 'cmp' => 'comparações de vídeos', 'ch' => 'canais resolvidos', 'cd' => 'dados de canal', 'cc' => 'leituras de canais'];
$semTabela = isset($cota[0]['_erro']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Consumo | Leitor de audiência do YouTube</title>
  <link rel="stylesheet" href="../assets/style.css?v=5">
  <style>
    body { padding-bottom: 3rem; }
    .topo { padding: 2rem 0 1rem; }
    .topo h1 { font-size: 1.75rem; }
    .topo p { color: var(--muted); margin-top: .3rem; }
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem; margin-top: 1.25rem; }
    .kpi { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 1rem 1.1rem; }
    .kpi span { display: block; font-size: .8125rem; color: var(--muted); }
    .kpi strong { display: block; margin-top: .2rem; font: 600 1.6rem/1.2 var(--f-display); font-variant-numeric: tabular-nums; }
    .kpi small { display: block; margin-top: .2rem; font-size: .8125rem; color: var(--muted); }
    .kpi .barra { margin-top: .6rem; }
    table.t { width: 100%; border-collapse: collapse; font-size: .875rem; margin-top: .75rem; }
    table.t th, table.t td { padding: .45rem .6rem .45rem 0; border-bottom: 1px solid var(--line); text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.t th:first-child, table.t td:first-child { text-align: left; }
    table.t thead th { font-weight: 500; color: var(--muted); font-size: .8125rem; }
    .rolagem { overflow-x: auto; }
    table.esq2 th:nth-child(2), table.esq2 td:nth-child(2) { text-align: left; }
    .erro-txt { color: var(--neg); white-space: normal !important; text-align: left !important; }
    .aviso { margin-top: 1rem; padding: .8rem 1rem; border-left: 3px solid var(--neg); background: var(--surface); }
  </style>
</head>
<body>
<main class="miolo">
  <header class="topo">
    <h1>Consumo do projeto</h1>
    <p>Dia da cota do YouTube: <?= $h($hoje) ?> (zera à meia-noite do Pacífico, entre 4h e 5h em Brasília). Página interna, não indexada.</p>
  </header>

  <?php if ($semTabela): ?>
    <p class="aviso">A tabela <code>yta_cota</code> não existe ainda. Rode <code>sql/consumo.sql</code> no phpMyAdmin.</p>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi"><span>Cota do YouTube hoje</span><strong><?= $n($cotaHoje) ?></strong><small>de <?= $n($COTA_YT) ?> unidades (<?= number_format($pct, 1, ',', '.') ?>%)</small>
      <div class="barra"><span style="width:<?= number_format($pct, 1, '.', '') ?>%"></span></div></div>
    <div class="kpi"><span>Chamadas ao LLM de visitantes, 24 h</span><strong><?= $n($uso24) ?></strong><small>teto do site: <?= $n(LIMITE_GLOBAL_DIA) ?></small>
      <div class="barra"><span style="width:<?= number_format(min(100, $uso24 / max(1, LIMITE_GLOBAL_DIA) * 100), 1, '.', '') ?>%"></span></div></div>
    <?php $hojeUso = $uso[0] ?? []; ?>
    <div class="kpi"><span>Tokens hoje (entrada + saída)</span><strong><?= $n(($hojeUso['tin'] ?? 0) + ($hojeUso['tout'] ?? 0)) ?></strong><small>dia do servidor</small></div>
    <?php $acertos = (int) ($cota[0]['acertos_cache'] ?? 0); $novas = (int) (($hojeUso['video'] ?? 0) + ($hojeUso['comparacao'] ?? 0) + ($hojeUso['canais'] ?? 0)); ?>
    <div class="kpi"><span>Respostas do cache hoje</span><strong><?= $n($acertos) ?></strong>
      <small><?= ($acertos + $novas) ? number_format($acertos / ($acertos + $novas) * 100, 0) . '% das análises sem gastar nada' : 'sem análises hoje' ?></small></div>
  </div>

  <section class="bloco">
    <h2>Últimos 14 dias</h2>
    <div class="rolagem"><table class="t">
      <thead><tr><th>Dia</th><th>Unidades YouTube</th><th>Do cache</th><th>Vídeos</th><th>Comparações</th><th>Canais</th><th>Radar</th><th>Visitantes</th><th>Tokens entrada</th><th>Tokens saída</th></tr></thead>
      <tbody>
      <?php
      $porDia = [];
      foreach ($cota as $c) { if (isset($c['dia'])) { $porDia[$c['dia']]['cota'] = $c; } }
      foreach ($uso as $u) { if (isset($u['dia'])) { $porDia[$u['dia']]['uso'] = $u; } }
      krsort($porDia);
      foreach ($porDia as $dia => $d): $c = $d['cota'] ?? []; $u = $d['uso'] ?? []; ?>
        <tr><td><?= $h($dia) ?></td><td><?= $n($c['unidades'] ?? 0) ?></td><td><?= $n($c['acertos_cache'] ?? 0) ?></td>
          <td><?= $n($u['video'] ?? 0) ?></td><td><?= $n($u['comparacao'] ?? 0) ?></td><td><?= $n($u['canais'] ?? 0) ?></td><td><?= $n($u['radar'] ?? 0) ?></td>
          <td><?= $n($u['visitantes'] ?? 0) ?></td><td><?= $n($u['tin'] ?? 0) ?></td><td><?= $n($u['tout'] ?? 0) ?></td></tr>
      <?php endforeach; if (!$porDia): ?><tr><td colspan="10">Sem registros ainda.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="nota">A cota do YouTube é contada no dia do Pacífico e o uso do LLM no dia do servidor, então a virada dos dois pode não coincidir.</p>
  </section>

  <section class="bloco">
    <h2>Qual LLM respondeu, últimos 7 dias</h2>
    <table class="t"><thead><tr><th>Modelo</th><th>Chamadas</th><th>Tokens</th></tr></thead><tbody>
      <?php foreach ($modelos as $m): if (isset($m['_erro'])) continue; ?>
        <tr><td><?= $h($m['modelo']) ?></td><td><?= $n($m['chamadas']) ?></td><td><?= $n($m['tokens']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <p class="nota">Quando o modelo reserva aparece com frequência, o principal está batendo no limite gratuito.</p>
  </section>

  <section class="bloco">
    <h2>Coleta do radar, últimos 7 dias</h2>
    <table class="t"><thead><tr><th>Dia</th><th>Nichos ok</th><th>Com erro</th><th>Pendentes</th><th>Tentativas</th><th>Sem assuntos do LLM</th></tr></thead><tbody>
      <?php foreach ($radar as $r): if (isset($r['_erro'])) continue; ?>
        <tr><td><?= $h($r['dia']) ?></td><td><?= $n($r['ok']) ?></td><td><?= $n($r['erro']) ?></td><td><?= $n($r['pendente']) ?></td><td><?= $n($r['tentativas']) ?></td><td><?= $n($r['sem_temas']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php if ($errosRadar && !isset($errosRadar[0]['_erro'])): ?>
      <h3 style="margin-top:1.25rem">Últimos erros</h3>
      <div class="rolagem"><table class="t esq2"><thead><tr><th>Dia</th><th>Nicho</th><th>Tentativas</th><th>Erro</th></tr></thead><tbody>
        <?php foreach ($errosRadar as $e): ?><tr><td><?= $h($e['dia']) ?></td><td><?= $h($e['nome']) ?></td><td><?= $n($e['tentativas']) ?></td><td class="erro-txt"><?= $h($e['erro']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="bloco">
    <h2>O que está em cache</h2>
    <table class="t"><thead><tr><th>Tipo</th><th>Itens</th></tr></thead><tbody>
      <?php foreach ($cache as $c): if (isset($c['_erro'])) continue; ?>
        <tr><td><?= $h($tiposCache[$c['tipo']] ?? $c['tipo']) ?></td><td><?= $n($c['n']) ?></td></tr>
      <?php endforeach; if (!$cache || isset($cache[0]['_erro'])): ?><tr><td colspan="2">Cache vazio.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
</main>
</body>
</html>
