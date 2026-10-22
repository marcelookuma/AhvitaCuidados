/**
 * Catálogo dos registros de "um toque" da versão Conversa (base: wireframe v5).
 * Pergunta curta, até 5 opções, cada uma com ícone + rótulo.
 * Os tipos batem com CuidadoControlador::TIPOS na API.
 */
import type { Sexo } from './tipos';

export type Acao = 'teclado_temperatura' | 'teclado_pressao' | 'teclado_glicemia' | 'foto' | 'audio' | 'grave' | 'gasto';

export interface Opcao {
  e: string;          // ícone
  texto: string;      // botão
  rotulo?: string;    // vai para a linha do tempo (padrão: rótulo do item)
  detalhe?: string;   // padrão: texto do botão
  dados?: Record<string, unknown>;
  alerta?: boolean;   // avisa o(a) gestor(a)
  acao?: Acao;
}

export interface Item {
  chave: string;
  tipo: string;
  e: string;
  nome: string;
  pergunta: string;
  dica?: string;
  opcoes: Opcao[];
  vermelho?: boolean;
}

export interface Contexto { apelido: string; sexo?: Sexo; refeicao: string }

/** "ela" / "ele" / nome, e a terminação para adjetivos (calma / calmo / calmo(a)). */
export function pronomes(sexo: Sexo | undefined, apelido: string) {
  if (sexo === 'feminino') return { pr: 'ela', Pr: 'Ela', a: 'a' };
  if (sexo === 'masculino') return { pr: 'ele', Pr: 'Ele', a: 'o' };
  return { pr: apelido, Pr: apelido, a: 'o(a)' };
}

export function refeicaoDaHora(d: Date = new Date()): string {
  const h = d.getHours();
  if (h < 10) return 'Café da manhã';
  if (h < 14) return 'Almoço';
  if (h < 18) return 'Lanche da tarde';
  return 'Jantar';
}

export const PRINCIPAIS = ['remedio', 'comida', 'agua', 'banheiro', 'sono', 'banho', 'humor', 'sinais', 'algo'] as const;
export const MAIS = ['pele', 'andar', 'comportamento', 'consulta', 'saida', 'visita', 'gasto', 'falta', 'casa', 'aparelhos'] as const;

export function itens(c: Contexto): Record<string, Item> {
  const { pr, Pr, a } = pronomes(c.sexo, c.apelido);
  const ref = c.refeicao;
  return {
    remedio: {
      chave: 'remedio', tipo: 'remedio', e: '💊', nome: 'Remédio', pergunta: 'Qual remédio você deu?',
      dica: 'Aparecem os remédios de agora e os próximos.',
      opcoes: [], // preenchido com os lembretes do dia (ver lembretes.ts)
    },
    comida: {
      chave: 'comida', tipo: 'comida', e: '🍽️', nome: 'Comida', pergunta: `${ref}: quanto ${pr} comeu?`,
      opcoes: [
        { e: '😋', texto: 'Comeu tudo', rotulo: ref },
        { e: '🙂', texto: 'Comeu metade', rotulo: ref },
        { e: '😕', texto: 'Comeu pouco', rotulo: ref },
        { e: '🚫', texto: 'Não comeu', rotulo: ref, alerta: true },
      ],
    },
    agua: {
      chave: 'agua', tipo: 'agua', e: '💧', nome: 'Água', pergunta: `Quanto ${pr} bebeu?`,
      opcoes: [
        { e: '🥛', texto: '1 copo', detalhe: 'Bebeu 1 copo', dados: { copos: 1 } },
        { e: '🥛', texto: '2 copos', detalhe: 'Bebeu 2 copos', dados: { copos: 2 } },
        { e: '🧃', texto: 'Suco ou chá', detalhe: 'Bebeu suco ou chá', dados: { copos: 1 } },
        { e: '🚫', texto: 'Não quis beber', dados: { copos: 0 } },
      ],
    },
    banheiro: {
      chave: 'banheiro', tipo: 'banheiro', e: '🚽', nome: 'Xixi e cocô', pergunta: 'O que aconteceu?',
      opcoes: [
        { e: '💧', texto: 'Fez xixi' },
        { e: '💩', texto: 'Fez cocô' },
        { e: '✅', texto: 'Fez os dois', detalhe: 'Fez xixi e cocô' },
        { e: '🧷', texto: 'Troquei a fralda', detalhe: 'Troca de fralda' },
        { e: '🤢', texto: 'Vomitou', alerta: true },
      ],
    },
    sono: {
      chave: 'sono', tipo: 'sono', e: '😴', nome: 'Sono', pergunta: `Como ${pr} dormiu?`,
      opcoes: [
        { e: '😴', texto: 'Dormiu bem' },
        { e: '😣', texto: 'Acordou várias vezes' },
        { e: '😫', texto: 'Não dormiu', alerta: true },
        { e: '🛋️', texto: 'Cochilou' },
      ],
    },
    banho: {
      chave: 'banho', tipo: 'banho', e: '🛁', nome: 'Banho', pergunta: 'O que você fez?',
      opcoes: [
        { e: '🚿', texto: 'Deu banho', detalhe: 'Tomou banho' },
        { e: '🛏️', texto: 'Banho na cama' },
        { e: '🧴', texto: 'Lavou o cabelo' },
        { e: '👕', texto: 'Trocou a roupa' },
      ],
    },
    humor: {
      chave: 'humor', tipo: 'humor', e: '🙂', nome: 'Como está', pergunta: `Como ${pr} está agora?`,
      dica: 'Conte como você percebe.',
      opcoes: [
        { e: '😄', texto: 'Muito bem', rotulo: `Como ${pr} está`, dados: { nivel: 5 } },
        { e: '🙂', texto: 'Bem', rotulo: `Como ${pr} está`, dados: { nivel: 4 } },
        { e: '😐', texto: 'Normal', rotulo: `Como ${pr} está`, dados: { nivel: 3 } },
        { e: '😢', texto: 'Triste', rotulo: `Como ${pr} está`, dados: { nivel: 2 } },
        { e: '😠', texto: `Nervos${a}`, rotulo: `Como ${pr} está`, dados: { nivel: 1 } },
      ],
    },
    sinais: {
      chave: 'sinais', tipo: 'sinais', e: '🌡️', nome: 'Pressão e febre', pergunta: 'O que você mediu?',
      opcoes: [
        { e: '🌡️', texto: 'Febre: digitar a temperatura', acao: 'teclado_temperatura' },
        { e: '🩺', texto: 'Pressão: digitar os números', acao: 'teclado_pressao' },
        { e: '🩸', texto: 'Açúcar no sangue', acao: 'teclado_glicemia' },
      ],
    },
    algo: {
      chave: 'algo', tipo: 'algo', e: '⚠️', nome: 'Aconteceu algo', pergunta: 'O que aconteceu?', vermelho: true,
      opcoes: [
        { e: '🤕', texto: 'Caiu', alerta: true, acao: 'grave' },
        { e: '😮‍💨', texto: 'Falta de ar', alerta: true, acao: 'grave' },
        { e: '😣', texto: 'Dor fraca', alerta: true },
        { e: '😖', texto: 'Dor forte', alerta: true },
        { e: '🎤', texto: 'Outra coisa: falar', acao: 'audio' },
      ],
    },
    pele: {
      chave: 'pele', tipo: 'pele', e: '🩹', nome: 'Pele e feridas', pergunta: 'Como está a pele?',
      opcoes: [
        { e: '✅', texto: 'Normal' },
        { e: '🔴', texto: 'Ficou vermelha', alerta: true },
        { e: '🩹', texto: 'Machucado ou ferida: foto', acao: 'foto', alerta: true },
        { e: '🔄', texto: `Mudei ${pr} de posição`, detalhe: 'Mudou de posição' },
      ],
    },
    andar: {
      chave: 'andar', tipo: 'andar', e: '🚶', nome: 'Andar e mexer', pergunta: `${Pr} se mexeu?`,
      opcoes: [
        { e: '🚶', texto: 'Caminhou' },
        { e: '🤸', texto: 'Fez exercício' },
        { e: '🤝', texto: 'Ajudei a levantar' },
        { e: '🛏️', texto: 'Ficou na cama' },
      ],
    },
    comportamento: {
      chave: 'comportamento', tipo: 'comportamento', e: '😵‍💫', nome: 'Agitação ou confusão', pergunta: `Como ${pr} ficou?`,
      opcoes: [
        { e: '😌', texto: `Calm${a}` },
        { e: '😤', texto: `Agitad${a}`, alerta: true },
        { e: '😵‍💫', texto: `Confus${a}`, alerta: true },
        { e: '🙅', texto: 'Não quis ajuda' },
      ],
    },
    consulta: {
      chave: 'consulta', tipo: 'consulta', e: '🏥', nome: 'Médico', pergunta: 'Médico',
      opcoes: [
        { e: '🏥', texto: 'Fomos ao médico', detalhe: 'Foi ao médico' },
        { e: '👩‍⚕️', texto: 'Médico veio em casa' },
        { e: '📄', texto: 'Tem receita nova: foto', detalhe: 'Receita nova', acao: 'foto' },
      ],
    },
    saida: {
      chave: 'saida', tipo: 'saida', e: '🌳', nome: 'Saiu de casa', pergunta: `${Pr} saiu de casa?`,
      dica: 'O app não usa localização. Só registra a ida e a volta.',
      opcoes: [
        { e: '🌳', texto: 'Passeio' },
        { e: '🛒', texto: 'Mercado ou farmácia' },
        { e: '🏥', texto: 'Médico ou exame' },
        { e: '🏠', texto: 'Voltamos para casa', detalhe: 'Voltou para casa' },
      ],
    },
    visita: {
      chave: 'visita', tipo: 'visita', e: '👋', nome: 'Visita', pergunta: 'Quem veio?',
      opcoes: [
        { e: '👨‍👩‍👧', texto: 'Parente' },
        { e: '🙋', texto: 'Amigo ou vizinho' },
        { e: '🩺', texto: 'Profissional de saúde' },
      ],
    },
    gasto: {
      chave: 'gasto', tipo: 'gasto', e: '💵', nome: 'Gastei dinheiro', pergunta: 'Onde você gastou?',
      dica: 'Depois tire foto do recibo.',
      opcoes: [
        { e: '💊', texto: 'Farmácia', acao: 'gasto' },
        { e: '🛒', texto: 'Mercado', acao: 'gasto' },
        { e: '🚌', texto: 'Transporte', acao: 'gasto' },
        { e: '💵', texto: 'Outro', acao: 'gasto' },
      ],
    },
    falta: {
      chave: 'falta', tipo: 'falta', e: '🛒', nome: 'Está acabando', pergunta: 'O que está acabando?',
      opcoes: [
        { e: '🧷', texto: 'Fralda', alerta: true },
        { e: '💊', texto: 'Remédio', alerta: true },
        { e: '🧤', texto: 'Luva ou álcool' },
        { e: '🥫', texto: 'Comida' },
      ],
    },
    casa: {
      chave: 'casa', tipo: 'casa', e: '🏠', nome: 'Casa segura', pergunta: 'A casa está segura?',
      opcoes: [
        { e: '✅', texto: 'Tudo certo' },
        { e: '⚠️', texto: 'Chão molhado ou tapete solto', detalhe: 'Risco de queda', alerta: true },
        { e: '🔧', texto: 'Algo quebrado' },
      ],
    },
    aparelhos: {
      chave: 'aparelhos', tipo: 'aparelhos', e: '🦽', nome: 'Aparelhos', pergunta: 'Os aparelhos estão bem?',
      dica: 'Você verifica e avisa. Conserto e ajuste ficam com a equipe de saúde.',
      opcoes: [
        { e: '✅', texto: 'Tudo funcionando' },
        { e: '🔧', texto: 'Precisa de conserto', alerta: true },
        { e: '🔋', texto: 'Bateria ou oxigênio acabando', detalhe: 'Acabando', alerta: true },
      ],
    },
  };
}

/** Faixas de alerta usadas também na API (alertaAutomatico). */
export function febre(temp: number): boolean {
  return temp >= 37.8;
}

export function pressaoForaDoComum(sist: number, diast: number): boolean {
  return sist >= 140 || sist <= 90 || diast >= 90;
}

export function glicemiaForaDoComum(v: number): boolean {
  return v < 70 || v > 250;
}

/** Porção de água em ml: 100 ml para crianças, 200 ml para os demais. */
export function mlPorCopo(idade: number): number {
  return idade < 12 ? 100 : 200;
}
