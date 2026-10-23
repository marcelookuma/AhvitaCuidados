/**
 * Tipos do contrato da API v1. Compartilhados entre o app web e o futuro
 * app Android/iOS (React Native/Expo). Não dependem de React nem do navegador.
 */

export type Papel = 'gestor' | 'cuidador' | 'rede' | 'profissional' | 'empresa';
export type Arranjo = 'somente_eu' | 'so_cuidador' | 'gestor_cuidador' | 'gestor_rede' | 'gestor_rede_cuidador';
export type Modalidade = 'mei' | 'autonomo' | 'clt' | 'rede' | 'empresa';
export type Sexo = 'feminino' | 'masculino' | 'intersexo' | 'nao_informar';

export interface Autor { id: string; nome: string }

export interface Preferencias { fonte?: 1 | 2 | 3; voz?: boolean; modo?: 'conversa' | 'padrao' }

export interface Usuario {
  id: string;
  nome: string | null;
  apelido: string | null;
  cpf: string | null;
  telefone: string | null;
  email: string | null;
  /** Conta vinculada ao Google ("Entrar com Google") */
  google?: boolean;
  preferencias: Preferencias;
}

export interface Eu {
  usuario: Usuario;
  perfil_completo: boolean;
  aceitou_termos: boolean;
  termos_versao: string;
  papeis: { cuidador: boolean; acompanha: boolean; empresa: boolean };
  pessoas: { id: string; apelido: string; papel: Papel; atua_como_cuidador: boolean }[];
  empresas: { id: string; nome: string; papel: 'admin' | 'coordenacao' }[];
}

export interface Ficha {
  alergias?: string;
  condicoes?: string;
  convenio?: string;
  endereco?: string;
  observacoes?: string;
  contatos?: { nome: string; telefone: string }[];
}

export interface Plano {
  texto?: string;
  importante?: string;
  cuidados?: { titulo: string; como: string }[];
  agua_meta_copos?: number;
}

export interface Pessoa {
  id: string;
  apelido: string;
  nome_completo: string;
  nascimento: string;
  idade: number;
  sexo: Sexo;
  genero: string | null;
  arranjo: Arranjo;
  empresa_id: string | null;
  meu_papel: Papel;
  sou_cuidador: boolean;
  permissoes: string[];
  cpf?: string | null;
  ficha?: Ficha;
  plano?: Plano;
  empresa_permissoes?: string[] | null;
  meu_vinculo?: { id: string; modalidade: Modalidade | null; prazo_confirmacao_min: number };
}

export interface Registro {
  tipo_item: 'registro';
  id: string;
  tipo: string;
  rotulo: string;
  detalhe: string | null;
  dados: Record<string, unknown> | null;
  alerta: boolean;
  remedio_id: string | null;
  midia_id: string | null;
  autor: Autor | null;
  em: string;
  recebido_em: string;
  anulado: boolean;
  anula_id: string | null;
}

export interface Mensagem {
  tipo_item: 'mensagem';
  id: string;
  tipo: 'texto' | 'audio' | 'foto' | 'sistema';
  texto: string | null;
  midia_id: string | null;
  autor: Autor | null;
  em: string;
  recebido_em: string;
}

export type ItemFeed = Registro | Mensagem;

export interface Atendimento {
  id: string;
  pessoa: { id: string; apelido: string };
  cuidador: Autor | null;
  solicitante: Autor | null;
  empresa_id: string | null;
  empresa?: string | null;
  origem: 'gestor' | 'empresa';
  inicio: string;
  fim: string;
  observacao: string | null;
  status: 'proposto' | 'aceito' | 'recusado' | 'cancelado' | 'expirado';
  motivo_recusa: string | null;
  prazo_confirmacao_min: number;
  confirmar_ate: string;
  confirmacao: 'vou' | 'nao_vou' | null;
  confirmacao_em: string | null;
  confirmacao_motivo: string | null;
  sem_resposta_no_prazo: boolean;
  valor_centavos?: number | null;
  enviada_para?: number;
  recusas?: number;
}

export interface Feed {
  itens: ItemFeed[];
  cursor: string;
  tem_mais: boolean;
  conversa_id: string;
  visto_por_outros_ate: string | null;
  pendencias: { tipo: 'proposta' | 'confirmar_ida'; atendimento: Atendimento }[];
  em_servico: { id: string; cuidador: Autor; desde: string; minha: boolean }[];
}

export interface Remedio {
  id: string;
  nome: string;
  dose: string | null;
  como_dar: string | null;
  horarios: string[];
  ativo: boolean;
  inicio: string | null;
  fim: string | null;
}

export interface ConversaResumo {
  tipo: 'cuidado' | 'direta';
  pessoa_id?: string;
  conversa_id: string;
  nome: string;
  sexo?: Sexo;
  ultimo: ItemFeed | null;
  nao_lidas: number;
  em_servico_desde?: string | null;
  presenca_id?: string | null;
  proximo_atendimento?: Atendimento | null;
}

export interface Alerta {
  id: string;
  pessoa: { id: string; apelido: string };
  tipo: string;
  referencia_id: string | null;
  texto: string;
  em: string;
  resolvido: boolean;
}

export interface Vinculo {
  id: string;
  usuario: { id: string; nome: string | null; apelido: string | null; telefone?: string | null };
  papel: Papel;
  relacao: string | null;
  modalidade: Modalidade | null;
  empresa_id: string | null;
  especialidade: string | null;
  atua_como_cuidador: boolean;
  permissoes: string[];
  prazo_confirmacao_min: number;
  status: 'ativo' | 'encerrado';
  desde: string;
}

export interface Janela { dia_semana: number; inicio: string; fim: string }

export interface Agenda {
  disponibilidade: Janela[];
  bloqueios: { id: string; inicio: string; fim: string; motivo: string | null }[];
  atendimentos: Atendimento[];
  propostas_abertas: Atendimento[];
}

export interface Painel {
  pessoa: Pessoa;
  alertas: Alerta[];
  em_servico: { presenca_id: string; cuidador: Autor; desde: string }[];
  hoje: null | {
    registros: number;
    agua_copos: number;
    agua_meta: number | null;
    refeicoes: number;
    remedios_dados: number;
    remedios_nao_dados: number;
    remedios_previstos: number;
    humor: string | null;
  };
  proximos_atendimentos: Atendimento[];
}

export interface Empresa {
  id: string;
  razao_social: string;
  nome_fantasia: string | null;
  nome: string;
  cnpj: string;
  telefone: string | null;
  email: string | null;
  meu_papel: 'admin' | 'coordenacao' | null;
}

export interface ConviteCriado { id: string; codigo: string; link: string; expira_em: string; whatsapp: string }

/** Pedido para entrar na rede de uma empresa (a empresa pede ou o(a) cuidador(a) pede; o outro lado aceita). */
export interface PedidoRede {
  id: string;
  origem: 'empresa' | 'cuidador';
  modalidade: 'mei' | 'autonomo' | 'clt';
  mensagem: string | null;
  status: 'pendente' | 'aceito' | 'recusado' | 'cancelado' | 'expirado';
  criado_em: string;
  expira_em: string;
  respondido_em: string | null;
  empresa?: { id: string; nome: string };
  cuidador?: { id: string; nome: string; telefone_final: string | null };
}

export interface MinhaRede {
  empresas: { id: string; nome: string; modalidade: string; conversa_id: string }[];
  recebidos: PedidoRede[];
  enviados: PedidoRede[];
}

export interface EmpresaEncontrada { id: string; nome: string; razao_social: string; cnpj: string; na_rede: boolean; pedido_pendente: boolean }
