-- =====================================================================
-- Pedidos de vínculo entre empresa e pessoa cuidada, nos dois sentidos:
--   origem = 'gestor'  → o(a) gestor(a) pede a uma empresa para acompanhar uma
--                        pessoa e já diz o que ela pode ver; a empresa aceita.
--   origem = 'empresa' → a empresa pede ao(à) gestor(a) (achado pelo celular ou
--                        e-mail) e diz o que gostaria de ver; o(a) gestor(a)
--                        aceita, escolhe a pessoa e decide o que a empresa vê.
-- Só o aceite preenche pessoas_cuidadas.empresa_id / empresa_permissoes.
-- chave_pendente (única, só enquanto pendente) evita pedidos repetidos sem gatilho.
-- =====================================================================
CREATE TABLE IF NOT EXISTS pedidos_cliente (
  id              CHAR(26)     NOT NULL,
  empresa_id      CHAR(26)     NOT NULL,
  gestor_id       CHAR(26)     NOT NULL COMMENT 'Gestor(a) que pediu ou a quem a empresa pediu',
  pessoa_id       CHAR(26)     NULL COMMENT 'Definida pelo(a) gestor(a): ao pedir (origem gestor) ou ao aceitar (origem empresa)',
  origem          VARCHAR(10)  NOT NULL COMMENT 'gestor | empresa',
  permissoes      JSON         NOT NULL COMMENT 'Origem gestor: o que libera. Origem empresa: o que a empresa gostaria de ver',
  mensagem        VARCHAR(200) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'pendente' COMMENT 'pendente | aceito | recusado | cancelado | expirado',
  chave_pendente  VARCHAR(60)  NULL,
  criado_por      CHAR(26)     NOT NULL,
  criado_em       DATETIME     NOT NULL,
  expira_em       DATETIME     NOT NULL,
  respondido_por  CHAR(26)     NULL,
  respondido_em   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedido_cliente_pendente (chave_pendente),
  KEY ix_pc_empresa (empresa_id, status),
  KEY ix_pc_gestor (gestor_id, status),
  KEY ix_pc_pessoa (pessoa_id),
  CONSTRAINT fk_pc_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_pc_gestor FOREIGN KEY (gestor_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
