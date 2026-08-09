<?php
declare(strict_types=1);
namespace App\Application\Assets;
final readonly class InactivateBrandCommand { public function __construct(public int $brandId) {} }
