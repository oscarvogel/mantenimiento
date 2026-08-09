<?php
declare(strict_types=1);
namespace App\Application\Assets;
final readonly class CreateBrandCommand { public function __construct(public string $name) {} }
