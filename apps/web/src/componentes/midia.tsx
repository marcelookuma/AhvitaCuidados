import { useEffect, useRef, useState } from 'react';
import { api } from '../servicos';
import { IcPausar, IcTocar } from './icones';

/**
 * Foto protegida: o navegador manda o cookie de sessão; a API confere o
 * acesso antes de entregar. Fotos pendentes (sem internet) vêm de um Blob local.
 */
export function Foto({ midiaId, blob, alt }: { midiaId?: string | null; blob?: Blob; alt: string }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (blob) {
      const u = URL.createObjectURL(blob);
      setUrl(u);
      return () => URL.revokeObjectURL(u);
    }
    setUrl(midiaId ? api.urlMidia(midiaId) : null);
  }, [midiaId, blob]);
  if (!url) return null;
  return <img className="foto" src={url} alt={alt} loading="lazy" />;
}

export function Audio({ midiaId, blob }: { midiaId?: string | null; blob?: Blob }) {
  const ref = useRef<HTMLAudioElement>(null);
  const [tocando, setTocando] = useState(false);
  const [dur, setDur] = useState('');
  const [src, setSrc] = useState<string>('');
  useEffect(() => {
    if (blob) {
      const u = URL.createObjectURL(blob);
      setSrc(u);
      return () => URL.revokeObjectURL(u);
    }
    setSrc(midiaId ? api.urlMidia(midiaId) : '');
  }, [midiaId, blob]);
  return (
    <div className="audio">
      <button className="tocar" aria-label={tocando ? 'Pausar áudio' : 'Ouvir áudio'} onClick={() => {
        const a = ref.current;
        if (!a) return;
        if (a.paused) void a.play(); else a.pause();
      }}>{tocando ? <IcPausar /> : <IcTocar />}</button>
      <span className="onda" aria-hidden />
      <span className="dur">{dur}</span>
      <audio ref={ref} src={src} preload="metadata"
        onPlay={() => setTocando(true)} onPause={() => setTocando(false)} onEnded={() => setTocando(false)}
        onLoadedMetadata={(e) => {
          const d = e.currentTarget.duration;
          if (Number.isFinite(d)) setDur(`${Math.floor(d / 60)}:${String(Math.round(d % 60)).padStart(2, '0')}`);
        }} />
    </div>
  );
}

/** Diminui a foto antes de enviar (menos dados móveis; sem metadados de localização). */
export async function prepararFoto(arquivo: File, max = 1600): Promise<Blob> {
  try {
    const bmp = await createImageBitmap(arquivo);
    const fator = Math.min(1, max / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * fator);
    c.height = Math.round(bmp.height * fator);
    c.getContext('2d')!.drawImage(bmp, 0, 0, c.width, c.height);
    return await new Promise((ok) => c.toBlob((b) => ok(b ?? arquivo), 'image/jpeg', 0.82));
  } catch {
    return arquivo;
  }
}

/** Gravação de áudio (botão 🎤). */
export function useGravador() {
  const [gravando, setGravando] = useState(false);
  const [segundos, setSegundos] = useState(0);
  const rec = useRef<MediaRecorder | null>(null);
  const partes = useRef<Blob[]>([]);
  const timer = useRef<number>();
  const resolver = useRef<((b: Blob | null) => void) | null>(null);

  async function comecar(): Promise<boolean> {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const tipo = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find((t) => MediaRecorder.isTypeSupported?.(t));
      const r = new MediaRecorder(stream, tipo ? { mimeType: tipo } : undefined);
      partes.current = [];
      r.ondataavailable = (e) => e.data.size && partes.current.push(e.data);
      r.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        const b = partes.current.length ? new Blob(partes.current, { type: r.mimeType || 'audio/webm' }) : null;
        resolver.current?.(b);
      };
      r.start();
      rec.current = r;
      setSegundos(0);
      setGravando(true);
      timer.current = window.setInterval(() => setSegundos((s) => {
        if (s >= 119) { void parar(true); }
        return s + 1;
      }), 1000);
      return true;
    } catch {
      return false;
    }
  }

  function parar(enviar: boolean): Promise<Blob | null> {
    window.clearInterval(timer.current);
    setGravando(false);
    return new Promise((ok) => {
      resolver.current = (b) => ok(enviar ? b : null);
      rec.current?.state === 'recording' ? rec.current.stop() : ok(null);
    });
  }

  return { gravando, segundos, comecar, parar };
}
