import { useEffect, useState } from 'react';
import { api } from '../servicos';
import { ativarAvisos, jaPerguntouAvisos, marcarPerguntouAvisos, pushDisponivel } from '../plataforma';
import { useSessao } from '../sessao';
import { Folha, FolhaTopo } from './ui';

const TEXTO = 'Receber avisos no celular? Você fica sabendo de mensagens e de pedidos novos. Você decide o que aceitar.';

/**
 * Só no app Android (com push ligado no build). Explica ANTES da janela do Android
 * pedir a permissão: quem tem pouca prática com o celular costuma negar uma janela
 * que aparece sem contexto. Pergunta uma vez por aparelho; depois só registra.
 */
export function AvisosCelular() {
  const { eu } = useSessao();
  const pronto = !!eu && eu.perfil_completo && eu.aceitou_termos; // depois do onboarding
  return pronto ? <PerguntaAvisos /> : null;
}

function PerguntaAvisos() {
  const [aberta, setAberta] = useState(false);
  const registrar = (t: string) => api.registrarDispositivo(t, 'android');

  useEffect(() => {
    if (!pushDisponivel) return;
    if (jaPerguntouAvisos()) void ativarAvisos(registrar, false); // já respondeu: só renova o registro
    else setAberta(true);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const responder = (quer: boolean) => {
    marcarPerguntouAvisos();
    setAberta(false);
    if (quer) void ativarAvisos(registrar, true);
  };

  return (
    <Folha aberta={aberta} aoFechar={() => responder(false)} rotulo="Avisos no celular">
      <FolhaTopo e="🔔" titulo="Avisos no celular" ouvir={TEXTO} />
      <p style={{ margin: '0 0 1em' }}>{TEXTO}</p>
      <div className="pilha">
        <button className="botao largo" onClick={() => responder(true)}>🔔 Sim, receber</button>
        <button className="botao secundario largo" onClick={() => responder(false)}>Agora não</button>
      </div>
    </Folha>
  );
}
