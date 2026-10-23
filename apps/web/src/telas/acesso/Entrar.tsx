import { nativo } from '../../plataforma';
import { useEffect, useRef, useState } from 'react';
import { telefone as fmtTel } from '@cuidados/core';
import { api } from '../../servicos';
import { navegar } from '../../rota';
import { useSessao } from '../../sessao';
import { ErroGeral, Ouvir, useAcao } from '../../componentes/ui';

/**
 * Entrar sem senha: celular (código por WhatsApp ou SMS), e-mail ou Google.
 * Duas telas curtas — "Entrar" e "Código" — como no wireframe de acesso.
 */
export function Entrar() {
  const { recarregar } = useSessao();
  const [etapa, setEtapa] = useState<'destino' | 'codigo'>('destino');
  const [tipo, setTipo] = useState<'celular' | 'email'>('celular');
  const [destino, setDestino] = useState('');
  const [canais, setCanais] = useState<string[]>([]);
  const [canalUsado, setCanalUsado] = useState('');
  const [codigo, setCodigo] = useState(['', '', '', '', '', '']);
  const [dicaTeste, setDicaTeste] = useState<string | null>(null);
  const [googleId, setGoogleId] = useState<string | null>(null);
  const caixas = useRef<(HTMLInputElement | null)[]>([]);

  useEffect(() => {
    api.canais().then((c) => {
      setCanais(c.canais);
      // App Android: o botão do Google (Identity Services) não funciona dentro de app; entra por código.
      setGoogleId(nativo ? null : c.google ?? null);
    }).catch(() => setCanais(['email']));
  }, []);
  const temCelular = canais.some((c) => ['whatsapp', 'sms', 'log'].includes(c));
  useEffect(() => { if (canais.length && !temCelular) setTipo('email'); }, [canais, temCelular]);

  const pedir = useAcao(async (canal?: string) => {
    const r = await api.pedirCodigo(destino, canal);
    setCanalUsado(r.canal);
    setDicaTeste(r.codigo_teste ?? null);
    setEtapa('codigo');
    setTimeout(() => caixas.current[0]?.focus(), 50);
  });

  const depoisDeEntrar = async () => {
    const eu = await recarregar();
    navegar(eu && (!eu.perfil_completo || !eu.aceitou_termos) ? '/bem-vindo' : '/', true);
  };

  const entrar = useAcao(async (c: string) => {
    await api.entrar(destino, c);
    await depoisDeEntrar();
  });

  const entrarGoogle = useAcao(async (credencial: string) => {
    await api.entrarGoogle(credencial);
    await depoisDeEntrar();
  });

  function digitar(i: number, v: string) {
    const so = v.replace(/\D/g, '');
    const novo = [...codigo];
    if (so.length > 1) {
      so.slice(0, 6).split('').forEach((d, k) => { novo[k] = d; });
    } else {
      novo[i] = so;
      if (so && i < 5) caixas.current[i + 1]?.focus();
    }
    setCodigo(novo);
    const junto = novo.join('');
    if (junto.length === 6) void entrar.executar(junto);
  }

  if (etapa === 'codigo') {
    const onde = canalUsado === 'whatsapp' ? 'no seu WhatsApp' : canalUsado === 'sms' ? 'por SMS' : canalUsado === 'email' ? 'no seu e-mail' : 'no aparelho';
    return (
      <main className="acesso">
        <button className="botao fantasma pequeno" style={{ alignSelf: 'flex-start' }} onClick={() => { setEtapa('destino'); setCodigo(['', '', '', '', '', '']); }}>← Voltar</button>
        <h1>Digite o código</h1>
        <p className="sutil">Mandamos 6 números {onde}: <b>{tipo === 'celular' ? fmtTel('+55' + destino.replace(/\D/g, '').replace(/^55/, '')) : destino}</b></p>
        <Ouvir texto={`Mandamos um código de 6 números ${onde}. Digite os números aqui.`} />
        <div className="codigo-caixas" role="group" aria-label="Código de 6 números">
          {codigo.map((d, i) => (
            <input key={i} ref={(el) => { caixas.current[i] = el; }} value={d} inputMode="numeric" autoComplete={i === 0 ? 'one-time-code' : 'off'}
              aria-label={`Número ${i + 1}`} maxLength={i === 0 ? 6 : 1}
              onChange={(e) => digitar(i, e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Backspace' && !d && i > 0) caixas.current[i - 1]?.focus(); }} />
          ))}
        </div>
        {dicaTeste && <p className="fraco" style={{ textAlign: 'center' }}>Ambiente de teste — código: <b>{dicaTeste}</b></p>}
        <ErroGeral erro={entrar.erro} />
        <button className="botao largo" disabled={entrar.enviando || codigo.join('').length < 6} onClick={() => entrar.executar(codigo.join(''))}>
          {entrar.enviando ? 'Entrando…' : 'Entrar'}
        </button>
        <button className="botao secundario largo" disabled={pedir.enviando} onClick={() => pedir.executar(canalUsado === 'whatsapp' ? 'sms' : undefined)}>
          {canalUsado === 'whatsapp' ? 'Não chegou? Mandar por SMS' : 'Mandar outro código'}
        </button>
        <ErroGeral erro={pedir.erro} />
      </main>
    );
  }

  return (
    <main className="acesso">
      <div className="marca">
        <img src="/icone.svg" alt="" />
        <div><b>Ahvita Cuidados</b><span>O cuidado combinado, simples como uma conversa.</span></div>
      </div>
      <h1>Entrar</h1>
      {temCelular && (
        <div className="chips" role="group" aria-label="Como você quer entrar">
          <button className="chip" aria-pressed={tipo === 'celular'} onClick={() => { setTipo('celular'); setDestino(''); }}>📱 Celular</button>
          <button className="chip" aria-pressed={tipo === 'email'} onClick={() => { setTipo('email'); setDestino(''); }}>✉️ E-mail</button>
        </div>
      )}
      <form className="pilha" onSubmit={(e) => { e.preventDefault(); void pedir.executar(); }}>
        <div className="campo">
          <label htmlFor="destino">{tipo === 'celular' ? 'Seu celular com DDD' : 'Seu e-mail'}</label>
          <input id="destino" value={destino} onChange={(e) => setDestino(e.target.value)} required
            inputMode={tipo === 'celular' ? 'tel' : 'email'} type={tipo === 'celular' ? 'tel' : 'email'}
            autoComplete={tipo === 'celular' ? 'tel-national' : 'email'} placeholder={tipo === 'celular' ? '(11) 98765-4321' : 'voce@email.com'} />
          <span className="ajuda">Sem senha: mandamos um código para você entrar.</span>
          {pedir.erro?.campos?.destino && <span className="erro">{pedir.erro.campos.destino}</span>}
        </div>
        {!pedir.erro?.campos?.destino && <ErroGeral erro={pedir.erro} />}
        <button className="botao largo" disabled={pedir.enviando || destino.trim().length < 6}>
          {pedir.enviando ? 'Enviando…' : tipo === 'celular' ? '💬 Receber código' : '✉️ Receber código'}
        </button>
      </form>
      {googleId && (
        <>
          <div className="ou" aria-hidden="true"><span>ou</span></div>
          <BotaoGoogle clientId={googleId} aoReceber={(c) => void entrarGoogle.executar(c)} />
          {entrarGoogle.enviando && <p className="sutil" style={{ textAlign: 'center' }}>Entrando…</p>}
          <ErroGeral erro={entrarGoogle.erro} />
        </>
      )}
      <p className="fraco">
        Ao entrar você concorda com os <a href="/termos.html" target="_blank" rel="noreferrer">Termos de uso</a> e a{' '}
        <a href="/privacidade.html" target="_blank" rel="noreferrer">Política de privacidade</a>. Dados de saúde são protegidos pela LGPD.
      </p>
    </main>
  );
}

/* ---------- Entrar com Google (Google Identity Services) ---------- */

declare global {
  interface Window { google?: any }
}

let scriptGoogle: Promise<void> | null = null;
function carregarGoogle(): Promise<void> {
  if (window.google?.accounts?.id) return Promise.resolve();
  scriptGoogle ??= new Promise<void>((ok, falhou) => {
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = () => ok();
    s.onerror = () => { scriptGoogle = null; falhou(new Error('google')); };
    document.head.appendChild(s);
  });
  return scriptGoogle;
}

/** Botão oficial do Google (exigência da marca). Sem internet ou bloqueado: some sem atrapalhar. */
function BotaoGoogle({ clientId, aoReceber }: { clientId: string; aoReceber: (credencial: string) => void }) {
  const alvo = useRef<HTMLDivElement>(null);
  const retorno = useRef(aoReceber);
  retorno.current = aoReceber;
  const [falhou, setFalhou] = useState(false);

  useEffect(() => {
    let ativo = true;
    carregarGoogle().then(() => {
      if (!ativo || !alvo.current) return;
      const g = window.google.accounts.id;
      g.initialize({
        client_id: clientId,
        callback: (r: { credential?: string }) => { if (r.credential) retorno.current(r.credential); },
        auto_select: false,
        cancel_on_tap_outside: true,
        ux_mode: 'popup',
      });
      const largura = Math.min(400, Math.max(200, Math.round(alvo.current.getBoundingClientRect().width || 320)));
      g.renderButton(alvo.current, { type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'pill', logo_alignment: 'center', width: largura, locale: 'pt-BR' });
    }).catch(() => { if (ativo) setFalhou(true); });
    return () => { ativo = false; };
  }, [clientId]);

  if (falhou) return null;
  return <div ref={alvo} className="botao-google" aria-label="Continuar com o Google" />;
}
