<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato entre los puertos de notificacion y sus adaptadores.
 *
 * Motivo (23/10/2026): en produccion (Ferozo) se subio un conjunto parcial de archivos del
 * contexto de notificaciones. La interfaz `WhatsAppNotificationDeliveryQueue` (de `main`) declara
 * `previewUserDailyDigests` y el adaptador (de otra rama) no lo implementaba. PHP no avisa al
 * desplegar: revienta en caliente, 148 veces, con
 * "Class CodeIgniterWhatsAppNotificationDeliveryQueue contains 1 abstract method", y deja el cron
 * de notificaciones y la campana del dashboard caidos.
 *
 * Este test recorre, por reflexion, cada puerto de `Application/Notifications/Port` y sus
 * implementaciones en `Infrastructure`, y falla si a un adaptador le falta un metodo del puerto.
 * Un deploy parcial se rompe en la suite, no en produccion.
 */
final class NotificationPortImplementationContractTest extends TestCase
{
    private const PORT_NAMESPACE = 'App\\Application\\Notifications\\Port';

    public function testEveryNotificationPortIsFullyImplementedByItsAdapters(): void
    {
        $failures = [];

        foreach ($this->portFiles() as $portFile) {
            $portClass = self::PORT_NAMESPACE . '\\' . basename($portFile, '.php');
            if (! interface_exists($portClass)) {
                $failures[] = "El puerto {$portClass} no se puede cargar.";

                continue;
            }

            $port = new ReflectionClass($portClass);
            foreach ($this->adaptersFor($portClass) as $adapterClass) {
                $adapter = new ReflectionClass($adapterClass);
                $missing = [];
                foreach ($port->getMethods() as $method) {
                    if (! $adapter->hasMethod($method->getName())) {
                        $missing[] = $method->getName();
                    }
                }
                if ($missing !== []) {
                    $failures[] = sprintf(
                        '%s no implementa %s (interfaz %s)',
                        $adapterClass,
                        implode(', ', $missing),
                        $portClass,
                    );
                }
            }
        }

        self::assertSame([], $failures, implode(PHP_EOL, $failures));
    }

    public function testWhatsAppDeliveryQueueAdapterImplementsEveryPortMethod(): void
    {
        $port = new ReflectionClass('App\\Application\\Notifications\\Port\\WhatsAppNotificationDeliveryQueue');
        $adapter = new ReflectionClass(
            'App\\Infrastructure\\Notifications\\CodeIgniterWhatsAppNotificationDeliveryQueue',
        );

        $missing = [];
        foreach ($port->getMethods() as $method) {
            if (! $adapter->hasMethod($method->getName())) {
                $missing[] = $method->getName();
            }
        }

        self::assertSame([], $missing, sprintf(
            'El adaptador de WhatsApp debe implementar todos los metodos del puerto. Faltan: %s',
            implode(', ', $missing),
        ));
        self::assertTrue(
            $adapter->implementsInterface('App\\Application\\Notifications\\Port\\WhatsAppNotificationDeliveryQueue'),
        );
    }

    /** @return list<string> */
    private function portFiles(): array
    {
        $files = glob(APPPATH . 'Application/Notifications/Port/*.php');

        return $files === false ? [] : $files;
    }

    /** @return list<class-string> */
    private function adaptersFor(string $portClass): array
    {
        $adapters = [];
        foreach ($this->infrastructureFiles() as $file) {
            $class = $this->classNameFor($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }
            if (! (new ReflectionClass($class))->implementsInterface($portClass)) {
                continue;
            }
            $adapters[] = $class;
        }

        return $adapters;
    }

    /** @return list<string> */
    private function infrastructureFiles(): array
    {
        $directory = APPPATH . 'Infrastructure';
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function classNameFor(string $file): ?string
    {
        $relative = substr($file, strlen(APPPATH));
        if ($relative === false || $relative === '') {
            return null;
        }

        $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

        return $class;
    }
}
