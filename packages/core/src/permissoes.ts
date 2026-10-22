/** Permissões por funcionalidade (mesmo catálogo de api/src/Modulos/Permissoes.php). */
export const FUNCOES: { chave: string; nome: string; desc: string }[] = [
  { chave: 'registrar', nome: 'Registrar cuidados', desc: 'Remédio, comida, água, banho…' },
  { chave: 'ver_registros', nome: 'Ver a linha do tempo', desc: 'Tudo o que foi registrado' },
  { chave: 'mensagens', nome: 'Conversa', desc: 'Mandar e receber mensagens' },
  { chave: 'remedios_ver', nome: 'Ver remédios', desc: 'Lista e horários' },
  { chave: 'remedios_editar', nome: 'Mudar remédios', desc: 'Incluir, mudar horário, suspender' },
  { chave: 'plano_ver', nome: 'Ver "Como cuidar"', desc: 'Plano de cuidado' },
  { chave: 'plano_editar', nome: 'Mudar "Como cuidar"', desc: 'Editar o plano de cuidado' },
  { chave: 'ficha_emergencia', nome: 'Ficha de emergência', desc: 'Alergias, convênio, contatos' },
  { chave: 'fotos', nome: 'Ver fotos', desc: 'Pele, receitas, comida' },
  { chave: 'atendimentos', nome: 'Organizar atendimentos', desc: 'Propor horários e ver confirmações' },
];

export const PAPEIS: Record<string, string> = {
  gestor: 'Gestor(a)',
  cuidador: 'Cuidador(a)',
  rede: 'Rede de Apoio',
  profissional: 'Equipe de saúde',
  empresa: 'Empresa',
};

export const MODALIDADES: Record<string, string> = {
  mei: 'MEI (prestação de serviço)',
  autonomo: 'Autônomo(a)',
  clt: 'CLT (empregado(a) doméstico(a))',
  rede: 'Da Rede de Apoio (sem pagamento)',
  empresa: 'Por uma empresa',
};

export const ARRANJOS: { chave: string; nome: string; desc: string }[] = [
  { chave: 'somente_eu', nome: 'Somente eu', desc: 'Eu mesmo(a) cuido e registro' },
  { chave: 'so_cuidador', nome: 'Só cuidador(a)', desc: 'Um(a) cuidador(a) cuida; eu acompanho' },
  { chave: 'gestor_cuidador', nome: 'Eu + cuidador(a)', desc: 'Dividimos o cuidado' },
  { chave: 'gestor_rede', nome: 'Eu + Rede de Apoio', desc: 'Revezamos com parentes, amigos, vizinhos' },
  { chave: 'gestor_rede_cuidador', nome: 'Eu + Rede + cuidador(a)', desc: 'Todos participam' },
];
