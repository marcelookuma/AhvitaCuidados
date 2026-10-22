<?php
declare(strict_types=1);

namespace Cuidados\Modulos;

/** Textos curtos para o resumo automático ao finalizar o serviço. */
final class ItensResumo
{
    private const ROTULOS = [
        'remedio' => ['remédio', 'remédios'],
        'comida' => ['refeição', 'refeições'],
        'agua' => ['registro de água', 'registros de água'],
        'banheiro' => ['ida ao banheiro', 'idas ao banheiro'],
        'sono' => ['registro de sono', 'registros de sono'],
        'banho' => ['banho', 'banhos'],
        'humor' => ['registro de como está', 'registros de como está'],
        'sinais' => ['medição', 'medições'],
        'algo' => ['ocorrência', 'ocorrências'],
        'pele' => ['cuidado com a pele', 'cuidados com a pele'],
        'andar' => ['registro de movimento', 'registros de movimento'],
        'comportamento' => ['registro de comportamento', 'registros de comportamento'],
        'consulta' => ['consulta', 'consultas'],
        'saida' => ['saída', 'saídas'],
        'visita' => ['visita', 'visitas'],
        'gasto' => ['gasto', 'gastos'],
        'falta' => ['item acabando', 'itens acabando'],
        'casa' => ['verificação da casa', 'verificações da casa'],
        'aparelhos' => ['verificação de aparelhos', 'verificações de aparelhos'],
        'nota' => ['anotação', 'anotações'],
        'procedimento' => ['procedimento', 'procedimentos'],
    ];

    public static function rotulo(string $tipo, int $n): string
    {
        [$um, $varios] = self::ROTULOS[$tipo] ?? ['registro', 'registros'];
        return $n . ' ' . ($n === 1 ? $um : $varios);
    }
}
