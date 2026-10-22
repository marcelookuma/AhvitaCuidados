-- =====================================================================
-- Ahvita Cuidados — esquema do banco (MVP)
-- Compatível com MySQL 5.7+, MySQL 8 e MariaDB 10.3+ (KingHost).
--
-- Decisões para hospedagem compartilhada:
--   * Sem gatilhos (triggers), procedures ou eventos: o MySQL compartilhado
--     costuma recusar (erro #1419). As regras ficam na API.
--   * Sem colunas geradas: o MariaDB da KingHost recusou (erro #1901).
--   * IDs são ULID (CHAR(26)): ordenáveis por tempo e gerados também no
--     aparelho, o que permite registrar sem internet e reenviar sem duplicar.
--   * Datas em UTC (DATETIME). A API converte para America/Sao_Paulo.
--   * Registros clínicos não são editados nem apagados: correção = novo
--     registro que anula o anterior (campo anulado_por).
--
-- Importar no phpMyAdmin (aba Importar) ou rodar: php api/bin/instalar.php
-- =====================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------
-- Pessoas que usam o app (gestor(a), cuidador(a), Rede de Apoio,
-- profissional, equipe da empresa). Um mesmo usuário pode ter vários papéis.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id              CHAR(26)     NOT NULL,
  nome            VARCHAR(120) NULL,
  apelido         VARCHAR(60)  NULL COMMENT 'Como gosta de ser chamado(a)',
  cpf             CHAR(11)     NULL COMMENT 'Chave de identificação; só dígitos',
  telefone        VARCHAR(20)  NULL COMMENT 'E.164, ex.: +5511999990000',
  email           VARCHAR(190) NULL,
  preferencias    JSON         NULL COMMENT '{"fonte":1-3,"voz":bool,"modo":"conversa"}',
  criado_em       DATETIME     NOT NULL,
  atualizado_em   DATETIME     NOT NULL,
  excluido_em     DATETIME     NULL COMMENT 'Conta excluída (dados anonimizados)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_cpf (cpf),
  UNIQUE KEY uq_usuarios_telefone (telefone),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Código de acesso por WhatsApp, SMS ou e-mail (o código nunca é guardado em claro)
CREATE TABLE IF NOT EXISTS otp_codigos (
  id            CHAR(26)     NOT NULL,
  destino       VARCHAR(190) NOT NULL,
  canal         VARCHAR(12)  NOT NULL COMMENT 'whatsapp | sms | email',
  codigo_hash   CHAR(64)     NOT NULL,
  tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip            VARCHAR(45)  NULL,
  expira_em     DATETIME     NOT NULL,
  usado_em      DATETIME     NULL,
  criado_em     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_otp_destino (destino, criado_em),
  KEY ix_otp_ip (ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessões: token opaco (cookie httpOnly na web, Bearer no app móvel)
CREATE TABLE IF NOT EXISTS sessoes (
  id             CHAR(26)    NOT NULL,
  usuario_id     CHAR(26)    NOT NULL,
  token_hash     CHAR(64)    NOT NULL,
  dispositivo    VARCHAR(160) NULL,
  criado_em      DATETIME    NOT NULL,
  ultimo_uso_em  DATETIME    NOT NULL,
  expira_em      DATETIME    NOT NULL,
  revogada_em    DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessoes_token (token_hash),
  KEY ix_sessoes_usuario (usuario_id),
  CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Empresas de cuidadores(as)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
  id             CHAR(26)     NOT NULL,
  razao_social   VARCHAR(160) NOT NULL,
  nome_fantasia  VARCHAR(120) NULL,
  cnpj           CHAR(14)     NOT NULL,
  telefone       VARCHAR(20)  NULL,
  email          VARCHAR(190) NULL,
  criado_por     CHAR(26)     NOT NULL,
  criado_em      DATETIME     NOT NULL,
  atualizado_em  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_empresas_cnpj (cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipe administrativa da empresa
CREATE TABLE IF NOT EXISTS empresa_membros (
  id          CHAR(26)    NOT NULL,
  empresa_id  CHAR(26)    NOT NULL,
  usuario_id  CHAR(26)    NOT NULL,
  papel       VARCHAR(20) NOT NULL DEFAULT 'coordenacao' COMMENT 'admin | coordenacao',
  ativo       TINYINT(1)  NOT NULL DEFAULT 1,
  criado_em   DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_empresa_membro (empresa_id, usuario_id),
  KEY ix_empresa_membros_usuario (usuario_id),
  CONSTRAINT fk_em_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_em_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuidadores(as) cadastrados(as) na rede da empresa.
-- modalidade decide as regras de agenda: MEI/autônomo => a agenda é do(a) cuidador(a).
CREATE TABLE IF NOT EXISTS empresa_cuidadores (
  id          CHAR(26)    NOT NULL,
  empresa_id  CHAR(26)    NOT NULL,
  usuario_id  CHAR(26)    NOT NULL,
  modalidade  VARCHAR(12) NOT NULL COMMENT 'mei | autonomo | clt',
  status      VARCHAR(12) NOT NULL DEFAULT 'ativo' COMMENT 'ativo | encerrado',
  criado_em   DATETIME    NOT NULL,
  encerrado_em DATETIME   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_empresa_cuidador (empresa_id, usuario_id),
  KEY ix_ec_usuario (usuario_id),
  CONSTRAINT fk_ec_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id),
  CONSTRAINT fk_ec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pessoa cuidada (qualquer idade)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pessoas_cuidadas (
  id                 CHAR(26)     NOT NULL,
  nome_completo      VARCHAR(160) NOT NULL,
  apelido            VARCHAR(60)  NOT NULL COMMENT 'Como gosta de ser chamado(a)',
  nascimento         DATE         NOT NULL,
  sexo               VARCHAR(20)  NOT NULL COMMENT 'feminino | masculino | intersexo | nao_informar',
  genero             VARCHAR(60)  NULL,
  cpf                CHAR(11)     NULL COMMENT 'Opcional',
  arranjo            VARCHAR(30)  NOT NULL COMMENT 'somente_eu | so_cuidador | gestor_cuidador | gestor_rede | gestor_rede_cuidador',
  ficha              JSON         NULL COMMENT 'Ficha de emergência: alergias, condições, convênio, endereço, contatos',
  plano              JSON         NULL COMMENT 'Plano de cuidado: o que a pessoa precisa (não organiza o serviço)',
  empresa_id         CHAR(26)     NULL COMMENT 'Cliente de uma empresa (opcional)',
  empresa_permissoes JSON         NULL COMMENT 'O que a empresa pode ver/fazer, definido pelo(a) gestor(a)',
  criado_por         CHAR(26)     NOT NULL,
  criado_em          DATETIME     NOT NULL,
  atualizado_em      DATETIME     NOT NULL,
  excluido_em        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_pessoas_empresa (empresa_id),
  CONSTRAINT fk_pessoas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem participa do cuidado de cada pessoa, com que papel e quais permissões.
-- Regra "um vínculo ativo por pessoa+usuário" é garantida pela API.
CREATE TABLE IF NOT EXISTS vinculos (
  id                     CHAR(26)    NOT NULL,
  pessoa_id              CHAR(26)    NOT NULL,
  usuario_id             CHAR(26)    NOT NULL,
  papel                  VARCHAR(20) NOT NULL COMMENT 'gestor | cuidador | rede | profissional',
  relacao                VARCHAR(40) NULL COMMENT 'Tutor(a), Filho(a), Vizinho(a)…',
  modalidade             VARCHAR(12) NULL COMMENT 'Cuidador(a): mei | autonomo | clt | empresa | rede',
  empresa_id             CHAR(26)    NULL COMMENT 'Quando o(a) cuidador(a) vem por uma empresa',
  especialidade          VARCHAR(40) NULL COMMENT 'Profissional: enfermagem, fisioterapia…',
  atua_como_cuidador     TINYINT(1)  NOT NULL DEFAULT 0,
  permissoes             JSON        NOT NULL,
  prazo_confirmacao_min  SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Confirmar ida até X min antes (combinado entre as partes)',
  status                 VARCHAR(12) NOT NULL DEFAULT 'ativo' COMMENT 'ativo | encerrado',
  criado_em              DATETIME    NOT NULL,
  encerrado_em           DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_vinculos_pessoa (pessoa_id, status),
  KEY ix_vinculos_usuario (usuario_id, status),
  CONSTRAINT fk_vinculos_pessoa  FOREIGN KEY (pessoa_id)  REFERENCES pessoas_cuidadas (id),
  CONSTRAINT fk_vinculos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Convites por código (enviados por WhatsApp pelo próprio gestor/empresa)
CREATE TABLE IF NOT EXISTS convites (
  id                CHAR(26)    NOT NULL,
  codigo            CHAR(8)     NOT NULL,
  tipo              VARCHAR(20) NOT NULL COMMENT 'vinculo | empresa_cuidador | empresa_membro | empresa_cliente',
  pessoa_id         CHAR(26)    NULL,
  empresa_id        CHAR(26)    NULL,
  papel             VARCHAR(20) NULL,
  dados             JSON        NULL COMMENT 'modalidade, relação, permissões, especialidade…',
  destino_nome      VARCHAR(120) NULL,
  destino_telefone  VARCHAR(20) NULL,
  criado_por        CHAR(26)    NOT NULL,
  criado_em         DATETIME    NOT NULL,
  expira_em         DATETIME    NOT NULL,
  aceito_por        CHAR(26)    NULL,
  aceito_em         DATETIME    NULL,
  revogado_em       DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_convites_codigo (codigo),
  KEY ix_convites_pessoa (pessoa_id),
  KEY ix_convites_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Agenda do(a) cuidador(a): é dele(a). Ninguém mais escreve aqui.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS disponibilidades (
  id          CHAR(26)   NOT NULL,
  usuario_id  CHAR(26)   NOT NULL,
  dia_semana  TINYINT UNSIGNED NOT NULL COMMENT '0=domingo … 6=sábado',
  inicio      TIME       NOT NULL COMMENT 'Hora local (America/Sao_Paulo)',
  fim         TIME       NOT NULL COMMENT 'Se fim <= inicio, termina no dia seguinte',
  criado_em   DATETIME   NOT NULL,
  PRIMARY KEY (id),
  KEY ix_disp_usuario (usuario_id, dia_semana),
  CONSTRAINT fk_disp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bloqueios_agenda (
  id          CHAR(26)    NOT NULL,
  usuario_id  CHAR(26)    NOT NULL,
  inicio      DATETIME    NOT NULL COMMENT 'UTC',
  fim         DATETIME    NOT NULL COMMENT 'UTC',
  motivo      VARCHAR(120) NULL COMMENT 'Só o(a) cuidador(a) vê',
  criado_em   DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_bloq_usuario (usuario_id, inicio),
  CONSTRAINT fk_bloq_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atendimentos: nascem como proposta e só valem com o aceite do(a) cuidador(a).
-- Proposta da empresa pode ir para vários(as) cuidadores(as): o primeiro aceite fica.
CREATE TABLE IF NOT EXISTS atendimentos (
  id                     CHAR(26)    NOT NULL,
  pessoa_id              CHAR(26)    NOT NULL,
  cuidador_id            CHAR(26)    NULL COMMENT 'NULL enquanto a proposta da empresa está aberta',
  solicitante_id         CHAR(26)    NOT NULL,
  empresa_id             CHAR(26)    NULL,
  origem                 VARCHAR(12) NOT NULL COMMENT 'gestor | empresa',
  inicio                 DATETIME    NOT NULL COMMENT 'UTC',
  fim                    DATETIME    NOT NULL COMMENT 'UTC',
  valor_centavos         INT UNSIGNED NULL COMMENT 'Valor do serviço (proposta comercial)',
  observacao             VARCHAR(255) NULL,
  status                 VARCHAR(12) NOT NULL DEFAULT 'proposto' COMMENT 'proposto | aceito | recusado | cancelado | expirado',
  respondido_em          DATETIME    NULL,
  motivo_recusa          VARCHAR(160) NULL COMMENT 'Opcional; recusar não tem consequência',
  prazo_confirmacao_min  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  confirmacao            VARCHAR(10) NULL COMMENT 'vou | nao_vou',
  confirmacao_em         DATETIME    NULL,
  confirmacao_motivo     VARCHAR(160) NULL,
  alerta_cobertura_em    DATETIME    NULL COMMENT 'Quando o alerta de "sem resposta no prazo" foi gerado',
  criado_em              DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_at_pessoa (pessoa_id, inicio),
  KEY ix_at_cuidador (cuidador_id, inicio),
  KEY ix_at_empresa (empresa_id, inicio),
  KEY ix_at_status (status, inicio),
  CONSTRAINT fk_at_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas_cuidadas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposta_destinatarios (
  atendimento_id  CHAR(26)    NOT NULL,
  cuidador_id     CHAR(26)    NOT NULL,
  status          VARCHAR(12) NOT NULL DEFAULT 'enviada' COMMENT 'enviada | aceita | recusada | encerrada',
  respondido_em   DATETIME    NULL,
  PRIMARY KEY (atendimento_id, cuidador_id),
  KEY ix_pd_cuidador (cuidador_id, status),
  CONSTRAINT fk_pd_atendimento FOREIGN KEY (atendimento_id) REFERENCES atendimentos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presença: início e fim do serviço (sem totais de horas, sem atraso, sem ranking)
CREATE TABLE IF NOT EXISTS presencas (
  id              CHAR(26)   NOT NULL COMMENT 'Gerado no aparelho',
  pessoa_id       CHAR(26)   NOT NULL,
  cuidador_id     CHAR(26)   NOT NULL,
  atendimento_id  CHAR(26)   NULL,
  inicio_em       DATETIME   NOT NULL,
  fim_em          DATETIME   NULL,
  resumo          JSON       NULL COMMENT 'Resumo enviado ao finalizar',
  criado_em       DATETIME   NOT NULL,
  PRIMARY KEY (id),
  KEY ix_pres_pessoa (pessoa_id, inicio_em),
  KEY ix_pres_cuidador (cuidador_id, fim_em),
  CONSTRAINT fk_pres_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas_cuidadas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Registros do cuidado (remédio, comida, água, humor, sinais…)
-- Imutáveis. Correção = novo registro + anulado_por no original.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS midias (
  id           CHAR(26)    NOT NULL,
  pessoa_id    CHAR(26)    NULL,
  conversa_id  CHAR(26)    NULL,
  autor_id     CHAR(26)    NOT NULL,
  tipo         VARCHAR(10) NOT NULL COMMENT 'foto | audio',
  mime         VARCHAR(60) NOT NULL,
  bytes        INT UNSIGNED NOT NULL,
  caminho      VARCHAR(255) NOT NULL COMMENT 'Relativo à pasta privada de mídias, fora da pasta pública',
  sha256       CHAR(64)    NOT NULL,
  criado_em    DATETIME    NOT NULL,
  excluido_em  DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_midias_pessoa (pessoa_id),
  KEY ix_midias_conversa (conversa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remedios (
  id            CHAR(26)     NOT NULL,
  pessoa_id     CHAR(26)     NOT NULL,
  nome          VARCHAR(120) NOT NULL,
  dose          VARCHAR(80)  NULL,
  como_dar      VARCHAR(255) NULL COMMENT 'Ex.: 1 comprimido branco, depois do almoço',
  horarios      JSON         NOT NULL COMMENT '["08:00","20:00"] hora local',
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,
  inicio        DATE         NULL,
  fim           DATE         NULL,
  criado_por    CHAR(26)     NOT NULL,
  criado_em     DATETIME     NOT NULL,
  atualizado_em DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_remedios_pessoa (pessoa_id, ativo),
  CONSTRAINT fk_remedios_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas_cuidadas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registros (
  id            CHAR(26)     NOT NULL COMMENT 'Gerado no aparelho (idempotente)',
  pessoa_id     CHAR(26)     NOT NULL,
  autor_id      CHAR(26)     NOT NULL,
  presenca_id   CHAR(26)     NULL,
  tipo          VARCHAR(20)  NOT NULL COMMENT 'remedio, comida, agua, banheiro, sono, banho, humor, sinais, algo, pele…',
  rotulo        VARCHAR(80)  NOT NULL COMMENT 'Texto curto já pronto para a linha do tempo',
  detalhe       VARCHAR(255) NULL,
  dados         JSON         NULL COMMENT 'Valores estruturados (ex.: {"temperatura":38.1})',
  alerta        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Avisa o(a) gestor(a)',
  remedio_id    CHAR(26)     NULL,
  midia_id      CHAR(26)     NULL,
  ocorrido_em   DATETIME     NOT NULL COMMENT 'Hora do aparelho (UTC)',
  recebido_em   DATETIME     NOT NULL COMMENT 'Hora em que chegou ao servidor',
  anulado_por   CHAR(26)     NULL,
  anula_id      CHAR(26)     NULL COMMENT 'Este registro corrige outro',
  PRIMARY KEY (id),
  KEY ix_reg_pessoa (pessoa_id, ocorrido_em),
  KEY ix_reg_pessoa_tipo (pessoa_id, tipo, ocorrido_em),
  KEY ix_reg_recebido (pessoa_id, recebido_em),
  CONSTRAINT fk_reg_pessoa FOREIGN KEY (pessoa_id) REFERENCES pessoas_cuidadas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Conversas no estilo WhatsApp
--   cuidado: uma por pessoa cuidada (todos com vínculo e permissão)
--   direta : empresa ↔ cuidador(a)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversas (
  id          CHAR(26)    NOT NULL,
  tipo        VARCHAR(10) NOT NULL COMMENT 'cuidado | direta',
  pessoa_id   CHAR(26)    NULL,
  empresa_id  CHAR(26)    NULL,
  usuario_id  CHAR(26)    NULL COMMENT 'Na conversa direta: o(a) cuidador(a)',
  criado_em   DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversa_pessoa (pessoa_id),
  UNIQUE KEY uq_conversa_direta (empresa_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensagens (
  id           CHAR(26)    NOT NULL COMMENT 'Gerado no aparelho (idempotente)',
  conversa_id  CHAR(26)    NOT NULL,
  autor_id     CHAR(26)    NULL COMMENT 'NULL = mensagem do sistema',
  tipo         VARCHAR(10) NOT NULL COMMENT 'texto | audio | foto | sistema',
  texto        TEXT        NULL,
  midia_id     CHAR(26)    NULL,
  criado_em    DATETIME    NOT NULL COMMENT 'Hora do aparelho',
  recebido_em  DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_msg_conversa (conversa_id, recebido_em),
  CONSTRAINT fk_msg_conversa FOREIGN KEY (conversa_id) REFERENCES conversas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Até onde cada pessoa já viu (para ✓✓ "viu" e contagem de não lidas)
CREATE TABLE IF NOT EXISTS conversa_leituras (
  conversa_id  CHAR(26) NOT NULL,
  usuario_id   CHAR(26) NOT NULL,
  visto_ate    DATETIME NOT NULL,
  PRIMARY KEY (conversa_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Alertas para gestor(a) e empresa
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertas (
  id             CHAR(26)     NOT NULL,
  pessoa_id      CHAR(26)     NOT NULL,
  empresa_id     CHAR(26)     NULL,
  tipo           VARCHAR(30)  NOT NULL COMMENT 'sem_confirmacao | nao_vou | registro | remedio_recusado | proposta_recusada',
  referencia_id  CHAR(26)     NULL,
  texto          VARCHAR(255) NOT NULL,
  criado_em      DATETIME     NOT NULL,
  resolvido_em   DATETIME     NULL,
  resolvido_por  CHAR(26)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alerta_ref (tipo, referencia_id),
  KEY ix_alertas_pessoa (pessoa_id, resolvido_em),
  KEY ix_alertas_empresa (empresa_id, resolvido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LGPD
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consentimentos (
  id           CHAR(26)    NOT NULL,
  usuario_id   CHAR(26)    NOT NULL,
  pessoa_id    CHAR(26)    NULL,
  tipo         VARCHAR(30) NOT NULL COMMENT 'termos | dados_saude | responsavel_legal',
  versao       VARCHAR(20) NOT NULL,
  ip           VARCHAR(45) NULL,
  aceito_em    DATETIME    NOT NULL,
  revogado_em  DATETIME    NULL,
  PRIMARY KEY (id),
  KEY ix_cons_usuario (usuario_id),
  KEY ix_cons_pessoa (pessoa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trilha de auditoria encadeada: cada linha guarda o hash da anterior.
-- Alterar ou apagar uma linha quebra a cadeia (verificável por bin/auditoria.php).
CREATE TABLE IF NOT EXISTS auditoria (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id      CHAR(26)    NULL,
  acao            VARCHAR(60) NOT NULL,
  entidade        VARCHAR(40) NULL,
  entidade_id     CHAR(26)    NULL,
  pessoa_id       CHAR(26)    NULL,
  dados           JSON        NULL COMMENT 'Sem dados de saúde: só o que mudou de forma resumida',
  ip              VARCHAR(45) NULL,
  criado_em       DATETIME    NOT NULL,
  hash_anterior   CHAR(64)    NOT NULL,
  hash            CHAR(64)    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_aud_pessoa (pessoa_id, criado_em),
  KEY ix_aud_usuario (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saída de eventos (padrão "outbox"): avisos por WhatsApp/e-mail/push hoje,
-- tempo real (WebSocket) amanhã — sem mudar as regras da API.
CREATE TABLE IF NOT EXISTS eventos_saida (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo           VARCHAR(40) NOT NULL,
  usuario_id     CHAR(26)    NULL COMMENT 'Destinatário',
  payload        JSON        NOT NULL,
  criado_em      DATETIME    NOT NULL,
  processado_em  DATETIME    NULL,
  tentativas     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_evt_pendentes (processado_em, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Controle de versão do esquema
CREATE TABLE IF NOT EXISTS esquema_versoes (
  versao     VARCHAR(20) NOT NULL,
  aplicado_em DATETIME   NOT NULL,
  PRIMARY KEY (versao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO esquema_versoes (versao, aplicado_em) VALUES ('001', UTC_TIMESTAMP());

SET foreign_key_checks = 1;
