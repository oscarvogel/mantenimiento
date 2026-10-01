<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\RunNotificationDispatch;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * #313 El informe gerencial debe distinguir dos dominios que el producto antes
 * reportaba mezclados bajo "VENCIMIENTOS VENCIDOS" / "VENCIMIENTOS PROXIMOS".
 *
 * Por que este test NO puede ser un grep del codigo: el correo que el usuario
 * recibio el 01/10/2026 seguia mostrando las tarjetas viejas aunque
 * ScheduleManagementReports ya emitiera las etiquetas nuevas. Motivo: el circuito
 * real no es lineal.
 *
 *   SuperAdmin::testCompanyManagementReport
 *     -> ScheduleManagementReports::queueTest   (genera el RESUMEN y lo congela)
 *     -> notificacion_empresa_entregas (fila PENDIENTE)
 *     -> RunNotificationDispatch::dispatchCompanyEmail
 *          -> dueCompany() devuelve TODAS las filas PENDIENTE de la empresa,
 *             no solo la recien creada
 *          -> sendDigest() usa SOLO $notifications[0] para el HTML gerencial
 *     -> managementReportHtml (convierte lineas "label: valor" en tarjetas)
 *
 * Si queda una fila gerencial vieja pendiente (un dia anterior que no se envio,
 * un REINTENTO, o la propia cola del dia), $notifications[0] es esa fila vieja y
 * el HTML sale con las etiquetas de antes. Estos tests atacan exactamente esa
 * condicion, con dobles en los puertos y sin base de datos.
 */
final class ManagementReportExpirationSeparationTest extends TestCase
{
    private const DOCUMENT_EXPIRED = 'Documentación vencida';
    private const DOCUMENT_UPCOMING = 'Documentación próxima (30 días)';
    private const PREVENTIVE_EXPIRED = 'Preventivos vencidos';
    private const PREVENTIVE_UPCOMING = 'Preventivos próximos';

    /**
     * Criterio A y E: el HTML del MISMO circuito que usa "Probar informe diario"
     * expone las cuatro metricas y no vuelve a las etiquetas ambiguas.
     */
    public function testManualDailyReportHtmlExposesTheFourSeparatedMetrics(): void
    {
        $html = $this->renderManagementReport($this->dailyReportSummary());

        self::assertStringContainsString(self::DOCUMENT_EXPIRED, $html);
        self::assertStringContainsString(self::DOCUMENT_UPCOMING, $html);
        self::assertStringContainsString(self::PREVENTIVE_EXPIRED, $html);
        self::assertStringContainsString(self::PREVENTIVE_UPCOMING, $html);

        // E: las etiquetas ambiguas desaparecieron del correo final.
        self::assertStringNotContainsString('VENCIMIENTOS VENCIDOS', $html);
        self::assertStringNotContainsString('VENCIMIENTOS PRÓXIMOS', $html);
    }

    /**
     * Criterio B y C: tener 3 documentos vencidos NO implica 3 preventivos
     * vencidos. Las metricas viajan en lineas independientes, cada una con su
     * propia fuente.
     */
    public function testThreeExpiredDocumentsDoNotImplyThreeExpiredPreventives(): void
    {
        $html = $this->renderManagementReport(
            "Empresa: Demo\n"
            . self::DOCUMENT_EXPIRED . ': 3' . "\n"
            . self::DOCUMENT_UPCOMING . ': 0' . "\n"
            . self::PREVENTIVE_EXPIRED . ': 0' . "\n"
            . self::PREVENTIVE_UPCOMING . ': 0' . "\n",
        );

        self::assertSame(1, $this->cardCount($html, self::DOCUMENT_EXPIRED));
        self::assertStringContainsString($this->cardValue($html, self::DOCUMENT_EXPIRED), $html);

        // La tarjeta preventiva existe y vale 0, no 3.
        $preventiveCard = $this->cardValue($html, self::PREVENTIVE_EXPIRED);
        self::assertSame('0', $preventiveCard);
    }

    /**
     * Criterio D: un preventivo vencido y uno proximo aparecen en su propia
     * tarjeta, con el color que distingue vencido de proximo.
     */
    public function testExpiredAndUpcomingPreventivesAppearInTheirOwnMetrics(): void
    {
        $html = $this->renderManagementReport(
            "Empresa: Demo\n"
            . self::DOCUMENT_EXPIRED . ': 3' . "\n"
            . self::DOCUMENT_UPCOMING . ': 1' . "\n"
            . self::PREVENTIVE_EXPIRED . ': 2' . "\n"
            . self::PREVENTIVE_UPCOMING . ': 4' . "\n",
        );

        self::assertSame('2', $this->cardValue($html, self::PREVENTIVE_EXPIRED));
        self::assertSame('4', $this->cardValue($html, self::PREVENTIVE_UPCOMING));
        self::assertSame('3', $this->cardValue($html, self::DOCUMENT_EXPIRED));
        self::assertSame('1', $this->cardValue($html, self::DOCUMENT_UPCOMING));

        // Vencido en rojo, proximo en ambar: el destinatario distingue la accion.
        self::assertStringContainsString('#dc2626', $this->cardOf($html, self::PREVENTIVE_EXPIRED));
        self::assertStringContainsString('#d97706', $this->cardOf($html, self::PREVENTIVE_UPCOMING));
    }

    /**
     * Regresion de tono: "Documentación vencida" esta en singular y el chequeo
     * buscaba solo "vencidos". La tarjeta con el valor mas alto del informe
     * salia en gris, igual que una metrica sin problemas.
     */
    public function testExpiredDocumentCardUsesTheAlertTone(): void
    {
        $html = $this->renderManagementReport(
            "Empresa: Demo\n"
            . self::DOCUMENT_EXPIRED . ': 3' . "\n"
            . self::DOCUMENT_UPCOMING . ': 0' . "\n"
            . self::PREVENTIVE_EXPIRED . ': 0' . "\n"
            . self::PREVENTIVE_UPCOMING . ': 0' . "\n",
        );

        $card = $this->cardOf($html, self::DOCUMENT_EXPIRED);
        self::assertStringContainsString('#dc2626', $card, 'La documentación vencida debe usar el tono de alerta.');
        self::assertStringNotContainsString('#0f172a', $card, 'La documentación vencida no puede salir en el tono neutro.');

        // Con valor 0 la tarjeta queda neutra: no hay nada urgente.
        $empty = $this->renderManagementReport("Empresa: Demo\n" . self::PREVENTIVE_EXPIRED . ': 0' . "\n");
        self::assertStringContainsString('#0f172a', $this->cardOf($empty, self::PREVENTIVE_EXPIRED));
    }

    /**
     * Este es el test que faltaba en el primer intento. Un resumen viejo queda
     * pendiente en la cola y, al grouped por bucket, el renderer recibe la fila
     * equivocada. El correo final debe mostrar SIEMPRE el informe vigente.
     */
    public function testPendingStaleReportDoesNotShadowTheCurrentReport(): void
    {
        $staleSummary = "Empresa: Demo\nVencimientos vencidos: 3\nVencimientos próximos (30 días): 0\n";
        $currentSummary = $this->dailyReportSummary();

        $queue = new ManagementReportQueue();
        $queue->companyRows = [
            $this->companyRow(1, 'informe.gerencial.diario', $staleSummary, 3),
            $this->companyRow(2, 'informe.gerencial.diario', $currentSummary, 0),
        ];
        $email = new ManagementReportRecordingEmail();

        // La cola implementa los dos puertos: el dispatcher recibe una sola
        // instancia y distingue por instanceof en cada etapa.
        $result = (new RunNotificationDispatch(
            $queue,
            $email,
            new ManagementReportEmptyPush(),
            new ManagementReportProcess(),
        ))->execute('management-report-test-1-daily-20261001');

        self::assertSame(1, $result['company_email_sent']);

        // La fila gerencial vieja queda OMITIDA, no enviada.
        self::assertSame([1], $queue->skippedIds);
        self::assertSame([2], $queue->deliveredIds);

        // Un solo envio para el bucket gerencial, con la fila vigente.
        self::assertCount(1, $email->batches);
        $rendered = $email->batches[0];
        self::assertCount(1, $rendered, 'El bucket gerencial debe consolidar en un unico envio.');

        $html = $this->renderManagementReport((string) $rendered[0]['resumen']);
        self::assertStringContainsString(self::DOCUMENT_EXPIRED, $html);
        self::assertStringContainsString(self::PREVENTIVE_EXPIRED, $html);
        self::assertStringNotContainsString('Vencimientos vencidos', $html);
    }

    /**
     * Criterio F: la prueba manual y el envio automatico usan el mismo
     * generador. queueTest() fuerza el envio pero entra por queueCompany(), igual
     * que execute(); ambas terminan en buildReport() con un unico cuerpo.
     */
    public function testManualTestAndScheduledSendShareOneGenerator(): void
    {
        $scheduler = file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php');
        self::assertIsString($scheduler);

        // La prueba manual entra por queueCompany() con $force = true.
        self::assertStringContainsString('public function queueTest(', $scheduler);
        self::assertStringContainsString(
            'return $this->queueCompany($companyId, strtoupper($type), true, $company);',
            $scheduler,
        );

        // El envio programado entra por el mismo metodo con $force = false.
        self::assertStringContainsString(
            '$result = $this->queueCompany((int) $company[\'id\'], $type, false, $company);',
            $scheduler,
            'El envio automatico debe entrar por el mismo queueCompany().',
        );

        // Unico punto de generacion del resumen: manual y cron no pueden divergir.
        self::assertSame(2, substr_count($scheduler, 'private function queueCompany('));
        self::assertSame(1, substr_count($scheduler, '$this->buildReport('));
        self::assertStringContainsString('$report = $this->buildReport($companyId, $type, $now, $companyName);', $scheduler);
    }

    /**
     * El generador no puede contar documentacion como mantenimiento preventivo:
     * los preventivos salen del evaluador de planes y la documentacion de la
     * tabla de vencimientos.
     */
    public function testPreventiveCountsComeFromPlanEvaluationNotFromExpirations(): void
    {
        $scheduler = file_get_contents(APPPATH . 'Application/Notifications/ScheduleManagementReports.php');
        self::assertIsString($scheduler);

        // Documentacion: tabla de vencimientos.
        self::assertStringContainsString("expirationCount(\$companyId, '<', \$today)", $scheduler);

        // Preventivos: read model de planes + regla de dominio, no una cuenta paralela.
        self::assertStringContainsString('CodeIgniterPreventivePlanReadModel', $scheduler);
        self::assertStringContainsString('new EvaluadorVencimiento()', $scheduler);
        self::assertStringContainsString('EstadoPlan::VENCIDO', $scheduler);
        self::assertStringContainsString('EstadoPlan::PROXIMO', $scheduler);
        self::assertStringContainsString('$evaluator->evaluar(', $scheduler);

        // Las lineas preventivas se alimentan del evaluador.
        self::assertMatchesRegularExpression(
            '/Preventivos vencidos:\s*\'\s*\.\s*\$preventiveDue\[\'overdue\'\]/',
            $scheduler,
        );
        self::assertMatchesRegularExpression(
            '/Preventivos próximos:\s*\'\s*\.\s*\$preventiveDue\[\'upcoming\'\]/',
            $scheduler,
        );
    }

    /** Resumen de un informe diario vigente con las cuatro metricas separadas. */
    private function dailyReportSummary(): string
    {
        return "Empresa: Demo\n"
            . 'Equipos activos: 12' . "\n"
            . self::DOCUMENT_EXPIRED . ': 3' . "\n"
            . self::DOCUMENT_UPCOMING . ': 0' . "\n"
            . self::PREVENTIVE_EXPIRED . ': 1' . "\n"
            . self::PREVENTIVE_UPCOMING . ': 2' . "\n"
            . 'Órdenes abiertas: 4' . "\n";
    }

    /** @return array<string,mixed> */
    private function companyRow(int $id, string $eventType, string $summary, int $attempts): array
    {
        return [
            'id' => $id,
            'empresa_id' => 1,
            'tipo_evento' => $eventType,
            'email' => 'gerencia@demo.example',
            'titulo' => 'Informe diario de mantenimiento · Demo · 01/10/2026',
            'resumen' => $summary,
            'url' => '/dashboard',
            'intentos' => $attempts,
        ];
    }

    /** Renderiza el HTML con el MISMO metodo privado que usa el correo real. */
    private function renderManagementReport(string $summary): string
    {
        $class = new \ReflectionClass(\App\Infrastructure\Notifications\CodeIgniterEmailNotificationGateway::class);
        $gateway = $class->newInstanceWithoutConstructor();

        $render = $class->getMethod('managementReportHtml');
        $render->setAccessible(true);

        return (string) $render->invoke(
            $gateway,
            [
                'tipo_evento' => 'informe.gerencial.diario',
                'titulo' => 'Informe diario de mantenimiento · Demo · 01/10/2026',
                'resumen' => $summary,
                'url' => '/dashboard',
            ],
            'Informe diario de mantenimiento · Demo · 01/10/2026',
        );
    }

    /** Extrae el valor numerico de la tarjeta con ese label. */
    private function cardValue(string $html, string $label): string
    {
        $card = $this->cardOf($html, $label);
        self::assertNotSame('', $card, 'No se encontro la tarjeta ' . $label);

        preg_match('/font-size:28px[^>]*>([^<]*)</', $card, $matches);

        return trim($matches[1] ?? '');
    }

    private function cardCount(string $html, string $label): int
    {
        return substr_count($html, htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** Devuelve el HTML de la tarjeta completa que corresponde al label. */
    private function cardOf(string $html, string $label): string
    {
        $needle = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $position = strpos($html, $needle);
        if ($position === false) {
            return '';
        }

        $start = strrpos(substr($html, 0, $position), '<table');
        $end = strpos($html, '</table>', $position);

        return $start === false || $end === false
            ? ''
            : substr($html, $start, $end + 8 - $start);
    }
}

final class ManagementReportQueue implements NotificationDeliveryQueue, CompanyNotificationDeliveryQueue
{
    /** @var list<array<string,mixed>> */
    public array $companyRows = [];
    /** @var list<int> */
    public array $deliveredIds = [];
    /** @var list<int> */
    public array $skippedIds = [];

    // NotificationDeliveryQueue (canal usuario): sin datos en este escenario.
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void {}
    public function due(string $channel, int $limit): array { return []; }
    public function delivered(int $deliveryId): void {}
    public function skipped(int $deliveryId, string $reason): void {}
    public function failed(int $deliveryId, string $error, bool $retryable): void {}

    // CompanyNotificationDeliveryQueue (informe gerencial).
    public function scheduleCompany(\App\Domain\Notifications\NotifiableEvent $event): void {}

    public function dueCompany(int $limit): array
    {
        return array_slice($this->companyRows, 0, $limit);
    }

    public function deliveredCompany(int $deliveryId): void
    {
        $this->deliveredIds[] = $deliveryId;
    }

    public function skippedCompany(int $deliveryId, string $reason): void
    {
        $this->skippedIds[] = $deliveryId;
    }

    public function failedCompany(int $deliveryId, string $error, bool $retryable): void {}
}

final class ManagementReportRecordingEmail implements EmailNotificationGateway
{
    /** @var list<list<array<string,mixed>>> */
    public array $batches = [];

    public function sendDigest(string $recipient, array $notifications): void
    {
        $this->batches[] = $notifications;
    }
}

final class ManagementReportEmptyPush implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class ManagementReportProcess implements NotificationProcessControl
{
    public function acquire(string $process, int $ttlSeconds): ?string { return 'token'; }
    public function start(string $process, string $executionKey): ?int { return 1; }
    public function finish(int $executionId, array $summary): void {}
    public function fail(int $executionId, string $error): void {}
    public function release(string $process, string $token): void {}
}
