import { useEffect, useState } from 'react';
import { fila } from '../servicos';
import { useSessao } from '../sessao';
import { useOnline } from '../hooks';
import { Folha, FolhaTopo } from './ui';
import { IcSair } from './icones';

/**
 * Botão "Sair" igual em todos os perfis (Cuidador(a), Gestor(a)/Rede de Apoio/
 * profissionais, Empresa) e em todas as etapas de cadastro.
 *
 * Sempre pede confirmação: para quem usa pouco o celular, sair
 * sem querer obriga a pedir um código novo. Se houver envios guardados no
 * aparelho (sem internet), avisa que eles se perdem e oferece esperar.
 *
 *  - "pequeno": cabeçalho dos painéis (gestor(a) e empresa)
 *  - "largo":   fim de página (aba "Eu" do(a) cuidador(a), cadastro, escolha de perfil)
 */
export function BotaoSair({ variante = 'largo', rotulo = 'Sair da conta' }: { variante?: 'pequeno' | 'largo'; rotulo?: string }) {
  const { sair } = useSessao();
  const online = useOnline();
  const [aberta, setAberta] = useState(false);
  const [saindo, setSaindo] = useState(false);
  const [pendentes, setPendentes] = useState(fila.pendentes().length);
  useEffect(() => fila.ouvir((p) => setPendentes(p.length)), []);

  // Ao abrir a confirmação com internet, tenta mandar o que estiver guardado.
  useEffect(() => { if (aberta && online && pendentes > 0) void fila.processar(); }, [aberta, online]); // eslint-disable-line react-hooks/exhaustive-deps

  const confirmar = async () => { setSaindo(true); try { await sair(); } finally { setSaindo(false); setAberta(false); } };
  const classe = variante === 'pequeno' ? 'botao fantasma pequeno botao-sair' : 'botao secundario largo botao-sair';
  const n = pendentes === 1 ? '1 envio guardado' : `${pendentes} envios guardados`;

  return (
    <>
      <button type="button" className={classe} onClick={() => setAberta(true)}>
        <IcSair /> {rotulo}
      </button>
      <Folha aberta={aberta} aoFechar={() => setAberta(false)} rotulo="Sair da conta">
        <FolhaTopo e="🚪" titulo="Sair da conta?"
          ouvir={pendentes > 0
            ? `Atenção. Você tem ${n} no celular que ainda não foram. Se sair agora, eles se perdem.`
            : 'Sair da conta? Para entrar de novo, você vai receber um código novo.'} />
        {pendentes > 0 ? (
          <div className="aviso-sair" role="alert">
            <b>🕓 Você tem {n} no celular.</b>
            <span>{online ? 'Estamos enviando. Espere um pouco.' : 'Ainda não foram: falta internet.'} Se sair agora, eles se perdem.</span>
          </div>
        ) : (
          <p className="sutil" style={{ margin: '0 0 .4em' }}>Para entrar de novo, você vai receber um código novo.</p>
        )}
        <div className="pilha">
          <button type="button" className="botao largo" onClick={() => setAberta(false)} autoFocus>
            {pendentes > 0 ? 'Esperar enviar' : 'Ficar'}
          </button>
          <button type="button" className={`botao largo botao-sair ${pendentes > 0 ? "perigo" : "secundario"}`} disabled={saindo} onClick={() => void confirmar()}>
            <IcSair /> {saindo ? 'Saindo…' : pendentes > 0 ? 'Sair mesmo assim' : 'Sair'}
          </button>
        </div>
      </Folha>
    </>
  );
}
