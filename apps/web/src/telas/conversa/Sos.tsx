import { telefone, type Pessoa } from '@cuidados/core';
import { Ouvir } from '../../componentes/ui';

/**
 * Emergência: funciona sem internet (a ficha fica guardada no aparelho).
 * Ligar 192, avisar quem acompanha e "o que fazer" falado.
 */
export function Sos({ pessoa, aoFechar, aoAvisar }: { pessoa: Pessoa | null; aoFechar: () => void; aoAvisar?: () => void }) {
  const f = pessoa?.ficha ?? {};
  const contato = f.contatos?.[0];
  return (
    <div className="sos-tela" role="dialog" aria-modal="true" aria-label="Emergência">
      <header className="topo">
        <h1>🚑 Emergência<small>{pessoa?.apelido ?? ''} · funciona sem internet</small></h1>
        <button className="botao secundario pequeno" onClick={aoFechar}>Voltar</button>
      </header>
      <div className="rolagem conteudo">
        <a className="sos-botao cheio" href="tel:192"><span className="e">📞</span><span>Ligar 192<small>SAMU · ambulância</small></span></a>
        {contato && (
          <a className="sos-botao" href={`tel:${contato.telefone}`}><span className="e">☎️</span><span>Ligar para {contato.nome}<small>{telefone(contato.telefone)}</small></span></a>
        )}
        {aoAvisar && (
          <button className="sos-botao" onClick={aoAvisar}><span className="e">📣</span><span>Avisar quem acompanha<small>Vai como alerta na conversa</small></span></button>
        )}
        <Ouvir rotulo="Ouvir o que fazer" texto="Mantenha a calma. Ligue 192. Não dê comida nem água. Se a pessoa caiu, não levante. Fale com ela até a ajuda chegar." />
        <h2 style={{ fontSize: '1em', margin: '.5em 0 0' }}>Mostre para o socorrista</h2>
        <div className="ficha">
          <div><b>Nome</b>{pessoa?.nome_completo ?? '—'}{pessoa ? ` · ${pessoa.idade} anos` : ''}</div>
          <div><b>Alergia</b><span className="alergia">{f.alergias ? f.alergias.toUpperCase() : 'Nenhuma informada'}</span></div>
          <div><b>Doenças</b>{f.condicoes || '—'}</div>
          <div><b>Convênio</b>{f.convenio || '—'}</div>
          <div><b>Endereço</b>{f.endereco || '—'}</div>
          {f.observacoes && <div><b>Observações</b>{f.observacoes}</div>}
        </div>
        {!pessoa?.ficha && <p className="sutil">Você não tem permissão para ver a ficha completa. Peça ao(à) gestor(a).</p>}
      </div>
    </div>
  );
}
