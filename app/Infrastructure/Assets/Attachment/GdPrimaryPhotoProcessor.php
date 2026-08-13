<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\Port\PrimaryPhotoProcessor;
use App\Application\Assets\Attachment\ProcessedPhotoThumbnail;
use DomainException;
use GdImage;
use RuntimeException;

final class GdPrimaryPhotoProcessor implements PrimaryPhotoProcessor
{
    public function __construct(private readonly int $maximumSide = 480, private readonly int $jpegQuality = 82)
    {
    }

    public function createThumbnail(string $sourcePath, string $mimeType): ?ProcessedPhotoThumbnail
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        $source = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
        if (! $source instanceof GdImage) {
            throw new DomainException('La imagen no se pudo decodificar de forma segura.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            throw new DomainException('La imagen no posee dimensiones válidas.');
        }
        $scale = min(1, $this->maximumSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $target instanceof GdImage
            || ! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($source);
            throw new RuntimeException('No se pudo normalizar la miniatura.');
        }
        $path = tempnam(sys_get_temp_dir(), 'mantenimiento-photo-');
        if ($path === false || ! imagejpeg($target, $path, $this->jpegQuality)) {
            imagedestroy($source);
            imagedestroy($target);
            throw new RuntimeException('No se pudo generar la miniatura privada.');
        }
        imagedestroy($source);
        imagedestroy($target);
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            @unlink($path);
            throw new RuntimeException('La miniatura generada está vacía.');
        }

        return new ProcessedPhotoThumbnail($path, 'image/jpeg', 'jpg', (int) $size);
    }
}
