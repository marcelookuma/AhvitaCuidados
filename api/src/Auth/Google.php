<?php
declare(strict_types=1);

namespace Cuidados\Auth;

use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;

/**
 * "Entrar com Google" (Google Identity Services).
 * O navegador recebe do Google um ID token (JWT assinado em RS256) e o envia
 * para POST /auth/google. Aqui conferimos assinatura, emissor, público (client_id)
 * e validade — sem biblioteca externa, só openssl + curl (ok na KingHost).
 * Os certificados públicos do Google ficam em cache local pelo tempo que o Google indica.
 */
final class Google
{
    private const URL_CERTS = 'https://www.googleapis.com/oauth2/v1/certs';
    private const EMISSORES = ['accounts.google.com', 'https://accounts.google.com'];
    private const FOLGA_SEG = 120; // diferença de relógio aceitável

    public static function ligado(): bool
    {
        return self::clientId() !== '';
    }

    public static function clientId(): string
    {
        return trim((string)Config::get('google.client_id', ''));
    }

    /**
     * @return array{sub:string,email:?string,email_verificado:bool,nome:?string}
     */
    public static function verificar(string $jwt, ?int $agora = null): array
    {
        if (!self::ligado()) {
            throw new ErroHttp(404, 'google_desligado', 'Entrar com Google não está disponível.');
        }
        $agora ??= time();
        $partes = explode('.', trim($jwt));
        if (count($partes) !== 3) throw self::invalido('formato');
        [$h64, $p64, $s64] = $partes;
        $cab = json_decode(self::b64url($h64), true);
        $dados = json_decode(self::b64url($p64), true);
        $assinatura = self::b64url($s64);
        if (!is_array($cab) || !is_array($dados) || $assinatura === '') throw self::invalido('decodificação');
        if (($cab['alg'] ?? '') !== 'RS256' || !is_string($cab['kid'] ?? null)) throw self::invalido('algoritmo');

        $cert = self::certificado($cab['kid']);
        $chave = openssl_pkey_get_public($cert);
        if ($chave === false || openssl_verify("$h64.$p64", $assinatura, $chave, OPENSSL_ALGO_SHA256) !== 1) {
            throw self::invalido('assinatura');
        }
        if (!in_array($dados['iss'] ?? '', self::EMISSORES, true)) throw self::invalido('emissor');
        $aud = $dados['aud'] ?? '';
        if (!is_string($aud) || !hash_equals(self::clientId(), $aud)) throw self::invalido('público');
        $exp = (int)($dados['exp'] ?? 0);
        $iat = (int)($dados['iat'] ?? 0);
        if ($exp < $agora - self::FOLGA_SEG || $iat > $agora + self::FOLGA_SEG) throw self::invalido('validade');
        $sub = (string)($dados['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 64) throw self::invalido('sub');

        $email = isset($dados['email']) ? mb_strtolower(trim((string)$dados['email'])) : null;
        $verificado = ($dados['email_verified'] ?? false) === true || ($dados['email_verified'] ?? '') === 'true';
        $nome = isset($dados['name']) ? mb_substr(trim((string)$dados['name']), 0, 120) : null;
        return ['sub' => $sub, 'email' => $email ?: null, 'email_verificado' => $verificado, 'nome' => $nome ?: null];
    }

    private static function certificado(string $kid): string
    {
        $certs = self::certificados(false);
        if (!isset($certs[$kid])) {
            $certs = self::certificados(true); // o Google troca as chaves de tempos em tempos
        }
        if (!isset($certs[$kid])) throw self::invalido('chave desconhecida');
        return $certs[$kid];
    }

    /** @return array<string,string> kid => certificado PEM */
    private static function certificados(bool $renovar): array
    {
        // Só no ambiente de teste: certificados de um arquivo (testes automáticos sem internet).
        $arqTeste = (string)Config::get('google.certificados_arquivo', '');
        if ($arqTeste !== '' && Config::emTeste()) {
            $d = json_decode((string)@file_get_contents($arqTeste), true);
            return is_array($d) ? $d : [];
        }

        $cache = self::arquivoCache();
        if (!$renovar && $cache !== null && is_file($cache)) {
            $c = json_decode((string)@file_get_contents($cache), true);
            if (is_array($c) && ($c['expira'] ?? 0) > time() && is_array($c['certs'] ?? null)) return $c['certs'];
        }

        $ch = curl_init(self::URL_CERTS);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamCab = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($resp === false || $status !== 200) {
            error_log('[cuidados] google: não baixou os certificados (HTTP ' . $status . ')');
            throw new ErroHttp(424, 'google_fora', 'Não conseguimos falar com o Google agora. Entre com celular ou e-mail.');
        }
        $certs = json_decode(substr((string)$resp, $tamCab), true);
        if (!is_array($certs) || $certs === []) {
            throw new ErroHttp(424, 'google_fora', 'Não conseguimos falar com o Google agora. Entre com celular ou e-mail.');
        }
        $maxAge = preg_match('/max-age=(\d+)/i', substr((string)$resp, 0, $tamCab), $m) ? (int)$m[1] : 3600;
        if ($cache !== null) {
            @file_put_contents($cache, json_encode(['expira' => time() + min($maxAge, 86400), 'certs' => $certs]), LOCK_EX);
        }
        return $certs;
    }

    private static function arquivoCache(): ?string
    {
        foreach ([CUIDADOS_RAIZ . '/cache', CUIDADOS_RAIZ . '/logs', sys_get_temp_dir()] as $pasta) {
            if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
            if (is_dir($pasta) && is_writable($pasta)) return $pasta . '/google-certificados.json';
        }
        return null;
    }

    private static function b64url(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    private static function invalido(string $motivo): ErroHttp
    {
        error_log('[cuidados] google: token recusado (' . $motivo . ')');
        return new ErroHttp(422, 'google_invalido', 'Não deu para entrar com o Google. Tente de novo ou use celular ou e-mail.');
    }
}
