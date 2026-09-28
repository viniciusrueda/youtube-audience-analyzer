-- Rodar uma vez no phpMyAdmin do Hostinger, no mesmo banco do portfólio.

CREATE TABLE IF NOT EXISTS yta_cache (
  chave      VARCHAR(80)  NOT NULL PRIMARY KEY,   -- v:<id>, ch:<hash>, cmp:<hash>
  payload    MEDIUMTEXT   NOT NULL,               -- JSON pronto pra devolver ao front
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yta_uso (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip_hash        CHAR(64)    NOT NULL,           -- sha256(salt + IP), nunca o IP puro
  acao           VARCHAR(20) NOT NULL,           -- video | comparacao
  tokens_entrada INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_saida   INT UNSIGNED NOT NULL DEFAULT 0,
  criado_em      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip (ip_hash, criado_em),
  INDEX idx_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consulta útil pra acompanhar gasto:
-- SELECT DATE(criado_em) dia, acao, COUNT(*) chamadas,
--        SUM(tokens_entrada) tokens_in, SUM(tokens_saida) tokens_out
--   FROM yta_uso GROUP BY dia, acao ORDER BY dia DESC;

-- Limpeza opcional de cache vencido:
-- DELETE FROM yta_cache WHERE criado_em < NOW() - INTERVAL 7 DAY;
