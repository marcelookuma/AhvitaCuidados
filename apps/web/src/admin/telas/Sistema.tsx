import { useState } from 'react';
import { get, post } from '../api';
import { Carregando, Erro, Etiqueta, Pares, Secao, Tabela, Titulo, bytes, dataHora, num, pode, useAdmin, useCarregar } from '../ui';

interface Sistema {
  versao_api: string; php: string; banco: string; ambiente: string; url_app: string; canais_codigo: string[]; google_ligado: boolean;
  termos_versao: string; guarda_log_dias: number;
  esquema: { aplicadas: { versao: string; aplicado_em: string | null }[]; pendentes: string[] };
  tarefas: { automaticas: boolean; ultima_execucao: string | null; chamada_externa: boolean };
  gravacao: { midias: boolean; logs: boolean };
  midias: { arquivos: number; bytes: number };
  fila_saida_pendente: number;
  codigos_24h: { pedidos: number; usados: number | null; esgotados: number | null };
  sessoes_ativas: { app: number; painel: number };
  tabelas: { nome: string; linhas: number; bytes: number }[];
  erros_php?: string[];
}

const NOME_CANAL: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-mail', log: 'Só no log (teste)' };

export function Sistema() {
  const { admin, avisar } = useAdmin();
  const { dados: d, erro, carregando, recarregar } = useCarregar(() => get<Sistema>('/admin/sistema'), []);
  const [rodando, setRodando] = useState(false);

  async function rodar() {
    setRodando(true);
    try {
      const r = await post<{ migracoes: string[]; alertas: number }>('/admin/sistema/tarefas');
      avisar(`Tarefas feitas. ${r.migracoes.length ? `Banco atualizado (${r.migracoes.join(', ')}). ` : ''}${r.alertas} alerta(s) de cobertura.`);
      recarregar();
    } catch (e: any) {
      avisar(e.message, 'erro');
    } finally {
      setRodando(false);
    }
  }

  const sim = (b: boolean, ok = 'Sim', nao = 'Não') => (b ? <Etiqueta tom="ok">{ok}</Etiqueta> : <Etiqueta tom="perigo">{nao}</Etiqueta>);

  return (
    <>
      <Titulo sub="Instalação na KingHost, banco e tarefas periódicas. Nenhuma senha ou chave aparece aqui."
        acoes={pode(admin, 'operador') && <button className="b b-secundario" onClick={() => void rodar()} disabled={rodando}>{rodando ? 'Rodando…' : 'Rodar tarefas agora'}</button>}>
        Sistema
      </Titulo>
      <Erro erro={erro} aoTentar={recarregar} />
      {carregando && !d && <Carregando />}
      {d && (
        <>
          {d.ambiente === 'teste' && <p className="aviso atencao">Ambiente de teste: o código de acesso aparece na tela. Nunca use com pessoas reais.</p>}
          {d.esquema.pendentes.length > 0 && <p className="aviso atencao">Atualizações do banco esperando: {d.esquema.pendentes.join(', ')}. Use Rodar tarefas agora.</p>}
          <div className="grade-2">
            <Secao titulo="Instalação">
              <Pares itens={[
                ['Endereço', d.url_app], ['Ambiente', d.ambiente === 'teste' ? 'Teste' : 'Produção'], ['Versão da API', d.versao_api],
                ['PHP', d.php], ['Banco', d.banco], ['Versão dos termos', d.termos_versao],
                ['Grava fotos e áudios', sim(d.gravacao.midias)], ['Grava log de erros', sim(d.gravacao.logs)],
              ]} />
            </Secao>
            <Secao titulo="Acesso e tarefas">
              <Pares itens={[
                ['Código de acesso por', d.canais_codigo.map((c) => NOME_CANAL[c] ?? c).join(', ')],
                ['Entrar com Google', sim(d.google_ligado, 'Ligado', 'Desligado')],
                ['Códigos pedidos em 24 h', `${num(d.codigos_24h.pedidos)} (${num(d.codigos_24h.usados ?? 0)} usados, ${num(d.codigos_24h.esgotados ?? 0)} com tentativas esgotadas)`],
                ['Sessões abertas', `${num(d.sessoes_ativas.app)} no app, ${num(d.sessoes_ativas.painel)} no painel`],
                ['Tarefas automáticas', d.tarefas.automaticas ? 'Pela própria API' : 'Só por cron ou chamada externa'],
                ['Última execução', dataHora(d.tarefas.ultima_execucao)],
                ['Avisos esperando envio', num(d.fila_saida_pendente)],
                ['Guarda do log de acessos', `${num(d.guarda_log_dias)} dias`],
                ['Fotos e áudios guardados', `${num(d.midias.arquivos)} arquivos, ${bytes(d.midias.bytes)}`],
              ]} />
            </Secao>
          </div>
          <Secao titulo="Atualizações do banco aplicadas">
            <ul className="lista-simples">{d.esquema.aplicadas.map((v) => <li key={v.versao}><code>{v.versao}</code> <span className="fraco">em {dataHora(v.aplicado_em)}</span></li>)}</ul>
          </Secao>
          <Secao titulo="Tamanho das tabelas">
            <Tabela linhas={d.tabelas.map((t) => ({ ...t, id: t.nome }))} colunas={[
              { titulo: 'Tabela', celula: (t) => <code>{t.nome}</code> },
              { titulo: 'Linhas (aprox.)', celula: (t) => num(t.linhas), classe: 'num' },
              { titulo: 'Espaço', celula: (t) => bytes(t.bytes), classe: 'num' },
            ]} />
          </Secao>
          {d.erros_php && (
            <Secao titulo="Últimos erros do PHP">
              {d.erros_php.length === 0 ? <p className="vazio">Nenhum erro registrado.</p> : <pre className="erros-php">{d.erros_php.join('\n')}</pre>}
            </Secao>
          )}
        </>
      )}
    </>
  );
}
