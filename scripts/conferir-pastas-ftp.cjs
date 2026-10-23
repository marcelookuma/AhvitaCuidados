/**
 * Antes de enviar: confere se as pastas configuradas batem com o que o FTP mostra.
 * Evita repetir o erro de 22/10/2026: o FTP da KingHost já abre DENTRO da pasta
 * pública, e "www/" acabou criado como www/www (com a pasta privada exposta).
 */
const ftp = require('basic-ftp');
const { S, U, P, PROTO, W, PV } = process.env;
const R = process.env.R || './'; // raiz da conta, a partir de onde o FTP abre

function erro(msg) {
  console.log(`::error title=Pastas do FTP::${msg}`);
  process.exit(1);
}

(async () => {
  // O FTP da KingHost recusa algumas conexões de vez em quando ("(control socket)")
  // e aceita segundos depois: tenta até 4 vezes, esperando 15, 30 e 60 s.
  const espera = (s) => new Promise((ok) => setTimeout(ok, s * 1000));
  let c;
  for (const [i, pausa] of [0, 15, 30, 60].entries()) {
    if (pausa) { console.log(`Tentativa ${i + 1} em ${pausa} s…`); await espera(pausa); }
    c = new ftp.Client(30000);
    try {
      await c.access({
        host: String(S).split(':')[0], port: Number(String(S).split(':')[1] || 21), user: U, password: P,
        secure: (PROTO || 'ftps') === 'ftps', secureOptions: { rejectUnauthorized: false },
      });
      break;
    } catch (e) {
      c.close();
      console.log(`Não conectou (tentativa ${i + 1}): ${e.message || e.code || e}`);
      if (i === 3) erro(`Não conectou ao FTP em 4 tentativas: ${e.message}. Confira o bloqueio de IP internacional no painel.`);
    }
  }
  let nomes = [];
  try {
    nomes = (await c.list(R === './' ? '' : R)).map((i) => `${i.isDirectory ? '[pasta]' : ''}${i.name}`);
  } catch (e) {
    erro(`Não listou a pasta inicial do FTP: ${e.message}`);
  }
  c.close();
  const pastas = nomes.filter((n) => n.startsWith('[pasta]')).map((n) => n.slice(7));
  console.log(`Raiz da conta (${R}): ${nomes.join(', ') || '(vazia)'}`);
  const primeira = (W || './').replace(/^\.\//, '').split('/')[0];
  const temIndex = nomes.some((n) => /^index\.(html?|php)$/i.test(n));

  if (primeira === '') {
    // KINGHOST_PASTA_WWW = "/": o FTP abre na própria pasta pública
    if (pastas.includes('www') || pastas.includes('public_html')) {
      erro(`O FTP mostra a pasta "${pastas.includes('www') ? 'www' : 'public_html'}": a pasta pública é ela. Use KINGHOST_PASTA_WWW=/${pastas.includes('www') ? 'www' : 'public_html'}/ e uma pasta privada fora dela.`);
    }
    console.log('OK: o FTP abre dentro da pasta pública (KINGHOST_PASTA_WWW=/).');
  } else if (!pastas.includes(primeira)) {
    erro(`A pasta "${primeira}" (KINGHOST_PASTA_WWW=${W}) não existe na pasta inicial do FTP` +
      (temIndex ? ' e ali já há um index: o FTP abre DENTRO da pasta pública. Use KINGHOST_PASTA_WWW=/ .' : '. Confira o nome no FileZilla.'));
  } else if (temIndex) {
    erro(`A pasta inicial do FTP já tem um index (é a pasta pública do site) e também uma pasta "${primeira}" ` +
      `— provavelmente sobra de um envio anterior. Apague "${primeira}" no FileZilla e use KINGHOST_PASTA_WWW=/ .`);
  } else {
    console.log(`OK: a pasta pública "${primeira}" existe na pasta inicial do FTP.`);
  }
  console.log(`Pasta privada configurada: ${PV}`);
})();
