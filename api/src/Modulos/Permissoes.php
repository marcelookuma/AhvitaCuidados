<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

/**
 * Permissões por funcionalidade, definidas pelo(a) gestor(a) para cada vínculo.
 * O catálogo é o mesmo em packages/core/src/permissoes.ts (telas do gestor).
 */
final class Permissoes
{
    public const FUNCS = [
        'registrar',        // registrar cuidados (remédio, comida, água…)
        'ver_registros',    // linha do tempo
        'mensagens',        // conversa da pessoa cuidada
        'remedios_ver',
        'remedios_editar',
        'plano_ver',        // como cuidar
        'plano_editar',
        'ficha_emergencia', // ficha para o socorrista (alergias, convênio…)
        'fotos',            // ver fotos (pele, receita, comida)
        'atendimentos',     // propor atendimentos e acompanhar a confirmação
        'administrar',      // cadastro, vínculos, permissões e dados (LGPD)
    ];

    /** @return list<string> */
    public static function padrao(string $papel, bool $atuaComoCuidador = false): array
    {
        $p = match ($papel) {
            'gestor' => self::FUNCS,
            'cuidador' => ['registrar', 'ver_registros', 'mensagens', 'remedios_ver', 'plano_ver', 'ficha_emergencia', 'fotos'],
            // Rede de Apoio: vê e conversa; registrar só se o gestor ligar (rodízio).
            'rede' => ['ver_registros', 'mensagens', 'ficha_emergencia'],
            // Profissional: minimização de dados; o gestor amplia se quiser.
            'profissional' => ['ver_registros', 'remedios_ver', 'plano_ver', 'mensagens'],
            // Empresa: organiza atendimentos; não vê registros de saúde por padrão.
            'empresa' => ['atendimentos', 'plano_ver'],
            default => [],
        };
        if ($atuaComoCuidador) {
            $p = array_values(array_unique(array_merge($p, self::padrao('cuidador'))));
        }
        return $p;
    }

    /** @param mixed $lista @return list<string> */
    public static function limpar(mixed $lista, string $papel): array
    {
        $lista = is_array($lista) ? $lista : [];
        $ok = array_values(array_unique(array_filter($lista, fn($f) => is_string($f) && in_array($f, self::FUNCS, true))));
        // "administrar" só para gestor(a): evita entregar o controle dos dados por engano.
        if ($papel !== 'gestor') {
            $ok = array_values(array_diff($ok, ['administrar']));
        }
        return $ok;
    }
}
