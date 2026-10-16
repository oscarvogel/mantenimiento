<?php

declare(strict_types=1);

namespace App\Application\TripMasters;

use App\Application\Identity\ActorContext;
use App\Application\TripMasters\Port\TsaTripMasterCatalogSeeder;
use DomainException;

final readonly class SeedTsaTripMasterCatalogs
{
    /** @var array<string,array{table:string,rows:list<array<string,string>>}> */
    private const CATALOGS = [
        'Tipos de carga' => [
            'table' => 'viajes_tipos_carga',
            'rows' => [
                ['nombre' => 'Carga general', 'descripcion' => 'Mercadería que no requiere condiciones especiales de transporte.'],
                ['nombre' => 'Carga refrigerada', 'descripcion' => 'Mercadería que requiere control de temperatura.'],
                ['nombre' => 'Carga a granel', 'descripcion' => 'Mercadería transportada sin embalaje individual.'],
                ['nombre' => 'Carga contenerizada', 'descripcion' => 'Mercadería transportada en contenedor.'],
                ['nombre' => 'Carga peligrosa', 'descripcion' => 'Mercadería sujeta a requisitos para cargas peligrosas.'],
                ['nombre' => 'Carga sobredimensionada', 'descripcion' => 'Mercadería que excede las dimensiones habituales de transporte.'],
            ],
        ],
        'Unidades de carga' => [
            'table' => 'viajes_unidades_carga',
            'rows' => [
                ['nombre' => 'Pallet', 'simbolo' => 'PLT'],
                ['nombre' => 'Bulto', 'simbolo' => 'BUL'],
                ['nombre' => 'Tonelada', 'simbolo' => 't'],
                ['nombre' => 'Kilogramo', 'simbolo' => 'kg'],
                ['nombre' => 'Metro cúbico', 'simbolo' => 'm³'],
            ],
        ],
        'Categorías de gastos' => [
            'table' => 'viajes_categorias_gasto',
            'rows' => [
                ['nombre' => 'Combustible', 'descripcion' => 'Combustible utilizado durante el viaje.'],
                ['nombre' => 'Peajes', 'descripcion' => 'Peajes y tasas de ruta.'],
                ['nombre' => 'Viáticos', 'descripcion' => 'Gastos diarios del personal durante el viaje.'],
                ['nombre' => 'Alojamiento', 'descripcion' => 'Hospedaje asociado al viaje.'],
                ['nombre' => 'Carga y descarga', 'descripcion' => 'Servicios de carga, descarga y manipulación.'],
                ['nombre' => 'Estacionamiento', 'descripcion' => 'Estacionamiento y guarda del vehículo.'],
                ['nombre' => 'Mantenimiento', 'descripcion' => 'Reparaciones y mantenimiento durante el viaje.'],
            ],
        ],
        'Medios de pago' => [
            'table' => 'viajes_medios_pago',
            'rows' => [
                ['nombre' => 'Efectivo', 'descripcion' => 'Pago en efectivo.'],
                ['nombre' => 'Transferencia bancaria', 'descripcion' => 'Pago mediante transferencia bancaria.'],
                ['nombre' => 'Tarjeta de débito', 'descripcion' => 'Pago con tarjeta de débito.'],
                ['nombre' => 'Tarjeta de crédito', 'descripcion' => 'Pago con tarjeta de crédito.'],
            ],
        ],
        'Tipos de documento' => [
            'table' => 'viajes_tipos_documento',
            'rows' => [
                ['nombre' => 'Factura', 'codigo' => 'FACTURA', 'descripcion' => 'Factura asociada al viaje o gasto.'],
                ['nombre' => 'Ticket', 'codigo' => 'TICKET', 'descripcion' => 'Ticket o comprobante simplificado.'],
                ['nombre' => 'Remito', 'codigo' => 'REMITO', 'descripcion' => 'Remito de entrega o traslado.'],
                ['nombre' => 'Recibo', 'codigo' => 'RECIBO', 'descripcion' => 'Recibo de pago.'],
                ['nombre' => 'Otro comprobante', 'codigo' => 'OTRO', 'descripcion' => 'Otro tipo de comprobante.'],
            ],
        ],
    ];

    public function __construct(private TsaTripMasterCatalogSeeder $seeder)
    {
    }

    /** @return array<string,int> */
    public function execute(ActorContext $actor): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('Esta operación requiere acceso de Superadministrador.');
        }

        return $this->seeder->insertMissing(self::CATALOGS, $actor->userId());
    }
}
