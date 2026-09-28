-- Etapa 5: registro de consumo pro painel interno. Rodar uma vez no phpMyAdmin.
SET NAMES utf8mb4;

-- Cota do YouTube por dia. O dia segue o fuso do Pacífico, porque é quando o Google zera a cota.
CREATE TABLE IF NOT EXISTS yta_cota (
  dia           DATE NOT NULL PRIMARY KEY,
  unidades      INT UNSIGNED NOT NULL DEFAULT 0,
  chamadas      INT UNSIGNED NOT NULL DEFAULT 0,
  acertos_cache INT UNSIGNED NOT NULL DEFAULT 0   -- respostas servidas do cache, sem chamar nenhuma API
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qual modelo respondeu cada chamada (o principal ou o reserva)
ALTER TABLE yta_uso ADD COLUMN IF NOT EXISTS modelo VARCHAR(60) NULL AFTER acao;
