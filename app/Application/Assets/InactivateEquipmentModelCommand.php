<?php
declare(strict_types=1);
namespace App\Application\Assets;
final readonly class InactivateEquipmentModelCommand { public function __construct(public int $modelId) {} }
