import { StrictMode, useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@fontsource/atkinson-hyperlegible/400.css';
import '@fontsource/atkinson-hyperlegible/700.css';
import './admin.css';
import { ErroAdmin, get, post, type Admin } from './api';
import { CtxAdmin, NOME_PAPEL_ADMIN, pode, useRota } from './ui';
import { Visao } from './telas/Visao';
import { Usuarios } from './telas/Usuarios';
import { Pessoas } from './telas/Pessoas';
import { Empresas } from './telas/Empresas';
import { Convites } from './telas/Convites';
import { Logs } from './telas/Logs';
import { Auditoria } from './telas/Auditoria';
import { Administradores } from './telas/Administradores';
import { Sistema } from './telas/Sistema';

const SECOES = [
  { rota: '/', nome: 'Visão geral', icone: 'M3 13h8V3H3zm10 8h8V11h-8zM3 21h8v-6H3zm10-18v6h8V3z' },
  { rota: '/usuarios', nome: 'Usuários', icone: 'M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm0 2c-2.7 0-6 1.3-6 3.5V19h7v-2.5c0-1 .4-2 1.2-2.7A9 9 0 0 0 8 13zm8 0c-3 0-7 1.5-7 4v2h14v-2c0-2.5-4-4-7-4z' },
  { rota: '/pessoas', nome: 'Pessoas cuidadas', icone: 'M12 21s-7-4.4-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.6-9.5 9-9.5 9z' },
  { rota: '/empresas', nome: 'Empresas', icone: 'M4 21V5l8-3 8 3v16h-6v-5h-4v5zm4-12h2V7H8zm6 0h2V7h-2zm-6 4h2v-2H8zm6 0h2v-2h-2z' },
  { rota: '/convites', nome: 'Convites', icone: 'M3 6h18v12H3zm2 2v.5l7 4.5 7-4.5V8l-7 4.5z' },
  { rota: '/logs', nome: 'Log de acessos', icone: 'M5 3h11l3 3v15H5zm3 6h8V7H8zm0 4h8v-2H8zm0 4h5v-2H8z' },
  { rota: '/auditoria', nome: 'Auditoria', icone: 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4z' },
  { rota: '/administradores', nome: 'Administradores', icone: 'M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm7 3-1.5-.9a5 5 0 0 0 0-1.2L19 12l-1-1.7-1.6.7a5 5 0 0 0-1-.6L15 8.7h-2l-.4 1.7c-.4.1-.7.3-1 .6L10 10.3 9 12l1.5.9v.3C9.5 13 8.2 13 7.8 13 4.9 13 2 14.4 2 16.5V19h9.6a6 6 0 0 0 1.4 1.4V21h2v-.6l1.6.7 1-1.7-1.4-1z' },
  { rota: '/sistema', nome: 'Sistema', icone: 'M19.4 13a7.6 7.6 0 0 0 0-2l2.1-1.6-2-3.4-2.5 1a7 7 0 0 0-1.7-1L15 3.3h-4L10.7 6a7 7 0 0 0-1.7 1l-2.5-1-2 3.4L6.6 11a7.6 7.6 0 0 0 0 2l-2.1 1.6 2 3.4 2.5-1a7 7 0 0 0 1.7 1l.3 2.7h4l.3-2.7a7 7 0 0 0 1.7-1l2.5 1 2-3.4zM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z' },
];

function Painel() {
  const [admin, setAdmin] = useState<Admin | null>(null);
  const [carregando, setCarregando] = useState(true);
  const [aviso, setAviso] = useState<{ t: string; tipo: 'ok' | 'erro' } | null>(null);
  const [rota] = useRota();

  useEffect(() => {
    get<{ admin: Admin }>('/admin/eu').then((r) => setAdmin(r.admin)).catch(() => setAdmin(null)).finally(() => setCarregando(false));
    const perdeu = () => setAdmin(null);
    window.addEventListener('admin:sessao-perdida', perdeu);
    return () => window.removeEventListener('admin:sessao-perdida', perdeu);
  }, []);

  const avisar = useCallback((t: string, tipo: 'ok' | 'erro' = 'ok') => {
    setAviso({ t, tipo });
    window.setTimeout(() => setAviso(null), 4000);
  }, []);

  if (carregando) return <p className="carregando tela-cheia">Abrindo o painel…</p>;
  if (!admin) return <Entrar aoEntrar={setAdmin} />;

  const raiz = '/' + (rota.split('/')[1] ?? '');
  const secao = SECOES.find((s) => s.rota === raiz) ?? SECOES[0];
  const Tela = {
    '/': Visao, '/usuarios': Usuarios, '/pessoas': Pessoas, '/empresas': Empresas, '/convites': Convites,
    '/logs': Logs, '/auditoria': Auditoria, '/administradores': Administradores, '/sistema': Sistema,
  }[secao.rota] ?? Visao;

  async function sair() {
    try { await post('/admin/sair'); } catch { /* já saiu */ }
    setAdmin(null);
  }

  return (
    <CtxAdmin.Provider value={{ admin, avisar }}>
      <div className="casca">
        <nav className="lateral" aria-label="Seções do painel">
          <div className="marca">
            <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 28s-11-6.6-11-15A6.5 6.5 0 0 1 16 8.6 6.5 6.5 0 0 1 27 13c0 8.4-11 15-11 15z" /></svg>
            <span><b>Ahvita Cuidados</b>Administração</span>
          </div>
          <ul>
            {SECOES.map((s) => (
              <li key={s.rota}>
                <a href={'#' + s.rota} aria-current={s.rota === secao.rota ? 'page' : undefined}>
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d={s.icone} /></svg>
                  {s.nome}
                </a>
              </li>
            ))}
          </ul>
          <div className="quem">
            <span className="quem-email" title={admin.email}>{admin.nome ?? admin.email}</span>
            <span className="quem-papel">{NOME_PAPEL_ADMIN[admin.papel]}{!pode(admin, 'operador') && ', só consulta'}</span>
            <button className="b b-lateral" onClick={() => void sair()}>Sair</button>
          </div>
        </nav>
        <main className="conteudo" id="conteudo">
          <Tela />
        </main>
        {aviso && <div className={`lanche lanche-${aviso.tipo}`} role="status">{aviso.t}</div>}
      </div>
    </CtxAdmin.Provider>
  );
}

/* ---------------- Entrar com Google ---------------- */

declare global {
  interface Window { google?: any }
}

function Entrar({ aoEntrar }: { aoEntrar: (a: Admin) => void }) {
  const alvo = useRef<HTMLDivElement>(null);
  const [cfg, setCfg] = useState<{ google: string | null; configurado: boolean } | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [entrando, setEntrando] = useState(false);

  useEffect(() => {
    get('/admin/config').then(setCfg).catch((e) => setErro(e instanceof ErroAdmin ? e.message : 'Não foi possível falar com o servidor.'));
  }, []);

  useEffect(() => {
    if (!cfg?.google || !alvo.current) return;
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = () => {
      window.google.accounts.id.initialize({
        client_id: cfg.google,
        ux_mode: 'popup',
        callback: async (r: { credential: string }) => {
          setEntrando(true);
          setErro(null);
          try {
            const res = await post<{ admin: Admin }>('/admin/entrar', { credencial: r.credential });
            aoEntrar(res.admin);
          } catch (e) {
            setErro(e instanceof Error ? e.message : String(e));
          } finally {
            setEntrando(false);
          }
        },
      });
      window.google.accounts.id.renderButton(alvo.current, { theme: 'outline', size: 'large', text: 'signin_with', shape: 'rectangular', locale: 'pt-BR', width: 300 });
    };
    s.onerror = () => setErro('O botão do Google não carregou. Confira se algum bloqueador está impedindo accounts.google.com.');
    document.head.appendChild(s);
    return () => { s.remove(); };
  }, [cfg?.google]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <main className="entrar">
      <div className="entrar-caixa">
        <svg viewBox="0 0 32 32" className="entrar-marca" aria-hidden="true"><path d="M16 28s-11-6.6-11-15A6.5 6.5 0 0 1 16 8.6 6.5 6.5 0 0 1 27 13c0 8.4-11 15-11 15z" /></svg>
        <h1>Painel de administração</h1>
        <p>Acesso restrito à equipe Ahvita. Entre com a conta Google cadastrada pelo(a) dono(a) do painel.</p>
        {cfg && !cfg.google && <p className="aviso erro">O login com Google não está ligado no servidor. Preencha <code>google.client_id</code> em <code>configuracao.php</code>.</p>}
        {cfg && cfg.google && !cfg.configurado && <p className="aviso atencao">Ninguém tem acesso ainda. Coloque seu e-mail em <code>admin.donos</code> na configuração do servidor.</p>}
        <div ref={alvo} className="entrar-google" />
        {entrando && <p className="carregando">Conferindo seu acesso…</p>}
        {erro && <p className="aviso erro" role="alert">{erro}</p>}
        <p className="entrar-nota">Cada acesso e cada consulta ficam registrados no log, com data, hora e IP.</p>
      </div>
    </main>
  );
}

createRoot(document.getElementById('raiz')!).render(
  <StrictMode>
    <Painel />
  </StrictMode>,
);
