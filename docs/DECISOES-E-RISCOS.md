# Decisões, riscos e o que ficou fora do MVP

> Não substitui parecer jurídico. Os pontos marcados ⚖️ precisam de validação de advogado(a) trabalhista e de proteção de dados antes de receber dados de pessoas reais.

## 1. Decisões técnicas
| Decisão | Por quê | Alternativa descartada |
|---|---|---|
| API em **PHP 8 sem dependências** | Plano de revenda KingHost = hospedagem compartilhada PHP; Node.js é produto à parte | Node/TypeScript (versão v1 anterior): exige o plano "Hospedagem Node.js" |
| **MariaDB sem gatilhos** | Hospedagem recusou triggers (#1419) e colunas geradas (#1901) na publicação anterior | Regras no banco |
| **Consulta periódica** em vez de WebSocket | Hospedagem compartilhada não mantém conexão aberta | Pusher/Ably (custo e transferência internacional de dados) |
| **ULID gerado no aparelho** + API idempotente | Registrar sem internet e reenviar sem duplicar | Auto-incremento (quebra com fila offline) |
| **Um app com modo por perfil** | Decisão de produto já tomada; mesma pessoa pode cuidar e gerir | Apps separados |
| **Login por código**, sem senha | Baixo letramento digital; sem senha fraca para vazar | E-mail + senha |
| **Continuar com o Google** como alternativa (opcional, `google.client_id`) | Quem tem Android já está logado no Google: um toque, sem esperar código. Verificação do ID token no próprio PHP (openssl), sem biblioteca. Mesmo e-mail confirmado = mesma conta | Redirecionamento OAuth (exige reescrita de URL que o nginx da KingHost ignora) e One Tap automático (janela que aparece sozinha confunde quem tem baixo letramento) |
| Convite enviado pelo **WhatsApp de quem convida** (link `wa.me`) | Custo zero e o app não "fala" em nome de ninguém | SMS pago pelo app |
| App Android com **Capacitor** (as mesmas telas da web num app nativo) — `docs/ANDROID.md` | Uma interface só: web e Android sempre iguais; custo de manutenção de um app; reaproveita fila offline, regras e testes. Push, cofre do token, câmera, voz e "voltar" nativos | Expo/React Native (plano anterior): reescreveria as ~40 telas e manteria duas interfaces. Reavaliar só se o piloto mostrar lentidão em celulares simples |
| **Push pelo Firebase** lendo a fila de saída já existente, a cada minuto, sem biblioteca | Funciona na hospedagem compartilhada (sem processo contínuo); nada muda nas regras da API | Serviço de push pago de terceiros |
| Transcrição de áudio e leitura do aparelho de pressão por foto **fora do MVP** | Exigem serviço de IA pago e, em geral, fora do Brasil (LGPD art. 33) | — |

## 2. Risco trabalhista — o que o código já protege
- Vocabulário: o build **falha** se aparecer "trabalhando", "expediente", "jornada", "patrão", "escala", "desempenho", "falta do cuidador", "Família", "Cuidadora" (`scripts/verificar-termos.mjs`).
- Agenda do(a) cuidador(a): só ele(a) escreve; proposta fora da disponibilidade de MEI/autônomo é **bloqueada pela API**; todo atendimento exige aceite; recusar não tem consequência e o motivo é opcional.
- Confirmação de ida: só organiza cobertura. Texto neutro ("sem resposta no prazo combinado"), sem falta, sem penalidade.
- Presença: só início e fim. Nenhum total de horas, atraso ou ranking em tela alguma.
- Plano de cuidado descreve necessidades da pessoa; não é lista de tarefas com horário.
- Empresa não vê a agenda completa do(a) cuidador(a), só se está disponível quando envia a proposta.
- ⚖️ **Avisos no celular (app Android)**: das 21h às 7h o celular do(a) cuidador(a) fica quieto, a não ser que esteja em serviço ou tenha ligado "Avisos à noite" (escolha dele(a), em *Eu*). Pedidos de atendimento esperam até as 7h. Textos sem cobrança ("veja e decida se aceita"). Aviso de contratante a qualquer hora pode ser lido como subordinação e sobreaviso.
- ⚖️ **App sem localização**: o Android não pede GPS em momento algum; nada de check-in por geolocalização. Se um dia pedirem "confirmar chegada pelo GPS", é controle de jornada: não fazer para MEI/autônomo(a).

### Empresa acompanhando a pessoa cuidada (pedido nos dois sentidos)
- O(a) gestor(a) procura a empresa e pede, ou a empresa pede ao(à) gestor(a) pelo celular ou e-mail. **Quem decide o que a empresa vê é sempre o(a) gestor(a)**, item por item (atendimentos, linha do tempo, plano, remédios, fotos, conversa, ficha), e pode liberar menos do que a empresa pediu, mudar depois ou encerrar.
- LGPD: o aceite grava o consentimento `compartilhar_empresa` (revogado ao encerrar) e avisa todos na conversa da pessoa, inclusive cuidadores(as). Antes do aceite a empresa vê só o apelido da pessoa. A empresa nunca registra, edita nem administra.
- ⚖️ **A empresa nunca vê presença**: sem início/fim de serviço na linha do tempo, sem "em serviço desde" no painel, sem os avisos automáticos de início/fim na conversa. Horário de entrada e saída de cuidador(a) MEI/autônomo(a) na mão da empresa é prova típica de controle de jornada. A linha do tempo da empresa mostra só o cuidado da pessoa, com o aviso de que não serve para controlar tarefas ou horários.
- Risco que fica: mesmo sem presença, os registros têm autor e horário. Se a empresa usar isso para cobrar o(a) cuidador(a) MEI, o risco volta. Recomendo cláusula no contrato empresa ↔ cuidador(a) e validação jurídica antes de liberar "ver a linha do tempo" por padrão.

### Rede da empresa por pedido (nos dois sentidos)
- A empresa chama quem já é cuidador(a) no app pelo **celular**; o(a) cuidador(a) procura a empresa pelo **nome ou CNPJ**. Só o aceite do outro lado coloca a pessoa na rede.
- ⚖️ **Sem exclusividade e com saída livre**: o(a) cuidador(a) pode estar na rede de várias empresas, recusar sem consequência e **sair da rede pelo próprio app** (antes só a empresa encerrava). Uma empresa não vê as outras. Isso reforça a autonomia típica de prestação de serviço.
- ⚖️ Vocabulário: "entrar na rede", "pedido", "aceitar". Nunca "vaga", "candidatura", "admissão" ou "contratar" para MEI/autônomo(a). O(a) cuidador(a) só se oferece como MEI ou autônomo(a); CLT só quando a empresa propõe (e aí o contrato de trabalho é fora do app).
- LGPD: a empresa não navega pelo cadastro de cuidadores(as). Antes do aceite vê só o primeiro nome e os 4 últimos dígitos do celular; achar alguém por celular tem limite (20 não encontrados por hora) para não servir de varredura. Pedido sem resposta vence em 30 dias.

## 3. ⚖️ Itens do wireframe v5 que **não** entraram no MVP por risco — recomendo revisar antes de construir
| Item no wireframe | Problema | Sugestão |
|---|---|---|
| Aba **"Minha Escala"** (cuidador(a)) e **"Sugerir escala / Escala sugerida pelo sistema"** (gestor) | "Escala" organizada por terceiros é sinal de subordinação para MEI | Mantido "Minha agenda" e propostas; escala só para CLT |
| **"Meu desempenho da semana"** (cuidador(a)), **Avaliação (NPS)** do(a) cuidador(a), aba **Qualidade** da empresa | Avaliar desempenho individual de prestador(a) MEI aproxima de poder diretivo | Se houver, só reputação de marketplace (prioridade em convites), nunca nota individual do contratante |
| **Dinheiro: "Para receber em outubro R$ 3.920 — Dona Alzira R$ 3.200"** | Valor fixo mensal e recorrente = **onerosidade + habitualidade**, cara de salário | Cobrança por atendimento, com nota fiscal do MEI; nunca "mensalidade" ao(à) cuidador(a) |
| **Câmeras** (gestor) | Vigiar quem presta o serviço = subordinação, e imagem do(a) trabalhador(a) é dado pessoal | Retirar, ou parecer jurídico específico; jamais com áudio ativável pelo gestor |
| **Cuidador(a) MEI atendendo a mesma família mais de 2 dias por semana** (ex.: Dona Alzira, seg a sáb, 07h–19h) | A LC 150/2015 define empregado(a) doméstico(a) quem presta serviço a pessoa ou família **por mais de 2 dias por semana**. Nesse padrão o MEI não protege: o risco vem da realidade, não da tela | Produto: aviso ao(à) gestor(a) ao combinar o 3º dia semanal com o(a) mesmo(a) MEI, sugerindo CLT doméstico ou empresa. **Validar com advogado(a) antes de lançar** |

## 4. ⚖️ LGPD — pontos abertos
1. **RIPD** (relatório de impacto) e **encarregado(a) (DPO)** antes de dados reais: é tratamento de dado sensível em escala, inclusive de crianças.
2. **Hospedagem compartilhada** para dado de saúde: confirmar com a KingHost política de backup, criptografia em repouso, localização dos servidores e contrato de operador (DPA). Para a fase de validação com poucas famílias é defensável; para escala, migrar (ver `ARQUITETURA.md` §7).
3. **Twilio** (SMS/WhatsApp) é transferência internacional: informar na política de privacidade ou usar fornecedor nacional (ex.: Zenvia). O e-mail via SMTP da própria KingHost não tem esse problema.
4. **Retenção após exclusão**: o app mantém só a presença (quem atendeu e quando) como prova do serviço. Definir prazo e base legal (exercício regular de direitos, art. 7º VI / 11 II d).
5. **Áudios** podem conter dado de saúde: mesma proteção das fotos (já aplicada).
6. Termos de uso e política de privacidade em `apps/web/public/` são **minutas**.
7. **Google** (Continuar com o Google): o Google fica sabendo que a pessoa usa o app e quando entra; o app recebe só nome, e-mail e o identificador da conta (`google_sub`). Nenhum dado de saúde vai ao Google. Informar na política (já incluído na minuta) e no RIPD como compartilhamento com controlador independente. O vínculo é apagado na exclusão da conta.

8. **Painel `/admin` e log de acessos** (`docs/ADMIN.md`): o painel mostra cadastro, vínculos e contagens, **nunca** conteúdo de saúde; toda leitura do painel vai para o log e toda mudança vai para a auditoria com justificativa. O log guarda IP e data/hora de todos os perfis por ≥ 6 meses (Marco Civil, art. 15) e permite responder ao titular **quem acessou seus dados**. Base legal a registrar no RIPD: cumprimento de obrigação legal (Marco Civil) e segurança (legítimo interesse). Informar na política de privacidade (minuta ainda não cita o log).
9. ⚖️ **Log de acessos não é ferramenta de gestão de cuidador(a)**: o painel não calcula horas, tempo conectado, atrasos nem ranking, e as leituras de rotina ficam só somadas por dia, sem horário. Nunca entregar relatório do log a gestor(a) ou empresa para acompanhar cuidador(a) MEI/autônomo(a): seria prova de controle (subordinação). Pedido judicial ou do titular segue pelo canal do encarregado(a).
10. **App Android**: dados do app fora do backup do Google e da cópia entre aparelhos; token no cofre do aparelho (Keystore); fotos e áudios recebidos só na memória; texto dos avisos sem nome nem dado de saúde (aparece na tela bloqueada). **Firebase (Google) é transferência internacional**: o conteúdo enviado é só o texto genérico e o caminho da tela, mas o token do aparelho e o horário vão ao Google. Informar na política de privacidade e no RIPD (a minuta ainda não cita o Firebase).
11. **Play Store**: preencher a Declaração de apps de saúde e a seção Segurança dos dados de acordo com o RIPD; a exclusão de conta pelo app (*Meus dados → Excluir minha conta*) é exigência da loja e direito do titular.

## 5. Custos a acompanhar
- App Android: conta de desenvolvedor da Play Store (taxa única), Firebase Cloud Messaging sem custo por aviso; Mac + conta Apple só quando for fazer o iOS.
- Twilio Verify cobra por verificação enviada: o login por e-mail é gratuito. Sessão de 30 dias reduz pedidos de código.
- Consulta periódica gera tráfego proporcional a conversas abertas; é o primeiro item a migrar na fase 2.

## 6. Fora do MVP (do wireframe v5)
Rede de Apoio com telas próprias (hoje usa o painel de acompanhamento, conforme permissões) · Pessoa cuidada ("como me sinto") · Médico(a) e Equipe de saúde com prontuário e isolamento de psicologia · Itens de enfermagem na tela do(a) cuidador(a) (o registro `procedimento` já existe na API, restrito à enfermagem) · Pele com mapa corporal · Despesas com rateio e reembolso · Tarefas da Rede · Capacitação · Carteira profissional · Exames · Transcrição de áudio e leitura por foto · Pagamentos.

## 7. Relação com a v1 anterior
Esta versão **substitui** a v1 (Node.js + MySQL para a Hospedagem Node.js). Mudanças: API reescrita em PHP para o plano de revenda; inclusão da versão Conversa como padrão do(a) cuidador(a), do perfil Empresa e da conversa direta empresa ↔ cuidador(a); núcleo de regras extraído em `packages/core` para o app nativo. Em out/2026 o app Android passou a ser feito com Capacitor sobre as mesmas telas (ver `docs/ANDROID.md`).
