(() => {
  'use strict';

  const API = 'api/';
  const PARALELO = 1; // um vídeo por vez: o Groq grátis limita tokens por minuto

  /* ---------- utilitários ---------- */

  const $ = (sel, el = document) => el.querySelector(sel);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const nInt = new Intl.NumberFormat('pt-BR');
  const nDec = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
  const fmt = (x) => (x === null || x === undefined ? 'oculto' : nInt.format(x));
  const dec = (x) => (x === null || x === undefined ? 'n/d' : nDec.format(x));
  const curto = (t, n = 32) => (t.length > n ? t.slice(0, n - 1).trimEnd() + '…' : t);
  const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

  const data = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  const duracao = (s) => {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = s % 60;
    const p = (x) => String(x).padStart(2, '0');
    return h ? `${h}:${p(m)}:${p(ss)}` : `${m}:${p(ss)}`;
  };

  function cores() {
    const cs = getComputedStyle(document.documentElement);
    const v = (k) => cs.getPropertyValue(k).trim();
    return { p: v('--pos'), u: v('--neu'), n: v('--neg'), misto: v('--copper'), copper: v('--copper'), linha: v('--line'), texto: v('--muted') };
  }

  /* ---------- Chart.js ---------- */

  let graficos = [];
  const novoGrafico = (canvas, cfg) => {
    if (!window.Chart || !canvas) return null; // CDN fora do ar: o relatório aparece sem os gráficos
    const g = new Chart(canvas, cfg);
    graficos.push(g);
    return g;
  };

  function configurarChart() {
    const c = cores();
    Chart.defaults.font.family = "'Inter', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = c.texto;
    Chart.defaults.borderColor = c.linha;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.plugins.tooltip.backgroundColor = '#14213D';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 4;
  }

  /* ---------- API ---------- */

  async function chamar(caminho, opcoes = {}) {
    let r;
    try {
      r = await fetch(API + caminho, opcoes);
    } catch {
      throw new Error('Sem conexão com o servidor.');
    }
    let j = null;
    try { j = await r.json(); } catch { /* resposta não-JSON */ }
    if (!j) throw new Error(`O servidor respondeu ${r.status} sem dados.`);
    if (!j.ok) throw new Error(j.erro || 'Não foi possível concluir.');
    return j;
  }

  const enviar = (caminho, corpo) =>
    chamar(caminho, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(corpo) });

  async function emParalelo(itens, limite, fn) {
    const fila = [...itens];
    const trabalhadores = Array.from({ length: Math.min(limite, fila.length) }, async () => {
      while (fila.length) await fn(fila.shift());
    });
    await Promise.all(trabalhadores);
  }

  /* ---------- log de execução ---------- */

  function linhaLog(alvo, lista = $('#log')) {
    const li = document.createElement('li');
    li.className = 'fila';
    li.innerHTML = `<span class="ponto" aria-hidden="true"></span><span class="alvo">${esc(alvo)}</span><span class="msg">na fila</span><span class="tempo"></span>`;
    lista.append(li);

    const msg = $('.msg', li), tempo = $('.tempo', li);
    let t0 = 0, timer = null;
    const segundos = () => ((performance.now() - t0) / 1000).toFixed(1).replace('.', ',') + ' s';
    const parar = () => { clearInterval(timer); if (t0) tempo.textContent = segundos(); };

    return {
      iniciar(texto) {
        li.className = 'rodando';
        msg.textContent = texto;
        t0 = performance.now();
        timer = setInterval(() => { tempo.textContent = segundos(); }, 100);
      },
      concluir(texto, novoAlvo) {
        parar();
        li.className = 'ok';
        msg.textContent = texto;
        if (novoAlvo) $('.alvo', li).textContent = novoAlvo;
      },
      falhar(texto) {
        parar();
        li.className = 'erro';
        msg.textContent = texto;
      },
    };
  }

  /* ---------- fluxo principal ---------- */

  const form = $('#form');
  const campo = $('#links');
  const botao = $('#btn');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const entrada = campo.value.trim();
    if (!entrada) { campo.focus(); return; }

    botao.disabled = true;
    botao.textContent = 'Analisando…';
    limpar();
    $('#execucao').hidden = false;
    $('#execucao').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });

    try {
      await executar(entrada);
    } finally {
      botao.disabled = false;
      botao.textContent = 'Analisar';
    }
  });

  function limpar() {
    graficos.forEach((g) => g.destroy());
    graficos = [];
    $('#log').innerHTML = '';
    $('#avisos').innerHTML = '';
    $('#relatorios').innerHTML = '';
    const cmp = $('#comparacao');
    cmp.hidden = true;
    cmp.innerHTML = '';
  }

  async function executar(entrada) {
    const passo = linhaLog('links');
    passo.iniciar('identificando vídeos');
    let res;
    try {
      res = await enviar('resolve.php', { entrada });
    } catch (err) {
      passo.falhar(err.message);
      return;
    }
    passo.concluir(plural(res.videos.length, 'vídeo encontrado', 'vídeos encontrados'));
    $('#avisos').innerHTML = res.avisos.map((a) => `<li>${esc(a)}</li>`).join('');

    const lista = $('#relatorios');
    const linhas = new Map();
    for (const id of res.videos) {
      const art = document.createElement('article');
      art.className = 'rel aguardando';
      art.id = 'rel-' + id;
      art.innerHTML = `<p>Aguardando a análise de <code>${esc(id)}</code></p>`;
      lista.append(art);
      linhas.set(id, linhaLog(id));
    }

    const prontos = new Map();
    await emParalelo(res.videos, PARALELO, async (id) => {
      const l = linhas.get(id);
      l.iniciar('lendo dados e analisando');
      try {
        const d = await chamar('video.php?id=' + encodeURIComponent(id));
        prontos.set(id, d);
        l.concluir(d.cache ? 'pronto, veio do cache' : 'pronto', curto(d.video.titulo, 56));
        renderRelatorio(d);
      } catch (err) {
        l.falhar(err.message);
        document.getElementById('rel-' + id)?.remove();
      }
    });

    const ok = res.videos.filter((id) => prontos.has(id)).map((id) => prontos.get(id));
    if (ok.length < 2) return;

    const l = linhaLog('comparação');
    l.iniciar(`cruzando ${ok.length} vídeos`);
    try {
      const c = await enviar('compare.php', { ids: ok.map((d) => d.video.id) });
      l.concluir(c.cache ? 'pronta, veio do cache' : 'pronta');
      renderComparacao(ok, c);
    } catch (err) {
      l.falhar(err.message);
    }
  }

  /* ---------- relatório de um vídeo ---------- */

  function renderRelatorio(d) {
    const { video: v, metricas: m, comentarios: c, analise: a } = d;
    const el = document.getElementById('rel-' + v.id);
    if (!el) return;
    el.className = 'rel';

    const link = 'https://www.youtube.com/watch?v=' + encodeURIComponent(v.id);
    const s = c.sentimento;
    const total = s.p + s.u + s.n;
    const pct = (x) => (total ? Math.round((x / total) * 100) : 0);

    const semComentarios = c.desativados
      ? 'Os comentários estão desativados neste vídeo.'
      : c.indisponiveis
        ? 'Não foi possível ler os comentários deste vídeo.'
        : 'Este vídeo ainda não tem comentários.';

    const blocoSentimento = total
      ? `<figure class="sentimento">
          <figcaption>Tom dos ${nInt.format(c.classificados)} comentários lidos</figcaption>
          <div class="rosca">
            <canvas role="img" aria-label="${pct(s.p)}% positivos, ${pct(s.u)}% neutros, ${pct(s.n)}% negativos"></canvas>
            <div class="rosca-centro"><strong>${pct(s.p)}%</strong><span>positivos</span></div>
          </div>
          <ul class="legenda">
            <li><i class="cor p"></i>Positivos <b>${pct(s.p)}%</b></li>
            <li><i class="cor u"></i>Neutros ou perguntas <b>${pct(s.u)}%</b></li>
            <li><i class="cor n"></i>Negativos <b>${pct(s.n)}%</b></li>
          </ul>
        </figure>`
      : `<div class="sentimento vazio"><p>${semComentarios}</p></div>`;

    const temas = a.temas || [];
    const blocoTemas = temas.length
      ? `<section class="temas">
          <h3>Assuntos que mais aparecem nos comentários</h3>
          <div class="cv" style="height:${temas.length * 38 + 30}px">
            <canvas role="img" aria-label="${esc(temas.map((t) => `${t.tema}: ${t.mencoes} menções`).join('; '))}"></canvas>
          </div>
          <p class="nota-cores">A cor indica o tom predominante:<i class="cor p"></i>positivo<i class="cor u"></i>neutro<i class="cor n"></i>negativo<i class="cor misto"></i>misto</p>
        </section>`
      : '';

    const voz = (titulo, itens, tipo) =>
      itens && itens.length ? `<div class="voz ${tipo}"><h3>${titulo}</h3><ul>${itens.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></div>` : '';
    const vozes = voz('O que elogiam', a.elogios, 'p') + voz('O que criticam', a.criticas, 'n') + voz('O que perguntam', a.perguntas, 'u');

    const am = c.amostras || {};
    const citar = (lista, tipo) =>
      (lista || []).map((x) => `<blockquote class="${tipo}"><p>${esc(x.texto)}</p><footer>${plural(x.likes, 'curtida', 'curtidas')}</footer></blockquote>`).join('');
    const amostras = (am.p?.length || am.n?.length)
      ? `<section class="amostras"><h3>Comentários mais curtidos de cada lado</h3><div class="amostras-grade">${citar(am.p, 'p')}${citar(am.n, 'n')}</div></section>`
      : '';

    const titulos = (a.titulos || []).map((t) => `<li><strong>${esc(t.titulo)}</strong><span>${esc(t.por_que)}</span></li>`).join('');
    const thumbs = (a.thumbnails || []).map((t) =>
      `<li><p>${esc(t.conceito)}</p>${t.texto ? `<p class="sobreposto">Texto na imagem: <mark>${esc(t.texto)}</mark></p>` : ''}</li>`).join('');

    el.innerHTML = `
      <header class="rel-topo">
        <a class="thumb" href="${link}" target="_blank" rel="noopener">${v.thumb ? `<img src="${esc(v.thumb)}" alt="" loading="lazy">` : ''}</a>
        <div>
          <h2><a href="${link}" target="_blank" rel="noopener">${esc(v.titulo)}</a></h2>
          <p class="meta">${esc(v.canal)}, publicado em ${data(v.publicado_em)}${v.duracao_seg ? `, ${duracao(v.duracao_seg)} de duração` : ''}</p>
          <dl class="numeros">
            <div><dt>Visualizações</dt><dd>${fmt(v.views)}</dd></div>
            <div><dt>Curtidas</dt><dd>${fmt(v.likes)}</dd></div>
            <div><dt>Comentários</dt><dd>${fmt(v.comentarios)}</dd></div>
            <div><dt>Curtidas a cada mil views</dt><dd>${dec(m.likes_por_mil)}</dd></div>
          </dl>
        </div>
      </header>

      <div class="rel-corpo">
        <div class="texto">
          <h3>Do que o vídeo trata</h3>
          <p>${esc(a.resumo)}</p>
          ${a.publico ? `<h3>Quem comenta</h3><p>${esc(a.publico)}</p>` : ''}
        </div>
        ${blocoSentimento}
      </div>

      ${blocoTemas}
      ${vozes ? `<div class="vozes">${vozes}</div>` : ''}
      ${amostras}

      <div class="sugestoes">
        ${titulos ? `<section><h3>Outros títulos possíveis</h3><ul class="titulos">${titulos}</ul></section>` : ''}
        ${thumbs ? `<section><h3>Ideias de thumbnail</h3><ul class="thumbs">${thumbs}</ul></section>` : ''}
      </div>

      <p class="rodape-rel">Base da análise: título, descrição, tags, estatísticas e ${plural(c.analisados, 'comentário', 'comentários mais relevantes')}. Modelo ${esc(d.modelo)}, gerado em ${new Date(d.gerado_em).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })}.</p>`;

    const cor = cores();

    if (total) {
      novoGrafico($('.rosca canvas', el), {
        type: 'doughnut',
        data: {
          labels: ['Positivos', 'Neutros ou perguntas', 'Negativos'],
          datasets: [{ data: [s.p, s.u, s.n], backgroundColor: [cor.p, cor.u, cor.n], borderWidth: 0 }],
        },
        options: {
          cutout: '70%',
          plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${ctx.parsed} (${pct(ctx.parsed)}%)` } } },
        },
      });
    }

    if (temas.length) {
      novoGrafico($('.temas canvas', el), {
        type: 'bar',
        data: {
          labels: temas.map((t) => curto(t.tema, 40)),
          datasets: [{
            data: temas.map((t) => t.mencoes),
            backgroundColor: temas.map((t) => cor[t.tom] || cor.u),
            borderRadius: 3,
            barThickness: 20,
          }],
        },
        options: {
          indexAxis: 'y',
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'comentários que citam o assunto' } },
            y: { grid: { display: false }, ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--ink').trim() } },
          },
          plugins: {
            tooltip: {
              callbacks: {
                title: (items) => temas[items[0].dataIndex].tema,
                label: (ctx) => ` ${plural(ctx.parsed.x, 'menção', 'menções')}`,
                afterLabel: (ctx) => (temas[ctx.dataIndex].exemplo ? `Ex.: ${temas[ctx.dataIndex].exemplo}` : ''),
              },
            },
          },
        },
      });
    }
  }

  /* ---------- comparação ---------- */

  function renderComparacao(itens, c) {
    const sec = $('#comparacao');
    const cor = cores();
    const nomes = itens.map((d) => curto(d.video.titulo, 28));
    const melhor = c.melhor ? itens.find((d) => d.video.id === c.melhor.id) : null;

    const linhas = itens.map((d) => `
      <tr>
        <th scope="row"><a href="#rel-${esc(d.video.id)}">${esc(curto(d.video.titulo, 60))}</a></th>
        <td>${fmt(d.video.views)}</td>
        <td>${fmt(d.metricas.views_por_dia)}</td>
        <td>${dec(d.metricas.likes_por_mil)}</td>
        <td>${dec(d.metricas.comentarios_por_mil)}</td>
      </tr>`).join('');

    const lista = (titulo, itensLista) =>
      itensLista.length ? `<section><h3>${titulo}</h3><ul>${itensLista.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></section>` : '';

    const altura = itens.length * 42 + 48;

    sec.innerHTML = `
      <h2>Comparação entre os ${itens.length} vídeos</h2>
      <p class="sintese">${esc(c.sintese)}</p>
      ${melhor ? `<p class="melhor"><span>Reação mais favorável do público</span><strong>${esc(melhor.video.titulo)}</strong>. ${esc(c.melhor.motivo)}</p>` : ''}

      <div class="tabela">
        <table>
          <thead><tr>
            <th scope="col">Vídeo</th><th scope="col">Visualizações</th><th scope="col">Views por dia</th>
            <th scope="col">Curtidas por mil views</th><th scope="col">Comentários por mil views</th>
          </tr></thead>
          <tbody>${linhas}</tbody>
        </table>
      </div>

      <div class="cmp-graficos">
        <figure><figcaption>Curtidas a cada mil visualizações</figcaption><div class="cv" style="height:${altura}px"><canvas></canvas></div></figure>
        <figure><figcaption>Tom dos comentários</figcaption><div class="cv" style="height:${altura}px"><canvas></canvas></div></figure>
      </div>

      <div class="cmp-listas">
        ${lista('Padrões entre os vídeos', c.padroes || [])}
        ${lista('O que testar nos próximos vídeos', c.recomendacoes || [])}
      </div>`;
    sec.hidden = false;

    const [cvLikes, cvTom] = sec.querySelectorAll('canvas');

    novoGrafico(cvLikes, {
      type: 'bar',
      data: {
        labels: nomes,
        datasets: [{ data: itens.map((d) => d.metricas.likes_por_mil ?? 0), backgroundColor: cor.copper, borderRadius: 3, barThickness: 20 }],
      },
      options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true }, y: { grid: { display: false } } },
        plugins: { tooltip: { callbacks: { title: (it) => itens[it[0].dataIndex].video.titulo, label: (ctx) => ` ${dec(ctx.parsed.x)} curtidas por mil views` } } },
      },
    });

    const perc = (d, k) => {
      const s = d.comentarios.sentimento;
      const t = s.p + s.u + s.n;
      return t ? Math.round((s[k] / t) * 1000) / 10 : 0;
    };
    novoGrafico(cvTom, {
      type: 'bar',
      data: {
        labels: nomes,
        datasets: [
          { label: 'Positivos', data: itens.map((d) => perc(d, 'p')), backgroundColor: cor.p, barThickness: 20 },
          { label: 'Neutros', data: itens.map((d) => perc(d, 'u')), backgroundColor: cor.u, barThickness: 20 },
          { label: 'Negativos', data: itens.map((d) => perc(d, 'n')), backgroundColor: cor.n, barThickness: 20 },
        ],
      },
      options: {
        indexAxis: 'y',
        scales: {
          x: { stacked: true, max: 100, ticks: { callback: (v) => v + '%' } },
          y: { stacked: true, grid: { display: false } },
        },
        plugins: {
          legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
          tooltip: { callbacks: { title: (it) => itens[it[0].dataIndex].video.titulo, label: (ctx) => ` ${ctx.dataset.label}: ${dec(ctx.parsed.x)}%` } },
        },
      },
    });
  }

  /* ---------- comparador de canais ---------- */

  const formCanais = $('#form-canais');
  const campoCanais = $('#canais-links');
  const botaoCanais = $('#btn-canais');
  let graficosCanais = [];

  const corCanal = (i) => `var(--c${i + 1})`;
  const corCanalHex = (i) => getComputedStyle(document.documentElement).getPropertyValue(`--c${i + 1}`).trim();
  const compacto = new Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1 });
  const cmp = (x) => (x === null || x === undefined ? 'n/d' : compacto.format(x));
  const DIAS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  const DIAS_LONGOS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
  const FAIXAS = ['0h', '4h', '8h', '12h', '16h', '20h'];

  formCanais.addEventListener('submit', async (e) => {
    e.preventDefault();
    const entrada = campoCanais.value.trim();
    if (!entrada) { campoCanais.focus(); return; }

    botaoCanais.disabled = true;
    botaoCanais.textContent = 'Comparando…';
    graficosCanais.forEach((g) => g.destroy());
    graficosCanais = [];
    $('#log-canais').innerHTML = '';
    $('#avisos-canais').innerHTML = '';
    $('#canais-resultado').innerHTML = '';
    $('#execucao-canais').hidden = false;

    const l = linhaLog('canais', $('#log-canais'));
    l.iniciar('lendo vídeos e calculando');
    try {
      const r = await enviar('canais.php', { entrada });
      l.concluir(`${plural(r.canais.length, 'canal comparado', 'canais comparados')}${r.cache ? ', leitura do cache' : ''}`);
      $('#avisos-canais').innerHTML = r.avisos.map((a) => `<li>${esc(a)}</li>`).join('');
      renderCanais(r);
    } catch (err) {
      l.falhar(err.message);
    } finally {
      botaoCanais.disabled = false;
      botaoCanais.textContent = 'Comparar canais';
    }
  });

  function renderCanais(r) {
    const cs = r.canais;
    const lt = r.leitura;
    const el = $('#canais-resultado');
    const estilo = (i) => `style="--cor:${corCanal(i)}"`;
    const linkCanal = (c) => 'https://www.youtube.com/' + (c.handle ? encodeURIComponent(c.handle).replace('%40', '@') : 'channel/' + encodeURIComponent(c.id));

    // Cabeçalho com a identidade de cada canal
    const topo = cs.map((c, i) => `
      <div class="canal-card" ${estilo(i)}>
        ${c.avatar ? `<img src="${esc(c.avatar)}" alt="" loading="lazy">` : ''}
        <div style="min-width:0">
          <a href="${linkCanal(c)}" target="_blank" rel="noopener"><strong>${esc(c.nome)}</strong></a>
          <span>${c.inscritos !== null ? cmp(c.inscritos) + ' inscritos' : 'inscritos ocultos'}, ${cmp(c.videos_total)} vídeos</span>
        </div>
      </div>`).join('');

    // Tabela: o melhor valor de cada linha fica em negrito
    const linha = (rotulo, valores, fmtFn, maiorMelhor = true, extras = null) => {
      const nums = valores.filter((x) => x !== null && x !== undefined);
      const alvo = maiorMelhor === null || nums.length < 2 ? null : (maiorMelhor ? Math.max(...nums) : Math.min(...nums));
      return `<tr><th scope="row">${rotulo}</th>${valores.map((v, i) =>
        `<td class="${v !== null && v === alvo ? 'destaque' : ''}">${fmtFn(v)}${extras ? `<small>${extras[i]}</small>` : ''}</td>`).join('')}</tr>`;
    };
    const grupo = (t) => `<tr class="grupo"><th scope="rowgroup" colspan="${cs.length + 1}"><span>${t}</span></th></tr>`;
    const m = cs.map((c) => c.metricas);
    const pctFmt = (x) => (x === null || x === undefined ? 'n/d' : dec(x) + '%');
    const tr = (k) => m.map((x) => x.titulos[k].pct);
    const trExtra = (k) => m.map((x) => {
      const t = x.titulos[k];
      return t.mediana_com !== null && t.mediana_sem !== null ? `${cmp(t.mediana_com)} com, ${cmp(t.mediana_sem)} sem` : 'poucos vídeos pra comparar';
    });

    const tabela = `
      <div class="tabela-canais"><table>
        <thead><tr><th scope="col"><span class="visually-hidden">Métrica</span></th>${cs.map((c, i) => `<th scope="col" ${estilo(i)}><i aria-hidden="true"></i>${esc(c.nome)}</th>`).join('')}</tr></thead>
        <tbody>
          ${grupo('Ritmo')}
          ${linha('Vídeos por semana', m.map((x) => x.por_semana), dec)}
          ${linha('Último vídeo', m.map((x) => x.ultimo_dias), (d) => (d === null ? 'n/d' : d === 0 ? 'hoje' : `há ${plural(d, 'dia', 'dias')}`), false)}
          ${grupo('Desempenho típico')}
          ${linha('Mediana de views por vídeo', m.map((x) => x.mediana_views), cmp)}
          ${linha('Mediana sobre inscritos', m.map((x) => x.views_por_inscrito), pctFmt)}
          ${linha('Curtidas a cada mil views', m.map((x) => x.likes_por_mil), dec)}
          ${linha('Comentários a cada mil views', m.map((x) => x.comentarios_por_mil), dec)}
          ${grupo('Formatos, entre os vídeos analisados')}
          ${linha('Vídeos curtos', m.map((x) => x.curtos.qtd), (v) => v, null, m.map((x) => `mediana ${cmp(x.curtos.mediana)}`))}
          ${linha('Vídeos longos', m.map((x) => x.longos.qtd), (v) => v, null, m.map((x) => `mediana ${cmp(x.longos.mediana)}`))}
          ${grupo('Títulos (mediana de views com e sem o recurso)')}
          ${linha('Tamanho típico', m.map((x) => x.titulos.comprimento), (v) => (v === null ? 'n/d' : `${Math.round(v)} caracteres`), null)}
          ${linha('Com número', tr('numero'), pctFmt, null, trExtra('numero'))}
          ${linha('Com pergunta', tr('pergunta'), pctFmt, null, trExtra('pergunta'))}
          ${linha('Com emoji', tr('emoji'), pctFmt, null, trExtra('emoji'))}
          ${linha('Com palavra em caixa alta', tr('caixa_alta'), pctFmt, null, trExtra('caixa_alta'))}
        </tbody>
      </table></div>`;

    // Mapas de publicação: uma grade por canal, mesma escala pra todos
    const maxMapa = Math.max(1, ...m.flatMap((x) => x.mapa.flat()));
    const mapas = cs.map((c, i) => {
      const cel = m[i].mapa.map((dia, d) => `<span>${DIAS[d]}</span>` + dia.map((n, f) => {
        const desc = `${DIAS_LONGOS[d]}, ${FAIXAS[f]} às ${f === 5 ? '24h' : FAIXAS[f + 1]}: ${plural(n, 'vídeo', 'vídeos')}`;
        return `<span class="cel" ${n ? `data-n="${n}" style="--a:${(0.18 + 0.82 * (n / maxMapa)).toFixed(2)}"` : ''} title="${desc}" aria-label="${desc}" role="img"></span>`;
      }).join('')).join('');
      return `<div class="mapa" ${estilo(i)}><h3><i aria-hidden="true"></i>${esc(c.nome)}</h3>
        <div class="mapa-grade"><span></span>${FAIXAS.map((f) => `<span class="col">${f}</span>`).join('')}${cel}</div></div>`;
    }).join('');

    // Vídeos fora da curva
    const fora = cs.map((c, i) => {
      const itens = m[i].fora_da_curva;
      const lista = itens.length
        ? `<ul>${itens.map((v) => `<li>
            <a href="https://www.youtube.com/watch?v=${esc(v.id)}" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/${esc(v.id)}/mqdefault.jpg" alt="" loading="lazy"></a>
            <div><p>${esc(v.titulo)}</p>
              <p class="num"><b>${dec(v.multiplo)}×</b> a mediana, ${cmp(v.views)} views${v.curto ? ', curto' : ''}</p>
              <button type="button" data-analisar="${esc(v.id)}">Analisar os comentários</button></div></li>`).join('')}</ul>`
        : `<p class="vazio">Nenhum vídeo recente com 3 vezes a mediana. O desempenho do canal está uniforme.</p>`;
      return `<div ${estilo(i)}><h3><i aria-hidden="true"></i>${esc(c.nome)}</h3>${lista}</div>`;
    }).join('');

    // Leitura do modelo
    let leitura = '';
    if (lt) {
      const pil = cs.map((c, i) => {
        const x = lt.canais[c.id];
        if (!x) return '';
        const itens = x.pilares.map((p) => `<li>${esc(p.pilar)}<span>${plural(p.videos, 'vídeo', 'vídeos')}, mediana de ${cmp(p.mediana_views)} views</span></li>`).join('');
        return `<div ${estilo(i)}><h3><i aria-hidden="true"></i>${esc(c.nome)}</h3>${itens ? `<ul>${itens}</ul>` : ''}${x.estilo_titulos ? `<p class="estilo">${esc(x.estilo_titulos)}</p>` : ''}</div>`;
      }).join('');
      const lista = (t, xs) => (xs.length ? `<section><h3>${t}</h3><ul>${xs.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></section>` : '');
      leitura = `<section class="bloco leitura">
        <h2>Pilares de conteúdo e lacunas</h2>
        <p class="sintese">${esc(lt.sintese)}</p>
        <div class="pilares">${pil}</div>
        <div class="listas-leitura">${lista('O que um faz e outro não', lt.lacunas)}${lista('O que testar', lt.oportunidades)}</div>
        <p class="nota" style="margin-top:1.25rem">Leitura dos títulos feita pelo modelo ${esc(lt.modelo || '')}. Contagem de vídeos e medianas calculadas no servidor.</p>
      </section>`;
    }

    const alturaBarras = cs.length * 44 + 40;
    el.innerHTML = `
      <div class="cmp-canais">
        <div class="canais-topo">${topo}</div>

        <section class="bloco">
          <h2>Lado a lado</h2>
          <p class="nota">Baseado nos 30 vídeos mais recentes de cada canal. O melhor valor de cada linha está em negrito.</p>
          <div class="graficos-canais">
            <figure><figcaption>Mediana de views por vídeo</figcaption><div class="cv" style="height:${alturaBarras}px"><canvas role="img" aria-label="Mediana de views por canal; os valores estão na tabela abaixo"></canvas></div></figure>
            <figure><figcaption>Curtidas a cada mil views</figcaption><div class="cv" style="height:${alturaBarras}px"><canvas role="img" aria-label="Curtidas por mil views por canal; os valores estão na tabela abaixo"></canvas></div></figure>
            <figure><figcaption>Vídeos por semana</figcaption><div class="cv" style="height:${alturaBarras}px"><canvas role="img" aria-label="Vídeos por semana por canal; os valores estão na tabela abaixo"></canvas></div></figure>
          </div>
          ${tabela}
        </section>

        ${leitura}

        <section class="bloco">
          <h2>Vídeos fora da curva</h2>
          <p class="nota">Vídeos recentes com 3 vezes ou mais a mediana de views do próprio canal. Costumam ser a melhor pista do que o público quer mais.</p>
          <div class="fora">${fora}</div>
        </section>

        <section class="bloco">
          <h2>Quando cada canal publica</h2>
          <p class="nota">Dia da semana e faixa de horário de Brasília dos 30 vídeos mais recentes. Quanto mais escuro, mais vídeos. Passe o mouse para ver o número.</p>
          <div class="mapas">${mapas}</div>
        </section>
      </div>`;

    // Gráficos: uma medida por gráfico, o nome do canal no eixo e a cor como reforço
    const nomes = cs.map((c) => curto(c.nome, 16));
    const cores = cs.map((_, i) => corCanalHex(i));
    const barra = (canvas, valores, fmtFn) => {
      const g = novoGrafico(canvas, {
        type: 'bar',
        data: { labels: nomes, datasets: [{ data: valores.map((v) => v ?? 0), backgroundColor: cores, borderRadius: 4, borderSkipped: 'start', barThickness: 22 }] },
        options: {
          indexAxis: 'y',
          scales: { x: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' }, ticks: { callback: (v) => fmtFn(v) } }, y: { grid: { display: false } } },
          plugins: { tooltip: { callbacks: { title: (it) => cs[it[0].dataIndex].nome, label: (ctx) => ' ' + (valores[ctx.dataIndex] === null ? 'sem dado' : fmtFn(ctx.parsed.x)) } } },
        },
      });
      if (g) graficosCanais.push(g);
    };
    const [cv1, cv2, cv3] = el.querySelectorAll('.graficos-canais canvas');
    barra(cv1, m.map((x) => x.mediana_views), cmp);
    barra(cv2, m.map((x) => x.likes_por_mil), dec);
    barra(cv3, m.map((x) => x.por_semana), dec);

    // Botão de vídeo fora da curva abre a análise de comentários na aba Vídeos
    el.querySelectorAll('[data-analisar]').forEach((b) => b.addEventListener('click', () => {
      campo.value = 'https://www.youtube.com/watch?v=' + b.dataset.analisar;
      location.hash = '#videos';
      window.scrollTo(0, 0);
      form.requestSubmit();
    }));
  }

  /* ---------- abas ---------- */

  const ABAS = ['videos', 'canais', 'radar'];
  let radarCarregado = false;

  function abrirAba() {
    const atual = ABAS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'videos';
    document.querySelectorAll('[data-painel]').forEach((el) => { el.hidden = el.dataset.painel !== atual; });
    document.querySelectorAll('.abas a').forEach((a) => {
      if (a.dataset.aba === atual) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
    if (atual === 'radar' && !radarCarregado) {
      radarCarregado = true;
      carregarColeta();
      carregarRadar(null);
    }
  }

  window.addEventListener('hashchange', abrirAba);

  /* ---------- radar: painel de tendências ---------- */

  let graficoEvolucao = null;
  let nichoAtual = null;

  const varTexto = (v) => {
    if (v === null || v === undefined) return '<span class="var">–</span>';
    const sinal = v > 0 ? '+' : '';
    const classe = v > 0 ? 'sobe' : v < 0 ? 'desce' : '';
    const seta = v > 0 ? '▲' : v < 0 ? '▼' : '';
    return `<span class="var ${classe}"><span aria-hidden="true">${seta}</span> ${sinal}${dec(v)}%</span>`;
  };
  const diaCurto = (d) => new Date(d + 'T12:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });

  async function carregarRadar(slug) {
    const alvo = $('#radar-nicho');
    alvo.setAttribute('aria-busy', 'true');
    let r;
    try {
      r = await chamar('radar.php' + (slug ? '?nicho=' + encodeURIComponent(slug) : ''));
    } catch (err) {
      alvo.innerHTML = `<p class="coleta-resumo">Não foi possível carregar o radar: ${esc(err.message)}</p>`;
      alvo.removeAttribute('aria-busy');
      return;
    }
    nichoAtual = r.nicho ? r.nicho.slug : null;

    $('#radar-nichos').innerHTML = r.visao.map((v) => `
      <button type="button" data-nicho="${esc(v.slug)}" aria-pressed="${v.slug === nichoAtual}" ${v.views === null ? 'disabled' : ''}>
        <strong>${esc(v.nome)}</strong>
        <span class="info">${v.views === null ? 'aguardando coleta' : `${cmp(v.views)} views ${v.variacao !== null ? varTexto(v.variacao) : ''}`}</span>
      </button>`).join('');
    $('#radar-nichos').querySelectorAll('button[data-nicho]').forEach((b) =>
      b.addEventListener('click', () => { if (b.dataset.nicho !== nichoAtual) carregarRadar(b.dataset.nicho); }));

    renderNicho(r.nicho);
    alvo.removeAttribute('aria-busy');
  }

  function renderNicho(n) {
    const alvo = $('#radar-nicho');
    if (graficoEvolucao) { graficoEvolucao.destroy(); graficoEvolucao = null; }
    if (!n) {
      alvo.innerHTML = '<section class="bloco"><h2>Nenhum nicho coletado ainda</h2><p class="nota">Assim que o agendamento rodar pela primeira vez, os dados aparecem aqui.</p></section>';
      return;
    }

    const temComparacao = !!n.dia_anterior;
    const faltam = Math.max(1, 8 - n.dias);
    const periodo = temComparacao
      ? `Os 50 vídeos mais vistos publicados na semana até ${diaCurto(n.dia)}, comparados com a semana até ${diaCurto(n.dia_anterior)}.`
      : `Os 50 vídeos mais vistos publicados na semana até ${diaCurto(n.dia)}. A comparação com a semana anterior aparece em ${plural(faltam, 'dia', 'dias')}.`;

    const maxViews = Math.max(1, ...n.temas.map((t) => t.views));
    const assuntos = n.temas.length
      ? `<ul class="assuntos">${n.temas.map((t) => `
          <li>
            <div class="assunto-topo"><strong>${esc(t.tema)}</strong><span>${cmp(t.views)} views, ${plural(t.qtd, 'vídeo', 'vídeos')}</span></div>
            <div class="assunto-barra" aria-hidden="true"><i style="width:${Math.max(2, (t.views / maxViews) * 100).toFixed(1)}%"></i></div>
            ${t.descricao ? `<p>${esc(t.descricao)}</p>` : ''}
            ${t.videos.length ? `<details><summary>Ver os vídeos</summary><ul>${t.videos.map((v) =>
              `<li><a href="https://www.youtube.com/watch?v=${esc(v.id)}" target="_blank" rel="noopener">${esc(v.titulo)}</a>, ${esc(v.canal)}, ${cmp(v.views)} views</li>`).join('')}</ul></details>` : ''}
          </li>`).join('')}</ul>`
      : '<p class="nota">O modelo não agrupou os assuntos nesta coleta. As hashtags e os vídeos ao lado continuam valendo.</p>';

    const termos = n.termos.length
      ? `<table class="termos">
          <thead><tr><th scope="col">Hashtag ou tag</th><th scope="col">Vídeos</th><th scope="col">Views</th><th scope="col">${temComparacao ? 'Contra a semana anterior' : 'Variação'}</th></tr></thead>
          <tbody>${n.termos.map((t) => `<tr>
            <td class="${t.tipo === 'tag' ? 'tag' : ''}">${esc(t.exibicao)}</td>
            <td>${t.videos}</td>
            <td>${cmp(t.views)}</td>
            <td>${t.nova ? '<span class="selo">nova</span>' : varTexto(t.variacao)}</td></tr>`).join('')}</tbody>
        </table>`
      : '<p class="nota">Poucas hashtags repetidas entre os vídeos desta semana.</p>';

    const temEvolucao = n.evolucao.dias.length >= 3 && n.evolucao.series.length;
    const evolucao = temEvolucao
      ? `<figure style="margin:0"><figcaption class="visually-hidden">Views diárias das principais hashtags</figcaption>
          <div class="cv" style="height:300px"><canvas role="img" aria-label="Evolução diária das views de ${esc(n.evolucao.series.map((x) => x.exibicao).join(', '))}"></canvas></div></figure>`
      : `<p class="aviso-dados">O gráfico aparece a partir do terceiro dia de coleta. Este nicho tem ${plural(n.dias, 'dia', 'dias')} por enquanto.</p>`;

    const top = n.top.map((v, i) => `<li>
        <span class="pos">${i + 1}</span>
        <a href="https://www.youtube.com/watch?v=${esc(v.id)}" target="_blank" rel="noopener"><img src="https://i.ytimg.com/vi/${esc(v.id)}/mqdefault.jpg" alt="" loading="lazy"></a>
        <div><p>${esc(v.titulo)}</p><p class="num">${esc(v.canal)}, ${cmp(v.views)} views${v.duracao_seg !== null && v.duracao_seg <= 180 ? ', curto' : ''}</p>
        <button type="button" data-analisar="${esc(v.id)}">Analisar os comentários</button></div></li>`).join('');

    alvo.innerHTML = `
      <section class="bloco">
        <h2>${esc(n.nome)}</h2>
        <p class="nota">${periodo}</p>
        ${n.resumo ? `<p class="sintese" style="margin-top:.9rem;max-width:68ch;color:var(--ink-2);font-size:1.0625rem">${esc(n.resumo)}</p>` : ''}
        <div class="radar-grade">
          <section><h3>Assuntos da semana</h3>${assuntos}</section>
          <section><h3>Hashtags e tags mais usadas</h3>${termos}
            <p class="nota" style="margin-top:.6rem">Views somadas dos vídeos da semana que usam cada termo.</p></section>
        </div>
        <section class="evolucao"><h3>Evolução diária das hashtags principais</h3>${evolucao}</section>
      </section>

      <section class="bloco">
        <h2>Vídeos mais vistos da semana</h2>
        <ol class="top-videos">${top}</ol>
      </section>
      <p class="nota" style="margin-top:.75rem;font-size:.8125rem;color:var(--muted)">Coleta de ${diaCurto(n.dia)}, com assuntos agrupados pelo modelo ${esc(n.modelo || '')}. Contagens e somas feitas no servidor.</p>`;

    if (temEvolucao) {
      const cores = n.evolucao.series.map((_, i) => corCanalHex(i));
      graficoEvolucao = novoGrafico(alvo.querySelector('.evolucao canvas'), {
        type: 'line',
        data: {
          labels: n.evolucao.dias.map((d) => diaCurto(d)),
          datasets: n.evolucao.series.map((x, i) => ({
            label: x.exibicao, data: x.valores, borderColor: cores[i], backgroundColor: cores[i],
            borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25,
          })),
        },
        options: {
          interaction: { mode: 'index', intersect: false },
          scales: {
            y: { beginAtZero: true, ticks: { callback: (v) => cmp(v) }, grid: { color: 'rgba(128,128,128,.15)' } },
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
          },
          plugins: {
            legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } },
            tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${cmp(ctx.parsed.y)} views` } },
          },
        },
      });
    }

    alvo.querySelectorAll('[data-analisar]').forEach((b) => b.addEventListener('click', () => {
      campo.value = 'https://www.youtube.com/watch?v=' + b.dataset.analisar;
      location.hash = '#videos';
      window.scrollTo(0, 0);
      form.requestSubmit();
    }));
  }

  /* ---------- radar: andamento da coleta ---------- */

  async function carregarColeta() {
    const resumo = $('#coleta-resumo');
    let s;
    try {
      s = await chamar('radar_status.php');
    } catch (err) {
      resumo.textContent = 'Não foi possível ler o andamento da coleta: ' + err.message;
      radarCarregado = false;
      return;
    }

    const dataBR = (d) => (d ? new Date(d + 'T12:00:00').toLocaleDateString('pt-BR', { day: 'numeric', month: 'short' }) : '');
    const faltam = Math.max(0, s.dias_meta - s.dias);

    if (!s.dias) {
      resumo.textContent = 'A primeira coleta ainda não aconteceu. Assim que o agendamento rodar, os nichos aparecem aqui.';
    } else if (faltam) {
      resumo.textContent = `Coletando desde ${dataBR(s.inicio)}: ${plural(s.dias, 'dia', 'dias')} de histórico, ${nInt.format(s.videos_unicos)} vídeos diferentes e ${nInt.format(s.retratos)} retratos diários. `
        + `Faltam ${plural(faltam, 'dia', 'dias')} pra ter duas semanas completas e comparar uma com a outra.`;
    } else {
      resumo.textContent = `${plural(s.dias, 'dia', 'dias')} de histórico desde ${dataBR(s.inicio)}, com ${nInt.format(s.videos_unicos)} vídeos diferentes. Já dá pra comparar semanas.`;
    }
    $('#coleta-barra').style.width = Math.min(100, (s.dias / s.dias_meta) * 100) + '%';

    $('#coleta-nichos').innerHTML = s.nichos.map((n) => {
      const ultima = n.ultimo_dia
        ? dataBR(n.ultimo_dia)
        : `<span class="${n.status_hoje === 'erro' ? 'falhou' : 'pendente'}">${n.status_hoje === 'erro' ? 'falhou, nova tentativa em breve' : 'aguardando'}</span>`;
      return `<tr><td>${esc(n.nome)}</td><td>${n.dias_ok}</td><td>${ultima}</td><td>${n.ultimo_videos ?? '–'}</td></tr>`;
    }).join('');
  }

  abrirAba();
  if (window.Chart) configurarChart();
})();
