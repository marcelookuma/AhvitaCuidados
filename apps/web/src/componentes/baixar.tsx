import { useState } from 'react';
import { ErroApi } from '@cuidados/core';
import { entregarArquivo } from '../plataforma';
import { useLanche } from './ui';

/**
 * "Baixar uma cópia" (LGPD, direito de acesso). Igual na web e no app:
 * baixa com a sessão e entrega o arquivo (no app, pela tela "Compartilhar").
 */
export function BotaoBaixar({ rotulo, nome, baixar, className = 'botao secundario largo', style }: {
  rotulo: string; nome: string; baixar: () => Promise<Blob>; className?: string; style?: React.CSSProperties;
}) {
  const [baixando, setBaixando] = useState(false);
  const lanche = useLanche();
  return (
    <button type="button" className={className} style={style} disabled={baixando} onClick={async () => {
      setBaixando(true);
      try {
        await entregarArquivo(await baixar(), nome);
      } catch (e) {
        lanche(e instanceof ErroApi ? e.message : 'Não deu para baixar agora. Tente de novo.');
      } finally {
        setBaixando(false);
      }
    }}>{baixando ? 'Baixando…' : rotulo}</button>
  );
}
