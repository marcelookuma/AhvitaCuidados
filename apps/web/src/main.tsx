import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './app';
import { AvisosCelular } from './componentes/avisos-celular';
import { ProvedorSessao } from './sessao';
import { ProvedorLanche } from './componentes/ui';
import { migrarRotaAntiga } from './rota';
import { iniciarPlataforma, nativo } from './plataforma';
import './estilos.css';

migrarRotaAntiga();

// No app Android, lê a sessão guardada no cofre antes de abrir as telas.
void iniciarPlataforma().finally(() => {
  createRoot(document.getElementById('raiz')!).render(
    <StrictMode>
      <ProvedorSessao>
        <ProvedorLanche>
          <App />
          <AvisosCelular />
        </ProvedorLanche>
      </ProvedorSessao>
    </StrictMode>,
  );
});

// Web: abre sem internet (os dados ficam na fila do aparelho até enviar).
// App Android: as telas já vêm dentro do app; não precisa de service worker.
if (!nativo && 'serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => void navigator.serviceWorker.register('/sw.js'));
}
