/** Leitura em voz alta (botão 🔊 Ouvir em todas as telas do(a) cuidador(a)). */
export function falar(texto: string) {
  try {
    const s = window.speechSynthesis;
    if (!s) return;
    s.cancel();
    const u = new SpeechSynthesisUtterance(texto.replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu, ''));
    u.lang = 'pt-BR';
    u.rate = 0.92;
    const voz = s.getVoices().find((v) => v.lang?.toLowerCase().startsWith('pt'));
    if (voz) u.voice = voz;
    s.speak(u);
  } catch { /* aparelho sem voz */ }
}

export function vozDisponivel(): boolean {
  return typeof window !== 'undefined' && 'speechSynthesis' in window;
}
