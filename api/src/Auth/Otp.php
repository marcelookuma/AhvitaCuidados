<?php
declare(strict_types=1);

namespace Cuidados\Auth;

use Cuidados\Support\Bd;
use Cuidados\Support\Config;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;
use Cuidados\Support\Valida;

/**
 * Entrar com código de 6 números (sem senha: mais simples para quem tem
 * baixo letramento digital, e sem senha fraca para vazar).
 */
final class Otp
{
    private const VALIDADE_MIN = 10;
    private const MAX_TENTATIVAS = 5;

    /** @return array{destino:string,tipo:string} */
    public static function normalizarDestino(string $bruto): array
    {
        if (str_contains($bruto, '@')) {
            $e = Valida::email($bruto);
            if ($e === null) throw ErroHttp::validacao(['destino' => 'E-mail inválido']);
            return ['destino' => $e, 'tipo' => 'email'];
        }
        $t = Valida::telefone($bruto);
        if ($t === null) throw ErroHttp::validacao(['destino' => 'Celular inválido. Use DDD + número']);
        return ['destino' => $t, 'tipo' => 'telefone'];
    }

    /** @return array{canal:string,expira_em:string,codigo_teste?:string} */
    public static function solicitar(string $destinoBruto, ?string $canalPedido, ?string $ip): array
    {
        ['destino' => $destino, 'tipo' => $tipo] = self::normalizarDestino($destinoBruto);
        $canais = Canais::disponiveis();
        $canal = $tipo === 'email' ? 'email' : ($canalPedido ?? (in_array('whatsapp', $canais, true) ? 'whatsapp' : 'sms'));
        if (!in_array($canal, $canais, true)) {
            if (in_array('log', $canais, true)) {
                $canal = 'log';
            } else {
                throw ErroHttp::validacao(['canal' => 'Esse jeito de receber o código não está disponível'], 'Escolha outra forma de receber o código.');
            }
        }

        $agora = Tempo::agora();
        $umaHora = $agora->modify('-1 hour')->format('Y-m-d H:i:s');
        $recente = Bd::valor('SELECT MAX(criado_em) FROM otp_codigos WHERE destino = :d', ['d' => $destino]);
        if ($recente !== null && Tempo::utc($recente) > $agora->modify('-30 seconds')) {
            throw ErroHttp::muitas('Espere 30 segundos para pedir outro código.');
        }
        if ((int)Bd::valor('SELECT COUNT(*) FROM otp_codigos WHERE destino = :d AND criado_em > :h', ['d' => $destino, 'h' => $umaHora]) >= 5
            || ($ip !== null && (int)Bd::valor('SELECT COUNT(*) FROM otp_codigos WHERE ip = :ip AND criado_em > :h', ['ip' => $ip, 'h' => $umaHora]) >= 20)) {
            throw ErroHttp::muitas();
        }

        $fixo = Config::emTeste() ? Config::get('codigo.teste_fixo') : null;
        $codigo = $fixo !== null ? (string)$fixo : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $verificaExterna = Canais::verificacaoExterna($canal);
        if ($verificaExterna) {
            Canais::iniciarVerificacao($canal, $destino);
        } else {
            Canais::enviarCodigo($canal, $destino, $codigo);
        }

        $expira = $agora->modify('+' . self::VALIDADE_MIN . ' minutes')->format('Y-m-d H:i:s');
        Bd::inserir('otp_codigos', [
            'id' => Ulid::novo(),
            'destino' => $destino,
            'canal' => $canal,
            'codigo_hash' => $verificaExterna ? 'externo' : self::hash($destino, $codigo),
            'ip' => $ip,
            'expira_em' => $expira,
            'criado_em' => $agora->format('Y-m-d H:i:s'),
        ]);
        $r = ['canal' => $canal, 'expira_em' => Tempo::sqlParaIso($expira)];
        if (Config::emTeste() && !$verificaExterna) $r['codigo_teste'] = $codigo;
        return $r;
    }

    /** Retorna o destino normalizado quando o código confere. */
    public static function verificar(string $destinoBruto, string $codigo): string
    {
        ['destino' => $destino] = self::normalizarDestino($destinoBruto);
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        $o = Bd::um(
            'SELECT * FROM otp_codigos WHERE destino = :d AND usado_em IS NULL AND expira_em > :a ORDER BY criado_em DESC LIMIT 1',
            ['d' => $destino, 'a' => Tempo::agoraSql()]
        );
        if ($o === null) {
            throw ErroHttp::validacao(['codigo' => 'Código vencido. Peça outro'], 'Código vencido.');
        }
        if ((int)$o['tentativas'] >= self::MAX_TENTATIVAS) {
            throw ErroHttp::muitas('Muitas tentativas. Peça um código novo.');
        }
        Bd::exec('UPDATE otp_codigos SET tentativas = tentativas + 1 WHERE id = :id', ['id' => $o['id']]);
        $ok = $o['codigo_hash'] === 'externo'
            ? Canais::conferirVerificacao($o['canal'], $destino, $codigo)
            : hash_equals($o['codigo_hash'], self::hash($destino, $codigo));
        if (!$ok) {
            throw ErroHttp::validacao(['codigo' => 'Código errado. Confira e tente de novo'], 'Código errado.');
        }
        Bd::exec('UPDATE otp_codigos SET usado_em = :a WHERE id = :id', ['a' => Tempo::agoraSql(), 'id' => $o['id']]);
        return $destino;
    }

    private static function hash(string $destino, string $codigo): string
    {
        $segredo = (string)Config::get('app.segredo', '');
        return hash_hmac('sha256', $destino . '|' . $codigo, $segredo !== '' ? $segredo : 'cuidados');
    }
}
