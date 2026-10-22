<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;
use Cuidados\Support\Ulid;

/**
 * Alertas (painel do gestor e da empresa) e eventos de saída (outbox).
 * O outbox é processado por bin/agendador.php (cron). Hoje ele só marca
 * como entregue e prepara o envio; push/WhatsApp entram sem mudar a API.
 */
final class Avisos
{
    public static function alerta(string $pessoaId, string $tipo, ?string $referenciaId, string $texto, ?string $empresaId = null): void
    {
        $n = Bd::inserir('alertas', [
            'id' => Ulid::novo(),
            'pessoa_id' => $pessoaId,
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'referencia_id' => $referenciaId,
            'texto' => mb_substr($texto, 0, 255),
            'criado_em' => Tempo::agoraSql(),
        ], true);
        if ($n > 0) {
            foreach (self::gestores($pessoaId) as $g) {
                self::evento('alerta', $g, ['pessoa_id' => $pessoaId, 'tipo' => $tipo, 'texto' => $texto]);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    public static function evento(string $tipo, ?string $usuarioId, array $payload): void
    {
        Bd::inserir('eventos_saida', [
            'tipo' => $tipo,
            'usuario_id' => $usuarioId,
            'payload' => $payload,
            'criado_em' => Tempo::agoraSql(),
        ]);
    }

    /** @return list<string> */
    public static function gestores(string $pessoaId): array
    {
        return array_column(Bd::todos(
            "SELECT usuario_id FROM vinculos WHERE pessoa_id = :p AND papel = 'gestor' AND status = 'ativo'",
            ['p' => $pessoaId]
        ), 'usuario_id');
    }

    /**
     * Confirmação de ida: se o(a) cuidador(a) não respondeu até o prazo
     * combinado, gestor(a) e empresa recebem o alerta para organizar a
     * cobertura. Texto neutro: sem "falta", sem penalidade.
     * Roda no cron e também ao abrir os painéis (caso a hospedagem não tenha cron).
     */
    public static function verificarCobertura(): int
    {
        $agora = Tempo::agora();
        $pendentes = Bd::todos(
            "SELECT * FROM atendimentos
              WHERE status = 'aceito' AND confirmacao IS NULL AND alerta_cobertura_em IS NULL
                AND inicio > :min AND inicio <= :max",
            ['min' => $agora->modify('-12 hours')->format('Y-m-d H:i:s'), 'max' => $agora->modify('+48 hours')->format('Y-m-d H:i:s')]
        );
        $n = 0;
        foreach ($pendentes as $a) {
            $prazo = Tempo::utc($a['inicio'])->modify('-' . (int)$a['prazo_confirmacao_min'] . ' minutes');
            if ($agora < $prazo) continue;
            $marcou = Bd::exec(
                'UPDATE atendimentos SET alerta_cobertura_em = :a WHERE id = :id AND alerta_cobertura_em IS NULL',
                ['a' => $agora->format('Y-m-d H:i:s'), 'id' => $a['id']]
            )->rowCount();
            if ($marcou === 0) continue;
            $nome = Formata::pessoaPublica($a['cuidador_id'])['nome'] ?? 'O(a) cuidador(a)';
            $quando = Tempo::local(Tempo::utc($a['inicio']))->format('d/m \à\s H\hi');
            self::alerta($a['pessoa_id'], 'sem_confirmacao', $a['id'],
                "$nome ainda não confirmou o atendimento de $quando. Veja se precisa de cobertura.", $a['empresa_id']);
            $n++;
        }
        return $n;
    }
}
