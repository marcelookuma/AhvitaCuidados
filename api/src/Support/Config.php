<?php
declare(strict_types=1);

namespace Cuidados\Support;

/**
 * Configuração lida de config/configuracao.php (fora da pasta pública).
 * Variáveis de ambiente com o mesmo nome em MAIÚSCULAS têm prioridade
 * (útil em contêiner/CI). Ex.: db.senha => DB_SENHA.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $valores = [];

    private const PADRAO = [
        'ambiente' => 'producao',            // producao | teste
        'url_app' => 'https://cuidados.ahvita.com',
        'fuso' => 'America/Sao_Paulo',
        'db.host' => 'localhost',
        'db.porta' => 3306,
        'db.nome' => 'cuidados',
        'db.usuario' => 'cuidados',
        'db.senha' => '',
        'midias.pasta' => null,              // padrão: <privado>/midias
        'midias.max_bytes' => 8_000_000,
        'sessao.dias' => 30,
        'sessao.cookie' => 'cuidados_sessao',
        'cors.origens' => [],                // ex.: ['http://localhost:5173']
        'codigo.canais' => ['email'],        // whatsapp, sms, email, log
        'codigo.teste_fixo' => null,         // só em ambiente teste
        'email.remetente' => 'Ahvita Cuidados <nao-responda@ahvita.com>',
        'twilio.sid' => '',
        'twilio.token' => '',
        'twilio.verify_sid' => '',
        'termos.versao' => '2026-10',
    ];

    public static function carregar(?string $arquivo = null): void
    {
        $arquivo ??= getenv('CUIDADOS_CONFIG') ?: CUIDADOS_RAIZ . '/config/configuracao.php';
        $dados = is_file($arquivo) ? (require $arquivo) : [];
        self::$valores = array_merge(self::PADRAO, self::achatar(is_array($dados) ? $dados : []));
        foreach (array_keys(self::$valores) as $chave) {
            $env = getenv(strtoupper(str_replace('.', '_', $chave)));
            if ($env !== false && $env !== '') {
                self::$valores[$chave] = is_array(self::PADRAO[$chave] ?? null)
                    ? array_filter(array_map('trim', explode(',', $env)))
                    : $env;
            }
        }
        if (self::$valores['midias.pasta'] === null) {
            self::$valores['midias.pasta'] = CUIDADOS_RAIZ . '/midias';
        }
    }

    public static function get(string $chave, mixed $padrao = null): mixed
    {
        return self::$valores[$chave] ?? $padrao;
    }

    public static function emTeste(): bool
    {
        return self::get('ambiente') === 'teste';
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private static function achatar(array $a, string $prefixo = ''): array
    {
        $saida = [];
        foreach ($a as $k => $v) {
            $chave = $prefixo === '' ? (string)$k : "$prefixo.$k";
            if (is_array($v) && !array_is_list($v)) {
                $saida += self::achatar($v, $chave);
            } else {
                $saida[$chave] = $v;
            }
        }
        return $saida;
    }
}
