-- =====================================================================
-- Painel de administração (cuidados.ahvita.com/admin) e log de acessos.
-- Sem gatilhos e sem colunas geradas (limites do MariaDB da KingHost).
-- =====================================================================

-- Equipe Ahvita que opera o painel. Entra só com Google (e-mail confirmado).
-- Os e-mails em admin.donos (configuracao.php) são sempre "dono" e não podem
-- ser removidos pela tela: a configuração do servidor é a raiz de confiança.
CREATE TABLE IF NOT EXISTS admin_usuarios (
  id                CHAR(26)     NOT NULL,
  email             VARCHAR(190) NOT NULL,
  nome              VARCHAR(120) NULL,
  google_sub        VARCHAR(64)  NULL COMMENT 'Preenchido no primeiro acesso; depois só esta conta Google entra',
  papel             VARCHAR(12)  NOT NULL DEFAULT 'leitura' COMMENT 'dono | operador | leitura',
  ativo             TINYINT(1)   NOT NULL DEFAULT 1,
  criado_por        CHAR(26)     NULL,
  criado_em         DATETIME     NOT NULL,
  atualizado_em     DATETIME     NOT NULL,
  ultimo_acesso_em  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email),
  UNIQUE KEY uq_admin_google (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessões do painel: separadas das sessões do app, curtas (8 h, 30 min parado).
CREATE TABLE IF NOT EXISTS admin_sessoes (
  id             CHAR(26)     NOT NULL,
  admin_id       CHAR(26)     NOT NULL,
  token_hash     CHAR(64)     NOT NULL,
  ip             VARCHAR(45)  NULL,
  dispositivo    VARCHAR(160) NULL,
  criado_em      DATETIME     NOT NULL,
  ultimo_uso_em  DATETIME     NOT NULL,
  expira_em      DATETIME     NOT NULL,
  revogada_em    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_sessoes_token (token_hash),
  KEY ix_admin_sessoes_admin (admin_id),
  CONSTRAINT fk_admin_sessoes_admin FOREIGN KEY (admin_id) REFERENCES admin_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de acessos e usos (todos os perfis + equipe do painel).
-- Uma linha por: entrada/saída, toda escrita, todo erro, toda ação do painel
-- e leituras sensíveis (exportações). Leituras de rotina (a conversa consulta a
-- cada 5 s) vão somadas por dia em uso_diario, para não estourar o banco.
-- Nunca guarda corpo da requisição, parâmetros de busca nem dado de saúde.
-- Guarda IP e data/hora por pelo menos 6 meses (Marco Civil, art. 15).
CREATE TABLE IF NOT EXISTS log_acessos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  criado_em    DATETIME     NOT NULL COMMENT 'UTC',
  ator         VARCHAR(8)   NOT NULL COMMENT 'usuario | admin | anonimo',
  usuario_id   CHAR(26)     NULL,
  admin_id     CHAR(26)     NULL,
  sessao_id    CHAR(26)     NULL,
  perfil       VARCHAR(20)  NULL COMMENT 'gestor | cuidador | rede | profissional | empresa | admin (no contexto da requisição)',
  evento       VARCHAR(40)  NOT NULL COMMENT 'requisicao | entrou | entrada_recusada | saiu | admin.entrou…',
  metodo       VARCHAR(7)   NULL,
  rota         VARCHAR(80)  NULL COMMENT 'Padrão da rota, ex.: /pessoas/{id}/registros',
  recurso_id   VARCHAR(40)  NULL COMMENT 'O {id} da rota',
  pessoa_id    CHAR(26)     NULL,
  empresa_id   CHAR(26)     NULL,
  status       SMALLINT UNSIGNED NULL,
  duracao_ms   INT UNSIGNED NULL,
  ip           VARCHAR(45)  NULL,
  cliente      VARCHAR(8)   NULL COMMENT 'web | app',
  dispositivo  VARCHAR(160) NULL,
  PRIMARY KEY (id),
  KEY ix_log_data (criado_em),
  KEY ix_log_usuario (usuario_id, criado_em),
  KEY ix_log_admin (admin_id, criado_em),
  KEY ix_log_pessoa (pessoa_id, criado_em),
  KEY ix_log_ip (ip, criado_em),
  KEY ix_log_evento (evento, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leituras de rotina somadas por dia (sem horário: não serve para medir tempo de ninguém).
CREATE TABLE IF NOT EXISTS uso_diario (
  dia          DATE         NOT NULL COMMENT 'Dia em America/Sao_Paulo',
  usuario_id   CHAR(26)     NOT NULL,
  perfil       VARCHAR(20)  NOT NULL DEFAULT '',
  rota         VARCHAR(80)  NOT NULL,
  contexto_id  CHAR(26)     NOT NULL DEFAULT '' COMMENT 'Pessoa cuidada ou empresa acessada',
  requisicoes  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, usuario_id, perfil, rota, contexto_id),
  KEY ix_uso_usuario (usuario_id, dia),
  KEY ix_uso_contexto (contexto_id, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueio de conta pelo painel (segurança/abuso). Bloqueada = não entra e as sessões caem.
ALTER TABLE usuarios
  ADD COLUMN bloqueado_em DATETIME NULL AFTER excluido_em,
  ADD COLUMN bloqueio_motivo VARCHAR(160) NULL AFTER bloqueado_em;
