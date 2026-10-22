/**
 * Diagnóstico do FTP (usado pelo GitHub quando o envio falha).
 * Usa a mesma biblioteca e o mesmo modo de TLS do FTP-Deploy-Action
 * ("loose": criptografa, mas não valida a cadeia do certificado).
 */
const ftp = require('basic-ftp');

const { S, U, P, W, PV, PROTO } = process.env;

async function listar(c, caminho) {
  try {
    const itens = await c.list(caminho);
    if (!itens.length) return console.log('   (vazia)');
    for (const i of itens.slice(0, 40)) console.log(`   ${i.isDirectory ? '📁' : '📄'} ${i.name}`);
    if (itens.length > 40) console.log(`   … e mais ${itens.length - 40}`);
  } catch (e) {
    console.log(`   não deu para listar: ${e.message}`);
  }
}

async function podeCriar(c, pasta) {
  const nome = `${pasta}teste-github-${Date.now()}`;
  try {
    await c.send(`MKD ${nome}`);
    await c.send(`RMD ${nome}`);
    console.log(`   OK: consegue criar pastas em '${pasta || '(pasta inicial)'}'`);
  } catch (e) {
    console.log(`   NÃO consegue criar pastas em '${pasta || '(pasta inicial)'}': ${e.message}`);
  }
}

(async () => {
  const c = new ftp.Client(30000);
  try {
    await c.access({
      host: S, user: U, password: P,
      secure: (PROTO || 'ftps') === 'ftps',
      secureOptions: { rejectUnauthorized: false },
    });
    console.log(`== Pasta onde o FTP começa (PWD): ${await c.pwd()}`);
    console.log('== O que existe nela =='); await listar(c, '');
    console.log('== Uma pasta acima (..) =='); await listar(c, '..');
    for (const p of ['www/', W, 'public_html/', 'ahvita.com/', 'ahvita.com/cuidados/']) {
      if (!p) continue;
      console.log(`== Conteúdo de '${p}' ==`); await listar(c, p);
    }
    console.log('== Testes de escrita ==');
    await podeCriar(c, '');
    await podeCriar(c, W || '');
    console.log(`(Pasta privada configurada: '${PV}')`);
  } catch (e) {
    console.log(`Não conectou: ${e.message}`);
  } finally {
    c.close();
  }
  console.log('Copie tudo a partir de "== Pasta onde o FTP começa" e mande para o Claude.');
})();
