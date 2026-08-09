<?php
declare(strict_types=1);
namespace App\Application\Assets;
final readonly class CreateEquipmentModelCommand
{
    public function __construct(public int $brandId, public int $typeId, public string $name) {}
}
