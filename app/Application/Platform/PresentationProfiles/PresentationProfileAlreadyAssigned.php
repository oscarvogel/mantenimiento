<?php

declare(strict_types=1);

namespace App\Application\Platform\PresentationProfiles;

use DomainException;

final class PresentationProfileAlreadyAssigned extends DomainException
{
    public function __construct()
    {
        parent::__construct('Ese perfil de presentación ya está asignado a otra empresa.');
    }
}
