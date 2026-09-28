-- Etapa 2: tabelas do radar de tendências. Rodar uma vez no phpMyAdmin, no mesmo banco.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS yta_radar_nicho (
  id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug     VARCHAR(40)  NOT NULL UNIQUE,
  nome     VARCHAR(80)  NOT NULL,
  consulta VARCHAR(250) NOT NULL,          -- termos da busca; | funciona como OU na API do YouTube
  ativo    TINYINT(1)   NOT NULL DEFAULT 1,
  ordem    SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha por nicho por dia. status: pendente -> ok | erro
CREATE TABLE IF NOT EXISTS yta_radar_coleta (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nicho_id    SMALLINT UNSIGNED NOT NULL,
  dia         DATE        NOT NULL,
  status      VARCHAR(10) NOT NULL DEFAULT 'pendente',
  tentativas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  videos      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  resumo      TEXT NULL,                  -- frase do modelo sobre a semana do nicho
  temas_json  MEDIUMTEXT NULL,            -- temas agrupados pelo modelo, já validados
  modelo      VARCHAR(60) NULL,
  erro        VARCHAR(300) NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nicho_dia (nicho_id, dia),
  INDEX idx_dia (dia),
  CONSTRAINT fk_coleta_nicho FOREIGN KEY (nicho_id) REFERENCES yta_radar_nicho (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retrato de cada vídeo no dia da coleta (o mesmo vídeo aparece em dias seguidos com views novas)
CREATE TABLE IF NOT EXISTS yta_radar_video (
  coleta_id    INT UNSIGNED NOT NULL,
  video_id     CHAR(11)     NOT NULL,
  posicao      TINYINT UNSIGNED NOT NULL,   -- ordem por views dentro da coleta
  titulo       VARCHAR(200) NOT NULL,
  canal        VARCHAR(120) NOT NULL,
  canal_id     VARCHAR(40)  NOT NULL,
  publicado_em DATETIME     NOT NULL,
  duracao_seg  INT UNSIGNED NULL,
  views        BIGINT UNSIGNED NULL,
  likes        BIGINT UNSIGNED NULL,
  comentarios  BIGINT UNSIGNED NULL,
  PRIMARY KEY (coleta_id, video_id),
  INDEX idx_video (video_id),
  CONSTRAINT fk_video_coleta FOREIGN KEY (coleta_id) REFERENCES yta_radar_coleta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hashtags e tags agregadas por coleta: base do "o que cresceu na semana"
CREATE TABLE IF NOT EXISTS yta_radar_termo (
  coleta_id INT UNSIGNED NOT NULL,
  tipo      VARCHAR(8)   NOT NULL,           -- hashtag | tag
  termo     VARCHAR(100) NOT NULL,           -- normalizado: minúsculo, sem acento, sem #
  exibicao  VARCHAR(100) NOT NULL,           -- como apareceu mais vezes
  videos    SMALLINT UNSIGNED NOT NULL,
  views     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (coleta_id, tipo, termo),
  INDEX idx_termo (tipo, termo),
  CONSTRAINT fk_termo_coleta FOREIGN KEY (coleta_id) REFERENCES yta_radar_coleta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO yta_radar_nicho (slug, nome, consulta, ordem) VALUES
('financas',   'Finanças pessoais',                'finanças pessoais|investimentos|educação financeira', 1),
('tecnologia', 'Tecnologia',                       'tecnologia|review celular|inteligência artificial',   2),
('educacao',   'Educação e concursos',             'concurso público|enem|vestibular|como estudar',       3),
('marketing',  'Marketing digital e empreendedorismo', 'marketing digital|empreendedorismo|vendas online', 4),
('consumo',    'Consumo e compras',                'achadinhos|review produto|unboxing|compras',          5),
('games',      'Games',                            'gameplay|games|jogos',                                6),
('culinaria',  'Culinária',                        'receita|culinária|receita fácil',                     7),
('fitness',    'Fitness e saúde',                  'treino|academia|musculação|corrida',                  8),
('beleza',     'Beleza',                           'maquiagem|skincare|cabelo',                           9),
('carros',     'Carros',                           'carros|avaliação carro|automóveis',                   10);

-- Acompanhar a coleta:
-- SELECT c.dia, n.nome, c.status, c.tentativas, c.videos, c.modelo, c.erro
--   FROM yta_radar_coleta c JOIN yta_radar_nicho n ON n.id = c.nicho_id ORDER BY c.dia DESC, n.ordem;
