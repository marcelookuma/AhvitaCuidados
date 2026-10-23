<?php
declare(strict_types=1);

namespace Cuidados\Modulos\Admin;

use Cuidados\Auth\AdminSessao;
use Cuidados\Http\Requisicao;
use Cuidados\Modulos\Controlador;
use Cuidados\Support\Auditoria;
use Cuidados\Support\ErroHttp;
use Cuidados\Support\LogAcesso;
use Cuidados\Support\Tempo;

/**
 * Base dos controladores do painel /admin.
 *
 * Princípios (LGPD — dados de saúde, inclusive de menores):
 *  - Minimização: o painel NÃO mostra conteúdo de saúde (registros, mensagens,
 *    ficha de emergência, plano, remédios, fotos, áudios). Só cadastro, vínculos,
 *    contagens e metadados de acesso.
 *  - Toda leitura do painel vira linha no log de acessos; toda mudança vai para a
 *    trilha de auditoria encadeada com a justificativa ("motivo") de quem fez.
 *  - Três papéis: leitura < operador < dono.
 *
 * Risco trabalhista: o painel não calcula horas, tempo conectado, atrasos nem
 * ranking de cuidador(a). O log serve à segurança da plataforma e não é
 * compartilhado com gestores(as) ou empresas.
 */
abstract class AdminBase extends Controlador
{
    /** @var array<string,mixed>|null */
    protected ?array $admin = null;
    protected ?string $adminSessao = null;

    public function __construct(Requisicao $req, ?array $usuario = null, ?string $sessaoId = null)
    {
        parent::__construct($req, null, null);
    }

    /** @return array<string,mixed> */
    protected function adm(string $papelMinimo = 'leitura'): array
    {
        if ($this->admin === null) {
            $s = AdminSessao::atual($this->req);
            if ($s === null) throw ErroHttp::naoAutenticado();
            $this->admin = $s['admin'];
            $this->adminSessao = $s['sessao_id'];
            LogAcesso::admin((string)$this->admin['id'], $this->adminSessao);
            Auditoria::contexto(null, $this->req->ip);
        }
        if (AdminSessao::nivel((string)$this->admin['papel']) < AdminSessao::nivel($papelMinimo)) {
            throw ErroHttp::proibido(match ($papelMinimo) {
                'dono' => 'Só quem é dono(a) do painel pode fazer isso.',
                default => 'Seu acesso ao painel é só de leitura.',
            });
        }
        return $this->admin;
    }

    /** Justificativa obrigatória para ações sensíveis (fica na auditoria). */
    protected function motivo(): string
    {
        $m = trim((string)($this->corpo()['motivo'] ?? ''));
        if (mb_strlen($m) < 10) {
            throw ErroHttp::validacao(['motivo' => 'Explique o motivo (mínimo 10 letras)'], 'Explique o motivo desta ação.');
        }
        return mb_substr($m, 0, 300);
    }

    /** @param array<string,mixed> $dados */
    protected function auditar(string $acao, ?string $entidade, ?string $entidadeId, ?string $pessoaId = null, array $dados = []): void
    {
        $a = $this->adm();
        Auditoria::contexto(null, $this->req->ip);
        Auditoria::registrar('admin.' . $acao, $entidade, $entidadeId, $pessoaId, ['admin' => $a['id'], 'admin_email' => $a['email']] + $dados);
    }

    /** @return array{0:int,1:int,2:int} pagina, por_pagina, offset */
    protected function paginacao(int $padrao = 50): array
    {
        $pp = max(1, min(200, (int)($this->req->q('por_pagina') ?? $padrao)));
        $p = max(1, (int)($this->req->q('pagina') ?? 1));
        return [$p, $pp, ($p - 1) * $pp];
    }

    /** Texto de busca seguro para LIKE. */
    protected function busca(): ?string
    {
        $q = trim((string)$this->req->q('q', ''));
        if ($q === '') return null;
        return '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
    }

    /** Data local (AAAA-MM-DD) → DATETIME UTC do início do dia (ou do dia seguinte, se $fim). */
    protected function dataLocal(?string $d, bool $fim = false): ?string
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
        $dt = new \DateTimeImmutable($d . ' 00:00:00', Tempo::fuso());
        if ($fim) $dt = $dt->modify('+1 day');
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    protected static function iso(?string $sql): ?string
    {
        return Tempo::sqlParaIso($sql);
    }

    protected static function mascararCpf(?string $cpf): ?string
    {
        return $cpf === null || strlen($cpf) !== 11 ? null : '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**';
    }

    protected static function idade(?string $nascimento): ?int
    {
        if ($nascimento === null) return null;
        return (new \DateTimeImmutable($nascimento))->diff(Tempo::local(Tempo::agora()))->y;
    }

    protected static function faixa(?int $idade): string
    {
        return match (true) {
            $idade === null => 'sem_data',
            $idade < 12 => 'crianca',
            $idade < 18 => 'adolescente',
            $idade < 60 => 'adulto',
            default => 'idoso',
        };
    }
}
