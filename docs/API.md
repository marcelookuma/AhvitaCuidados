# API v1 — Ahvita Cuidados

Base: `https://cuidados.ahvita.com/api/index.php?r=` (funciona em qualquer servidor, inclusive nginx sem reescrita de URL; o "?" da rota vira "&", ex.: `?r=/conversas&desde=…`) ou `https://cuidados.ahvita.com/api/v1` onde houver Apache com .htaccess · JSON UTF-8 · datas ISO 8601 em UTC (`2026-10-21T10:32:00Z`).

**Autenticação**: web usa o cookie `cuidados_sessao` (httpOnly) e **toda escrita precisa do cabeçalho `X-Cuidados: 1`**; app Android/iOS usa `Authorization: Bearer <token>` (recebido em `/auth/entrar`).

**Erros** (mensagens curtas, prontas para a tela):
```json
{ "erro": { "codigo": "validacao", "mensagem": "Confira os dados.", "campos": { "cpf": "CPF inválido" } } }
```
| Status | Quando |
|---|---|
| 401 `nao_autenticado` | sem sessão |
| 403 `proibido` | tem vínculo, mas não a permissão |
| 404 | não existe **ou** sem vínculo (não revela existência) |
| 409 | regra de negócio: `fora_da_agenda`, `choque`, `ja_aceito`, `unico_gestor`, `cpf_em_uso`… |
| 422 `validacao` | campos inválidos (`campos` por campo) |
| 429 `muitas_tentativas` | limite de código de acesso |
| 503 `banco` | instabilidade (o app mantém na fila e reenvia) |

**Idempotência**: `POST` de registros, mensagens e presenças recebem `id` (ULID gerado no aparelho). Reenviar o mesmo `id` devolve o mesmo recurso, sem duplicar.

## Acesso
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| GET | `/saude` | — | público |
| POST | `/sistema/tarefas` | — | cabeçalho `X-Chave-Tarefas` (desligado sem `tarefas.chave`) — tarefas periódicas para serviço externo |
| GET | `/auth/canais` | — | público; `whatsapp`, `sms`, `email` ligados e `google` (client_id público ou `null`) |
| POST | `/auth/codigo` | `{destino, canal?}` | celular ou e-mail; em `teste` devolve `codigo_teste` |
| POST | `/auth/entrar` | `{destino, codigo, dispositivo?}` | → `{token, usuario, novo}` + cookie |
| POST | `/auth/google` | `{credencial, dispositivo?}` | ID token do Google Identity Services → `{token, usuario, novo}` + cookie. Mesmo e-mail (confirmado pelo Google) = mesma conta. 404 se `google.client_id` vazio |
| POST | `/auth/sair` | — | revoga a sessão |

## Eu
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| GET | `/eu` | — | conta, papéis (`cuidador`, `acompanha`, `empresa`), pessoas e empresas |
| PATCH | `/eu` | `{nome?, apelido?, cpf?, preferencias?}` | CPF validado e único |
| POST | `/eu/termos` | — | aceite versionado |
| GET | `/eu/inicio` | — | lista de conversas do(a) cuidador(a) (estilo WhatsApp) |
| GET | `/eu/exportar` | — | portabilidade (JSON) |
| POST | `/eu/excluir` | `{confirmar:"EXCLUIR"}` | bloqueia se for o(a) único(a) gestor(a) de alguém |

## Pessoa cuidada
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| GET | `/pessoas` | — | pessoas que acompanho |
| POST | `/pessoas` | `{nome_completo, apelido, nascimento, sexo, genero?, cpf?, relacao?, arranjo, ficha?, consentimentos:{dados_saude, responsavel_legal?}}` | nascimento obrigatório; menor de 18 exige `responsavel_legal` |
| GET | `/pessoas/{id}` | — | campos conforme permissão (CPF só p/ administrar; ficha p/ `ficha_emergencia`) |
| PATCH | `/pessoas/{id}` | cadastro, `ficha`, `plano`, `empresa_permissoes` | cadastro/ficha: `administrar`; plano: `plano_editar` |
| GET | `/pessoas/{id}/painel` | — | alertas, em serviço, indicadores do dia, próximos atendimentos |
| POST | `/alertas/{id}/resolver` | — | "Ciente" |
| GET | `/pessoas/{id}/vinculos` | — | quem participa; convites pendentes e empresa (para quem administra) |
| POST | `/pessoas/{id}/empresa/desvincular` | — | gestor(a) encerra a relação com a empresa |
| GET | `/pessoas/{id}/exportar` | — | cópia completa (administrar) |
| POST | `/pessoas/{id}/excluir` | `{confirmar:"<apelido>"}` | apaga saúde, mensagens e mídias; mantém só presença |

## Convites e vínculos
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| POST | `/pessoas/{id}/convites` | `{papel: cuidador\|rede\|profissional\|gestor, nome, telefone?, relacao?, modalidade?, especialidade?, atua_como_cuidador?, permissoes?, prazo_confirmacao_min?}` | → `{codigo, link, whatsapp}` |
| POST | `/empresas/{id}/convites` | `{tipo: cuidador\|membro\|cliente, nome, telefone?, modalidade?}` | |
| GET | `/convites/{codigo}` | — | o que será aceito |
| POST | `/convites/{codigo}/aceitar` | `{pessoa_id?, permissoes?}` | `cliente`: o(a) gestor(a) escolhe a pessoa e o que a empresa vê |
| POST | `/convites/{id}/revogar` | — | |
| PATCH | `/vinculos/{id}` | `{permissoes?, prazo_confirmacao_min?, modalidade?, relacao?, atua_como_cuidador?}` | `administrar` nunca vai para não-gestor |
| POST | `/vinculos/{id}/encerrar` | — | histórico fica; bloqueia remover o último gestor |

## Conversa, registros, remédios e presença
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| GET | `/pessoas/{id}/feed?desde=ISO` ou `?antes=ISO` | — | mensagens + registros + pendências (propostas, confirmar ida) + em serviço + `visto_por_outros_ate` (✓✓). Use `cursor` como próximo `desde` |
| POST | `/pessoas/{id}/mensagens` | `{id, tipo: texto\|audio\|foto, texto?, midia_id?, em}` | `mensagens` |
| POST | `/pessoas/{id}/visto` | `{ate?}` | |
| POST | `/pessoas/{id}/registros` | `{id, tipo, rotulo, detalhe?, dados?, alerta?, remedio_id?, midia_id?, em}` | `registrar`; `procedimento` só enfermagem; alertas recalculados na API; `em` até 7 dias no passado |
| GET | `/pessoas/{id}/registros?tipo=a,b&alertas=1&de&ate&antes&presencas=1` | — | linha do tempo (`ver_registros`) |
| POST | `/registros/{id}/anular` | `{id?, motivo?}` | correção sem apagar |
| GET | `/pessoas/{id}/remedios` | — | `remedios_ver` |
| POST | `/pessoas/{id}/remedios` | `{nome, dose?, como_dar?, horarios:["08:00"]}` | `remedios_editar` |
| PATCH | `/remedios/{id}` | `{…, ativo?}` | suspender = `ativo:false` |
| POST | `/pessoas/{id}/presencas` | `{id, em, atendimento_id?}` | "Iniciar serviço" (só cuidador(a)) |
| POST | `/presencas/{id}/finalizar` | `{em, recado?}` | resumo automático na conversa |

`tipo` de registro: `remedio, comida, agua, banheiro, sono, banho, humor, sinais, algo, pele, andar, comportamento, consulta, saida, visita, gasto, falta, casa, aparelhos, nota, procedimento`.

## Agenda do(a) cuidador(a) e atendimentos
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| GET | `/agenda` | — | minha disponibilidade, bloqueios, atendimentos e propostas abertas |
| PUT | `/agenda/disponibilidade` | `{janelas:[{dia_semana 0-6, inicio, fim}]}` | só o próprio; `fim <= inicio` = noturno |
| POST | `/agenda/bloqueios` | `{inicio, fim, motivo?}` | motivo só o(a) cuidador(a) vê |
| DELETE | `/agenda/bloqueios/{id}` | — | |
| GET | `/pessoas/{id}/atendimentos` | — | |
| POST | `/pessoas/{id}/atendimentos` | `{cuidador_id, inicio, fim, valor_centavos?, observacao?}` | `atendimentos`; MEI/autônomo só dentro da disponibilidade |
| GET | `/pessoas/{id}/cuidadores/{c}/disponivel?inicio&fim` | — | checagem antes de enviar |
| POST | `/atendimentos/{id}/responder` | `{aceitar, motivo?}` | só o(a) cuidador(a); proposta de empresa: primeiro aceite fica |
| POST | `/atendimentos/{id}/confirmar` | `{vou, motivo?}` | "Vou" / "Não vou" |
| POST | `/atendimentos/{id}/cancelar` | — | |

## Empresa
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| POST | `/empresas` | `{razao_social, nome_fantasia?, cnpj, telefone?, email?}` | quem cria vira admin |
| GET | `/empresas/{id}/painel` | — | contagens + alertas de cobertura |
| GET | `/empresas/{id}/cuidadores` | — | equipe (sem a agenda de cada um) |
| POST | `/empresas/{id}/cuidadores/{c}/encerrar` | — | admin |
| GET | `/empresas/{id}/clientes` | — | sem ficha de saúde, salvo permissão |
| GET | `/empresas/{id}/atendimentos` | — | propostas e cobertura |
| POST | `/empresas/{id}/propostas` | `{pessoa_id, inicio, fim, valor_centavos?, observacao?, cuidadores?}` | envia a quem tem disponibilidade |
| GET | `/empresas/{id}/conversas` | — | conversas com a equipe |
| GET/POST | `/conversas/{id}/mensagens` | `{id, tipo, texto?, midia_id?, em}` | conversa direta |
| POST | `/conversas/{id}/visto` | — | |

## Mídias
| Método | Caminho | Corpo | Observação |
|---|---|---|---|
| POST | `/midias` | multipart `arquivo` + `pessoa_id` ou `conversa_id` | foto (jpeg/png/webp/heic) ou áudio (webm/ogg/m4a/mp3), até 8 MB |
| GET | `/midias/{id}` | — | só para quem tem acesso; foto exige `fotos` |

## Painel de administração (`/admin/*`)
Sessão própria, separada da do app: cookie `cuidados_admin` (httpOnly, `SameSite=Strict`, 8 h, cai após 30 min parada), **sem Bearer**. Toda escrita exige `X-Cuidados-Admin: 1`. A sessão do app nunca abre o painel e a do painel nunca abre o app. Papéis: **leitura** < **operador** < **dono**. Ações com 🖊 exigem `{motivo}` (mín. 10 letras), gravado na auditoria com o e-mail de quem fez. Detalhes em `docs/ADMIN.md`.

| Método | Caminho | Papel | Corpo / filtros | Observação |
|---|---|---|---|---|
| GET | `/admin/config` | público | — | `google` (client_id), `configurado` |
| POST | `/admin/entrar` | público | `{credencial}` | ID token do Google; e-mail confirmado e na lista; 10 recusas/15 min por IP → 429 |
| POST | `/admin/sair` | qualquer | — | |
| GET | `/admin/eu` | leitura | — | |
| GET | `/admin/painel` | leitura | — | números, segurança 24 h, série de 30 dias |
| GET | `/admin/uso` | leitura | `dias` | pessoas por dia e perfil; rotas mais usadas (sem horários) |
| GET | `/admin/sistema` | leitura | — | versões, esquema, tarefas, tabelas; `erros_php` só operador+ |
| POST | `/admin/sistema/tarefas` | operador | — | roda migrações, alertas e limpezas |
| GET | `/admin/usuarios` | leitura | `q, papel, status, pagina` | |
| GET | `/admin/usuarios/{id}` | leitura | — | vínculos, empresas, sessões, consentimentos, uso 30 d |
| PATCH | `/admin/usuarios/{id}` | operador 🖊 | `{nome?, email?, telefone?}` | mudou forma de entrar → sessões caem |
| POST | `/admin/usuarios/{id}/bloquear` · `/desbloquear` | operador 🖊 | — | bloqueada não entra e perde as sessões |
| POST | `/admin/usuarios/{id}/encerrar-sessoes` | operador 🖊 | — | celular perdido |
| POST | `/admin/usuarios/{id}/desvincular-google` | operador 🖊 | — | |
| POST | `/admin/usuarios/{id}/excluir` | dono 🖊 | `{confirmar:"EXCLUIR"}` | mesma regra do `/eu/excluir` (LGPD art. 18) |
| GET | `/admin/pessoas` | leitura | `q, arranjo, status, pagina` | sem conteúdo de saúde |
| GET | `/admin/pessoas/{id}` | leitura | — | vínculos, contagens, consentimentos, **quem acessou (30 d)** |
| POST | `/admin/vinculos/{id}/encerrar` | operador 🖊 | — | 409 `unico_gestor` |
| POST | `/admin/vinculos/{id}/tornar-gestor` | dono 🖊 | — | só Rede de Apoio (409 `so_rede`) |
| GET | `/admin/empresas` · `/admin/empresas/{id}` | leitura | `q, pagina` | sem horas por cuidador(a) |
| GET | `/admin/convites` | leitura | `status, pagina` | código mascarado |
| POST | `/admin/convites/{id}/revogar` | operador 🖊 | — | |
| GET | `/admin/logs` | leitura | `ator, usuario, admin, pessoa, empresa, perfil, evento, rota, status (2xx/4xx/5xx/erro/NNN), ip, metodo, sessao, de, ate, pagina` | |
| GET | `/admin/logs/csv` | operador | mesmos filtros | até 50 mil linhas; a exportação vai para a auditoria |
| GET | `/admin/auditoria` | leitura | `acao, usuario, pessoa, entidade, de, ate, pagina` | |
| POST | `/admin/auditoria/verificar` | leitura | — | confere a cadeia de hashes |
| GET | `/admin/admins` | leitura | — | |
| POST | `/admin/admins` | dono | `{email, nome?, papel}` | |
| PATCH | `/admin/admins/{id}` | dono | `{papel?, ativo?, liberar_google?}` | ninguém muda a si mesmo nem os donos da configuração |
