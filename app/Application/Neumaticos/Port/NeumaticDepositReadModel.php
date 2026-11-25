<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Lectura de los depósitos de neumáticos: existencias, movimientos y los
 * neumáticos que se pueden mover. Siempre con la empresa y las sucursales del
 * actor (`null` = todas las de la empresa).
 */
interface NeumaticDepositReadModel
{
    /**
     * Depósitos activos con cuántos neumáticos tienen hoy.
     *
     * @param list<int>|null $branchIds
     * @return list<array{id: int, name: string, branchName: string, location: string, tires: int, negative: int}>
     */
    public function deposits(int $companyId, ?array $branchIds): array;

    /**
     * Existencias distintas de cero, de un depósito o de todos.
     *
     * @param list<int>|null $branchIds
     * @return list<array{depositId: int, depositName: string, neumaticId: int, number: string, status: string, description: string, quantity: int, updatedAt: string|null}>
     */
    public function stock(int $companyId, ?array $branchIds, ?int $depositId): array;

    /**
     * Últimos movimientos, del más reciente al más antiguo.
     *
     * @param list<int>|null $branchIds
     * @return list<array{at: string, type: string, depositName: string, neumaticId: int, number: string, quantity: int, sheetNumber: string|null, notes: string|null, userName: string|null}>
     */
    public function movements(int $companyId, ?array $branchIds, ?int $depositId, int $limit): array;

    /**
     * Neumáticos del alcance del actor que se pueden elegir en un movimiento.
     *
     * @param list<int>|null $branchIds
     * @return list<array{id: int, number: string, status: string, description: string}>
     */
    public function tires(int $companyId, ?array $branchIds): array;
}
