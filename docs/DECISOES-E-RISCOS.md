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
| Convite enviado pelo **WhatsApp de quem convida** (link `wa.me`) | Custo zero e o app não "fala" em nome de ninguém | SMS pago pelo app |
| Transcrição de áudio e leitura do aparelho de pressão por foto **fora do MVP** | Exigem serviço de IA pago e, em geral, fora do Brasil (LGPD art. 33) | — |

## 2. Risco trabalhista — o que o código já protege
- Vocabulário: o build **falha** se aparecer "trabalhando", "expediente", "jornada", "patrão", "escala", "desempenho", "falta do cuidador", "Família", "Cuidadora" (`scripts/verificar-termos.mjs`).
- Agenda do(a) cuidador(a): só ele(a) escreve; proposta fora da disponibilidade de MEI/autônomo é **bloqueada pela API**; todo atendimento exige aceite; recusar não tem consequência e o motivo é opcional.
- Confirmação de ida: só organiza cobertura. Texto neutro ("sem resposta no prazo combinado"), sem falta, sem penalidade.
- Presença: só início e fim. Nenhum total de horas, atraso ou ranking em tela alguma.
- Plano de cuidado descreve necessidades da pessoa; não é lista de tarefas com horário.
- Empresa não vê a agenda completa do(a) cuidador(a), só se está disponível quando envia a proposta.

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

## 5. Custos a acompanhar
- Twilio Verify cobra por verificação enviada: o login por e-mail é gratuito. Sessão de 30 dias reduz pedidos de código.
- Consulta periódica gera tráfego proporcional a conversas abertas; é o primeiro item a migrar na fase 2.

## 6. Fora do MVP (do wireframe v5)
Rede de Apoio com telas próprias (hoje usa o painel de acompanhamento, conforme permissões) · Pessoa cuidada ("como me sinto") · Médico(a) e Equipe de saúde com prontuário e isolamento de psicologia · Itens de enfermagem na tela do(a) cuidador(a) (o registro `procedimento` já existe na API, restrito à enfermagem) · Pele com mapa corporal · Despesas com rateio e reembolso · Tarefas da Rede · Capacitação · Carteira profissional · Exames · Backoffice · Push nativo · Transcrição de áudio e leitura por foto · Pagamentos.

## 7. Relação com a v1 anterior
Esta versão **substitui** a v1 (Node.js + MySQL para a Hospedagem Node.js). Mudanças: API reescrita em PHP para o plano de revenda; inclusão da versão Conversa como padrão do(a) cuidador(a), do perfil Empresa e da conversa direta empresa ↔ cuidador(a); núcleo de regras extraído em `packages/core` para o app nativo.
