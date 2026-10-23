-- =====================================================================
-- Pedidos para entrar na rede de uma empresa, nos dois sentidos:
--   origem = 'empresa'  → a empresa pede; o(a) cuidador(a) aceita no app dele(a)
--   origem = 'cuidador' → o(a) cuidador(a) pede; a empresa aceita no painel
-- Só o aceite do outro lado cria (ou reativa) a linha em empresa_cuidadores.
-- Sem gatilhos: "um pedido pendente por empresa + cuidador(a)" é garantido pela
-- coluna chave_pendente (preenchida só enquanto pendente) com índice único.
-- =====================================================================
CREATE TABLE IF NOT EXISTS pedidos_rede (
  id              CHAR(26)     NOT NULL,
  empresa_id      CHAR(26)     NOT NULL,
  usuario_id      CHAR(26)     NOT NULL COMMENT 'O(a) cuidador(a)',
  origem          VARCHAR(10)  NOT NULL COMMENT 'empresa | cuidador',
  modalidade      VARCHAR(12)  NOT NULL COMMENT 'mei | autonomo | clt (clt só quando a empresa pede)',
  mensagem        VARCHAR(200) NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'pendente' COMMENT 'pendente | aceito | recusado | cancelado | expirado',
  chave_pendente  CHAR(53)     NULL COMMENT 'empresa_id:usuario_id enquanto pendente; NULL depois',
  criado_por      CHAR(26)     NOT NULL,
  criado_em       DATETIME     NOT NULL,
  expira_em       DATETIME     NOT NULL,
  respondido_por  CHAR(26)     NULL,
  respondido_em   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pedido_pendente (chave_pendente),
  KEY ix_pedidos_usuario (usuario_id, status),
  KEY ix_pedidos_empresa (empresa_id, status),
  CONSTRAINT fk_pedidos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
