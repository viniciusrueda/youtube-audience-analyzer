<?php
declare(strict_types=1);

// Credenciais: mesmo padrão do db.php do portfólio.
// Em produção vêm do ambiente; os valores depois do ?: são só placeholders pro repositório público.
define('YT_API_KEY',        getenv('YOUTUBE_API_KEY')   ?: 'SUA_CHAVE_YOUTUBE');
define('GROQ_API_KEY',      getenv('GROQ_API_KEY')      ?: 'SUA_CHAVE_GROQ');
define('GEMINI_API_KEY',    getenv('GEMINI_API_KEY')    ?: 'SUA_CHAVE_GEMINI');
// Ordem de tentativa: o primeiro que responder vence. Todos rodam em camadas gratuitas.
// Groq é o principal (resposta em poucos segundos); os dois Gemini são reserva.
// Formato "provedor:modelo". Pra mudar sem editar código, defina YTA_LLM_CADEIA separando por vírgula.
define('LLM_CADEIA', array_map('trim', explode(',', getenv('YTA_LLM_CADEIA') ?: implode(',', [
    'groq:openai/gpt-oss-120b',
    'gemini:gemini-flash-lite-latest',
    'gemini:gemini-flash-latest',
]))));
define('LLM_MODEL', LLM_CADEIA[0]); // só pra exibição

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'SEU_BANCO');
define('DB_USER', getenv('DB_USER') ?: 'SEU_USUARIO');
define('DB_PASS', getenv('DB_PASS') ?: 'SUA_SENHA');

// Salt pra guardar o IP só como hash (nada de IP puro no banco).
define('IP_SALT', getenv('YTA_IP_SALT') ?: 'troque-este-salt');

// Limites da entrada
define('MAX_LINKS', 5);        // itens colados por vez
define('MAX_VIDEOS', 5);       // vídeos analisados por execução, depois de expandir canais
define('CANAL_VIDEOS', 5);     // quantos vídeos recentes um link de canal traz

// Limites do que vai pro modelo
define('MAX_COMENTARIOS', 60);        // cabe no limite de 8 mil tokens/minuto do Groq grátis
define('COMENTARIO_MAX_CHARS', 200);

// Cache e proteção da cota gratuita
define('CACHE_TTL_HORAS', 72);    // cache mais longo = menos chamadas à cota grátis
define('LIMITE_IP_DIA', 15);      // chamadas novas ao modelo por visitante em 24h (cache não conta)
define('LIMITE_GLOBAL_DIA', 200); // mantenha abaixo do limite diário (RPD) que o AI Studio mostra pro seu projeto

// Radar de tendências
define('CRON_TOKEN', getenv('YTA_CRON_TOKEN') ?: '');  // sem token definido, o endpoint do cron fica desligado
define('RADAR_VIDEOS', 50);        // vídeos mais vistos por nicho em cada coleta (1 busca = 100 unidades de cota)
define('RADAR_JANELA_DIAS', 7);    // considera vídeos publicados nos últimos N dias
define('RADAR_TENTATIVAS', 3);     // tentativas por nicho por dia antes de desistir
define('RADAR_FUSO', 'America/Sao_Paulo');

// Comparador de canais
define('CANAIS_MIN', 2);
define('CANAIS_MAX', 4);
define('CANAIS_VIDEOS', 30);        // vídeos recentes analisados por canal
define('CANAIS_CACHE_HORAS', 12);   // dados de canal mudam mais rápido que análise de comentários
define('CURTO_MAX_SEG', 180);       // até 3 minutos conta como vídeo curto (Shorts)
