import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './app';
import { ProvedorSessao } from './sessao';
import { ProvedorLanche } from './componentes/ui';
import './estilos.css';

createRoot(document.getElementById('raiz')!).render(
  <StrictMode>
    <ProvedorSessao>
      <ProvedorLanche>
        <App />
      </ProvedorLanche>
    </ProvedorSessao>
  </StrictMode>,
);

// Abre sem internet (o app; os dados ficam na fila do aparelho até enviar).
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => void navigator.serviceWorker.register('/sw.js'));
}
