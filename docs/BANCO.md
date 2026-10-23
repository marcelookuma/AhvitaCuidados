# Banco de dados — MariaDB/MySQL

Arquivos: `db/001_schema.sql` e as alterações seguintes `db/0NN_*.sql` (ex.: `002_google.sql`), aplicadas em ordem (importar no phpMyAdmin ou `php bin/instalar.php`). Compatível com MySQL 5.7+/8 e MariaDB 10.3+. Testado em MariaDB 10.11 com um usuário **sem** privilégio de gatilho (como na hospedagem compartilhada).

```mermaid
erDiagram
  usuarios ||--o{ sessoes : tem
  usuarios ||--o{ vinculos : participa
  pessoas_cuidadas ||--o{ vinculos : "é cuidada por"
  pessoas_cuidadas ||--o{ registros : tem
  pessoas_cuidadas ||--o{ remedios : toma
  pessoas_cuidadas ||--|| conversas : "tem 1 conversa"
  conversas ||--o{ mensagens : contém
  conversas ||--o{ conversa_leituras : "✓✓"
  pessoas_cuidadas ||--o{ atendimentos : recebe
  atendimentos ||--o{ proposta_destinatarios : "enviada a"
  usuarios ||--o{ disponibilidades : "agenda própria"
  usuarios ||--o{ bloqueios_agenda : bloqueia
  pessoas_cuidadas ||--o{ presencas : "início/fim"
  empresas ||--o{ empresa_membros : equipe
  empresas ||--o{ empresa_cuidadores : rede
  empresas |o--o{ pessoas_cuidadas : clientes
  pessoas_cuidadas ||--o{ alertas : gera
  usuarios ||--o{ consentimentos : dá
```

| Tabela | Para quê | Notas |
|---|---|---|
| `usuarios` | quem usa o app | CPF, telefone, e-mail e `google_sub` (conta Google) únicos; exclusão = anonimização |
| `otp_codigos` | código de acesso | só o HMAC do código; tentativas; IP |
| `sessoes` | sessões | só SHA-256 do token |
| `empresas`, `empresa_membros`, `empresa_cuidadores` | empresa, equipe administrativa e rede de cuidadores(as) | `modalidade` (mei/autonomo/clt) decide a regra de agenda |
| `pessoas_cuidadas` | pessoa cuidada | `nascimento` obrigatório; `ficha` (emergência) e `plano` em JSON; `empresa_permissoes` definidas pelo gestor |
| `vinculos` | quem participa de cada cuidado | papel, relação (Tutor(a)…), modalidade, especialidade, `atua_como_cuidador`, `permissoes` JSON, `prazo_confirmacao_min` |
| `convites` | convites por código | 6 caracteres sem letras ambíguas; 7 dias |
| `disponibilidades`, `bloqueios_agenda` | **agenda do(a) cuidador(a)** | só o(a) próprio(a) escreve |
| `atendimentos`, `proposta_destinatarios` | propostas e combinados | `status`, confirmação de ida, `alerta_cobertura_em` |
| `presencas` | início/fim do serviço | sem cálculo de horas |
| `registros` | registros do cuidado | id do aparelho; imutável; `anulado_por`/`anula_id` |
| `remedios` | remédios e horários | `horarios` JSON |
| `conversas`, `mensagens`, `conversa_leituras` | conversa por pessoa + diretas empresa↔cuidador(a) | leituras para ✓✓ e não lidas |
| `midias` | fotos e áudios | arquivo fora da pasta pública; SHA-256 |
| `alertas` | painel do gestor e da empresa | único por (tipo, referência) |
| `consentimentos` | LGPD | termos, dados_saude, responsavel_legal, versionados |
| `auditoria` | trilha encadeada por hash | sem dado de saúde |
| `eventos_saida` | outbox | base para push/WhatsApp/WebSocket |
| `esquema_versoes` | migrações aplicadas | `bin/instalar.php` aplica só as novas |

**Migrações**: novos arquivos `db/002_*.sql`, `db/003_*.sql`… Cada um termina com `INSERT IGNORE INTO esquema_versoes`.

**Índices pensados para as telas mais usadas**: conversa (`registros(pessoa_id, recebido_em)`, `mensagens(conversa_id, recebido_em)`), linha do tempo (`registros(pessoa_id, tipo, ocorrido_em)`), agenda (`atendimentos(cuidador_id, inicio)`), cobertura (`atendimentos(status, inicio)`).
