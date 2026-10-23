<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\ReadingComplianceReportMessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Texto del aviso que lee la gerencia.
 *
 * Es el mensaje que la dueña ve en su celular: no es un reporte tecnico. Dice
 * cuantos equipos son, cuanto hace que no suben lectura, cual era el ultimo
 * kilometraje, quien los maneja y donde puede actuar.
 */
final class ReadingComplianceReportMessageBuilderTest extends TestCase
{
    public function testItExplainsTheImpactAndPointsToTheReadingControl(): void
    {
        $message = $this->builder()->build(
            'ES',
            'Transportes del Sur',
            [$this->item()],
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            false,
            true,
        );

        self::assertStringContainsString('Transportes del Sur', $message);
        self::assertStringContainsString('1 equipo sin lectura hace más de 7 días', $message);
        self::assertStringContainsString('no se puede calcular ni controlar su mantenimiento', $message);
        self::assertStringContainsString('https://app.example.com/mantenimiento/lecturas/control', $message);
        self::assertStringContainsString('Cuando el equipo suba su lectura, este aviso deja de aparecer', $message);
    }

    public function testItUsesThePluralForMoreThanOneEquipment(): void
    {
        $message = $this->builder()->build(
            'ES',
            'Transportes del Sur',
            [$this->item(), $this->item('CAM-02', 'AC532DD', '2026-10-14 10:40:00', 1031491)],
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            false,
            true,
        );

        self::assertStringContainsString('2 equipos sin lectura hace más de 7 días', $message);
    }

    public function testItSaysWhenAnEquipmentNeverReported(): void
    {
        $message = $this->builder()->build(
            'ES',
            'Transportes del Sur',
            [[
                'code' => 'CAM-09',
                'plate' => 'CAM09',
                'driver' => '',
                'lastReadingAt' => null,
                'lastKm' => null,
                'daysSince' => null,
            ]],
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            false,
            true,
        );

        self::assertStringContainsString('nunca registró lectura', $message);
        self::assertStringNotContainsString('Última lectura', $message);
    }

    public function testItSummarisesTheRestWhenThereAreManyEquipment(): void
    {
        $items = [];
        for ($i = 0; $i < 14; $i++) {
            $items[] = $this->item('CAM-' . $i, 'AA' . $i . 'BB', '2026-10-12 09:58:00', 1000 + $i);
        }

        $message = $this->builder()->build(
            'ES',
            'Transportes del Sur',
            $items,
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            false,
            true,
        );

        self::assertStringContainsString('14 equipos sin lectura hace más de 7 días', $message);
        self::assertStringContainsString('y 4 equipo(s) más', $message);
    }

    public function testItWritesInPortugueseForBrazilianCompanies(): void
    {
        $message = $this->builder()->build(
            'PT',
            'Transportes do Sul',
            [$this->item()],
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            false,
            true,
        );

        self::assertStringContainsString('Transportes do Sul', $message);
        self::assertStringContainsString('1 equipamento sem leitura há mais de 7 dias', $message);
        self::assertStringContainsString('Manutenção', $message);
    }

    public function testPilotModeIsAnnouncedInTheMessage(): void
    {
        $message = $this->builder()->build(
            'ES',
            'Transportes del Sur',
            [$this->item()],
            7,
            'https://app.example.com/mantenimiento/lecturas/control',
            true,
            true,
        );

        self::assertStringContainsString('PRUEBA CONTROLADA', $message);
        self::assertStringContainsString('NO ENVIADO AL DESTINATARIO REAL', $message);
    }

    /**
     * @param array{code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int,daysSince:?int} $item
     */
    private function item(string $code = 'AB499OK', string $plate = 'AB499OK', string $lastReadingAt = '2026-10-12 09:58:00', int $lastKm = 1002337): array
    {
        return [
            'code' => $code,
            'plate' => $plate,
            'driver' => 'ISMAEL HORACIO DUBE',
            'lastReadingAt' => $lastReadingAt,
            'lastKm' => $lastKm,
            'daysSince' => 11,
        ];
    }

    private function builder(): ReadingComplianceReportMessageBuilder
    {
        return new ReadingComplianceReportMessageBuilder();
    }
}
