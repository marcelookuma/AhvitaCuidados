<?php
declare(strict_types=1);

namespace Cuidados\Modulos\Admin;

use Cuidados\Http\Resposta;
use Cuidados\Support\Bd;
use Cuidados\Support\Tempo;

/**
 * Consulta do log de acessos/usos e da trilha de auditoria.
 * Uso exclusivo da equipe Ahvita (segurança, suporte e pedidos de titulares).
 * Não deve ser repassado a gestores(as) ou empresas para acompanhar cuidadores(as).
 */
final class LogsAdmin extends AdminBase
{
    private const MAX_CSV = 50000;

    /** GET /admin/logs?ator=&usuario=&admin=&pessoa=&empresa=&perfil=&evento=&rota=&status=&ip=&de=&ate=&pagina= */
    public function listar(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao(100);
        [$w, $p] = $this->filtros();
        $total = (int)Bd::valor("SELECT COUNT(*) FROM log_acessos l WHERE $w", $p);
        $linhas = Bd::todos($this->sql($w) . " ORDER BY l.id DESC LIMIT $pp OFFSET $off", $p);
        return Resposta::json(['itens' => array_map([self::class, 'formatar'], $linhas), 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp]);
    }

    /** GET /admin/logs/csv (mesmos filtros) — até 50 mil linhas */
    public function csv(): Resposta
    {
        $this->adm('operador');
        [$w, $p] = $this->filtros();
        $linhas = Bd::todos($this->sql($w) . ' ORDER BY l.id DESC LIMIT ' . self::MAX_CSV, $p);
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF"); // BOM: o Excel abre com acentos certos
        fputcsv($f, ['id', 'data_hora_local', 'ator', 'usuario_id', 'usuario', 'admin', 'perfil', 'evento', 'metodo', 'rota', 'recurso_id',
            'pessoa_id', 'empresa_id', 'status', 'duracao_ms', 'ip', 'cliente', 'dispositivo'], ';');
        foreach ($linhas as $l) {
            fputcsv($f, [$l['id'], Tempo::local(Tempo::utc($l['criado_em']))->format('Y-m-d H:i:s'), $l['ator'], $l['usuario_id'], $l['usuario_nome'],
                $l['admin_email'], $l['perfil'], $l['evento'], $l['metodo'], $l['rota'], $l['recurso_id'], $l['pessoa_id'], $l['empresa_id'],
                $l['status'], $l['duracao_ms'], $l['ip'], $l['cliente'], $l['dispositivo']], ';');
        }
        rewind($f);
        $csv = (string)stream_get_contents($f);
        fclose($f);
        $this->auditar('log_exportado', 'log_acessos', null, null, ['linhas' => count($linhas)]);
        return Resposta::download($csv, 'text/csv; charset=utf-8', 'log-acessos-' . Tempo::local(Tempo::agora())->format('Ymd-His') . '.csv');
    }

    /** GET /admin/auditoria?acao=&usuario=&pessoa=&entidade=&de=&ate=&pagina= */
    public function auditoria(): Resposta
    {
        $this->adm();
        [$pag, $pp, $off] = $this->paginacao(100);
        $onde = ['1 = 1'];
        $p = [];
        if (($a = trim((string)$this->req->q('acao', ''))) !== '') { $onde[] = 'a.acao LIKE :acao'; $p['acao'] = addcslashes($a, '%_') . '%'; }
        foreach (['usuario' => 'a.usuario_id', 'pessoa' => 'a.pessoa_id', 'entidade' => 'a.entidade_id'] as $k => $col) {
            if (($v = trim((string)$this->req->q($k, ''))) !== '') { $onde[] = "$col = :$k"; $p[$k] = $v; }
        }
        if (($de = $this->dataLocal($this->req->q('de'))) !== null) { $onde[] = 'a.criado_em >= :de'; $p['de'] = $de; }
        if (($ate = $this->dataLocal($this->req->q('ate'), true)) !== null) { $onde[] = 'a.criado_em < :ate'; $p['ate'] = $ate; }
        $w = implode(' AND ', $onde);
        $total = (int)Bd::valor("SELECT COUNT(*) FROM auditoria a WHERE $w", $p);
        $linhas = Bd::todos(
            "SELECT a.*, COALESCE(u.apelido, u.nome) AS usuario_nome, pc.apelido AS pessoa_apelido
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id LEFT JOIN pessoas_cuidadas pc ON pc.id = a.pessoa_id
              WHERE $w ORDER BY a.id DESC LIMIT $pp OFFSET $off",
            $p
        );
        $acoes = array_column(Bd::todos('SELECT DISTINCT acao FROM auditoria ORDER BY acao'), 'acao');
        return Resposta::json([
            'itens' => array_map(fn($l) => [
                'id' => (int)$l['id'], 'criado_em' => self::iso($l['criado_em']), 'acao' => $l['acao'],
                'usuario_id' => $l['usuario_id'], 'usuario' => $l['usuario_nome'], 'entidade' => $l['entidade'], 'entidade_id' => $l['entidade_id'],
                'pessoa_id' => $l['pessoa_id'], 'pessoa' => $l['pessoa_apelido'],
                'dados' => $l['dados'] === null ? null : json_decode((string)$l['dados'], true), 'ip' => $l['ip'], 'hash' => substr((string)$l['hash'], 0, 12),
            ], $linhas),
            'acoes' => $acoes, 'total' => $total, 'pagina' => $pag, 'por_pagina' => $pp,
        ]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function filtros(): array
    {
        $onde = ['1 = 1'];
        $p = [];
        $iguais = ['ator' => 'l.ator', 'usuario' => 'l.usuario_id', 'admin' => 'l.admin_id', 'pessoa' => 'l.pessoa_id',
            'empresa' => 'l.empresa_id', 'perfil' => 'l.perfil', 'evento' => 'l.evento', 'ip' => 'l.ip', 'metodo' => 'l.metodo', 'sessao' => 'l.sessao_id'];
        foreach ($iguais as $k => $col) {
            $v = trim((string)$this->req->q($k, ''));
            if ($v !== '') { $onde[] = "$col = :f_$k"; $p["f_$k"] = mb_substr($v, 0, 80); }
        }
        if (($r = trim((string)$this->req->q('rota', ''))) !== '') { $onde[] = 'l.rota LIKE :f_rota'; $p['f_rota'] = '%' . addcslashes(mb_substr($r, 0, 80), '%_') . '%'; }
        $st = trim((string)$this->req->q('status', ''));
        if (preg_match('/^[1-5]xx$/', $st)) { $onde[] = 'l.status >= :st1 AND l.status < :st2'; $p['st1'] = (int)$st[0] * 100; $p['st2'] = (int)$st[0] * 100 + 100; }
        elseif (preg_match('/^\d{3}$/', $st)) { $onde[] = 'l.status = :st'; $p['st'] = (int)$st; }
        elseif ($st === 'erro') { $onde[] = 'l.status >= 400'; }
        if (($de = $this->dataLocal($this->req->q('de'))) !== null) { $onde[] = 'l.criado_em >= :de'; $p['de'] = $de; }
        if (($ate = $this->dataLocal($this->req->q('ate'), true)) !== null) { $onde[] = 'l.criado_em < :ate'; $p['ate'] = $ate; }
        return [implode(' AND ', $onde), $p];
    }

    private function sql(string $w): string
    {
        return "SELECT l.*, COALESCE(u.apelido, u.nome) AS usuario_nome, ad.email AS admin_email, pc.apelido AS pessoa_apelido,
                       COALESCE(e.nome_fantasia, e.razao_social) AS empresa_nome
                  FROM log_acessos l
                  LEFT JOIN usuarios u ON u.id = l.usuario_id
                  LEFT JOIN admin_usuarios ad ON ad.id = l.admin_id
                  LEFT JOIN pessoas_cuidadas pc ON pc.id = l.pessoa_id
                  LEFT JOIN empresas e ON e.id = l.empresa_id
                 WHERE $w";
    }

    /** @param array<string,mixed> $l @return array<string,mixed> */
    public static function formatar(array $l): array
    {
        return [
            'id' => (int)$l['id'], 'criado_em' => self::iso($l['criado_em']), 'ator' => $l['ator'],
            'usuario_id' => $l['usuario_id'], 'usuario' => $l['usuario_nome'], 'admin_id' => $l['admin_id'], 'admin' => $l['admin_email'],
            'sessao_id' => $l['sessao_id'], 'perfil' => $l['perfil'], 'evento' => $l['evento'], 'metodo' => $l['metodo'], 'rota' => $l['rota'],
            'recurso_id' => $l['recurso_id'], 'pessoa_id' => $l['pessoa_id'], 'pessoa' => $l['pessoa_apelido'],
            'empresa_id' => $l['empresa_id'], 'empresa' => $l['empresa_nome'], 'status' => $l['status'] === null ? null : (int)$l['status'],
            'duracao_ms' => $l['duracao_ms'] === null ? null : (int)$l['duracao_ms'], 'ip' => $l['ip'], 'cliente' => $l['cliente'], 'dispositivo' => $l['dispositivo'],
        ];
    }
}
