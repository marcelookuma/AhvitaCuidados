/**
 * Diagnóstico do FTP (usado pelo GitHub quando o envio falha).
 * Mesma biblioteca e mesmo modo de TLS do FTP-Deploy-Action ("loose":
 * criptografa, sem validar a cadeia do certificado). Cada teste roda
 * separado: comando bloqueado pelo servidor não interrompe os outros.
 * O resultado sai no log e como anotação da execução (lida pela API do GitHub).
 */
const ftp = require('basic-ftp');

const { S, U, P, W, PV, PROTO } = process.env;
const linhas = [];
const log = (t) => { linhas.push(t); console.log(t); };

async function tentar(rotulo, fn) {
  try {
    const r = await fn();
    log(`${rotulo}: ${r ?? 'OK'}`);
  } catch (e) {
    log(`${rotulo}: ERRO ${e.code ?? ''} ${e.message}`.trim());
  }
}

async function listar(c, caminho) {
  const itens = await c.list(caminho);
  if (!itens.length) return '(vazia)';
  const nomes = itens.slice(0, 40).map((i) => `${i.isDirectory ? '[pasta]' : ''}${i.name}`);
  return nomes.join(', ') + (itens.length > 40 ? ` … +${itens.length - 40}` : '');
}

async function podeCriar(c, pasta) {
  const nome = `${pasta}teste-github-${Date.now()}`;
  await c.send(`MKD ${nome}`);
  await c.send(`RMD ${nome}`);
  return 'consegue criar pastas';
}

(async () => {
  const c = new ftp.Client(30000);
  try {
    await c.access({
      host: String(S).split(':')[0], port: Number(String(S).split(':')[1] || 21), user: U, password: P,
      secure: (PROTO || 'ftps') === 'ftps',
      secureOptions: { rejectUnauthorized: false },
    });
    log('Conectou e entrou.');
  } catch (e) {
    log(`Não conectou: ${e.message}`);
    console.log(`::notice title=Diagnostico FTP::${linhas.join('%0A')}`);
    return;
  }
  await tentar('PWD (pasta inicial)', () => c.pwd());
  await tentar('Lista da pasta inicial', () => listar(c, ''));
  await tentar('Lista de "."', () => listar(c, '.'));
  await tentar('Lista de ".."', () => listar(c, '..'));
  for (const p of ['www', W, 'cuidados', 'public_html', 'ahvita.com', 'ahvita.com/cuidados', 'web']) {
    if (p) await tentar(`Lista de "${p}"`, () => listar(c, p));
  }
  for (const p of ['', W, 'cuidados/']) {
    await tentar(`Criar pasta em "${p || '(inicial)'}"`, () => podeCriar(c, p));
  }
  log(`(Pasta privada configurada: "${PV}")`);
  c.close();

  // Anotação: o Claude lê pela API do GitHub. Sem senhas: o GitHub mascara os segredos.
  const texto = linhas.join('\n').replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
  console.log(`::notice title=Diagnostico FTP::${texto}`);
})();
