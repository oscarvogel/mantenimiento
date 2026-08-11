<?php

declare(strict_types=1);

namespace App\Application\Assets\Attachment;

final readonly class ListEquipmentAttachmentsQuery
{
    public function __construct(
        public int $equipmentId,
        public int $page = 1,
        public int $perPage = 10,
    ) {
    }
}
