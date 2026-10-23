-- =====================================================================
-- Aparelhos do app Android/iOS que recebem avisos no celular (push).
-- Cada registro fica preso à SESSÃO que o criou: ao sair da conta, ao
-- encerrar sessões pelo painel ou ao excluir a conta, o aparelho deixa de
-- receber avisos (dados de saúde não aparecem para quem já saiu).
-- O token do Firebase identifica o app no aparelho, não a pessoa.
-- =====================================================================
CREATE TABLE IF NOT EXISTS dispositivos (
  id             CHAR(26)     NOT NULL,
  usuario_id     CHAR(26)     NOT NULL,
  sessao_id      CHAR(26)     NOT NULL,
  plataforma     VARCHAR(10)  NOT NULL COMMENT 'android | ios',
  token          VARCHAR(255) NOT NULL COMMENT 'Token de push do Firebase (FCM)',
  criado_em      DATETIME     NOT NULL,
  atualizado_em  DATETIME     NOT NULL,
  ultimo_envio_em DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dispositivos_token (token),
  KEY ix_dispositivos_usuario (usuario_id),
  KEY ix_dispositivos_sessao (sessao_id),
  CONSTRAINT fk_aparelho_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
