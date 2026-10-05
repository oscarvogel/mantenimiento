<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Notifications;

use App\Application\Notifications\ScheduleManagementReports;
use App\Infrastructure\Notifications\CodeIgniterEmailNotificationGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * #318 El correo gerencial no puede dejar el trabajo de filtrado al destinatario.
 *
 * El problema real no era mostrar cuatro indicadores: eso ya estaba. Era que el
 * unico boton apuntaba a /dashboard, asi que el responsable tenia que entrar,
 * navegar y filtrar a mano cada vez que recibia el informe.
 *
 * Por que estos tests no pueden ser un grep del fuente: que exista una cadena
 * "!LINK|" en el codigo no dice nada sobre si el enlace llego al correo ni
 * sobre a donde apunta. Lo que importa es el href real que recibe el
 * destinatario. Por eso el circuito bajo prueba es el completo:
 *
 *   ScheduleManagementReports::actionLines  (Application: decide etiqueta, URL
 *                                           y si el CTA existe o no)
 *     -> lineas !LINK| / !CTA| dentro del resumen congelado
 *     -> CodeIgniterEmailNotificationGateway::managementReportHtml
 *          -> href absoluto sobre la base configurada
 *
 * El resumen se arma con el generador REAL (actionLines por reflexion, sin base
 * de datos) y se renderiza con el renderizador REAL, asi que si alguien borra
 * un enlace, cambia un destino o muestra el boton con el contador en 0, falla.
 *
 * Sobre la base: el destino esperado se arma con la base que esta configurada
 * en el momento (app.baseURL), no con un dominio literal. Asi el test verifica
 * lo que importa - que el enlace se construye desde la configuracion y no
 * desde una constante pegada en el codigo - y sigue siendo valido si el
 * despliegue cambia de host o de subdirectorio.
 */
final class ManagementReportActionLinksTest extends TestCase
{
    private const DOCUMENT_EXPIRED = 'Documentación vencida';
    private const DOCUMENT_UPCOMING = 'Documentación próxima (30 días)';
    private const PREVENTIVE_EXPIRED = 'Preventivos vencidos';
    private const PREVENTIVE_UPCOMING = 'Preventivos próximos';

    /**
     * Criterio 1 y 3: cada indicador viaja a su propia lista ya filtrada, y los
     * preventivos van al filtro preventivo y NO al documental. Este test falla
     * si alguien saca un enlace, lo deja apuntando a /dashboard o invierte los
     * dos destinos.
     */
    public function testEachIndicatorLinksToItsOwnFilteredList(): void
    {
        $html = $this->render($this->counts(documentOverdue: 3, documentUpcoming: 1, preventiveOverdue: 2, preventiveUpcoming: 4));

        self::assertSame(
            [$this->documentsOverdue()],
            $this->cardLinks($html, self::DOCUMENT_EXPIRED),
            'La documentación vencida debe llevar a la lista de vencimientos ya filtrada.',
        );
        self::assertSame(
            [$this->documentsUpcoming()],
            $this->cardLinks($html, self::DOCUMENT_UPCOMING),
            'La documentación próxima debe llevar a la ventana de 30 días, no a la de vencidos.',
        );
        self::assertSame(
            [$this->plansOverdue()],
            $this->cardLinks($html, self::PREVENTIVE_EXPIRED),
            'Los preventivos vencidos deben llevar al filtro preventivo, no al documental.',
        );
        self::assertSame(
            [$this->plansUpcoming()],
            $this->cardLinks($html, self::PREVENTIVE_UPCOMING),
            'Los preventivos próximos deben llevar al filtro preventivo, no al documental.',
        );

        // El caso que el #313 hacia facil de romper: las dos mitades comparten
        // pantalla y un destino equivocado se ve "correcto".
        self::assertStringNotContainsString('mantenimiento/vencimientos', $this->cardLinks($html, self::PREVENTIVE_EXPIRED)[0] ?? '');
        self::assertStringNotContainsString('mantenimiento/planes', $this->cardLinks($html, self::DOCUMENT_EXPIRED)[0] ?? '');

        // Ningun indicador degrada al boton global. Se comprueba sobre los enlaces
        // de cada tarjeta y no sobre el HTML entero: el boton global "Abrir sistema
        // de mantenimiento" se conserva a proposito y comparar contra el documento
        // completo hacia fallar este test por un motivo que no es suyo.
        $dashboard = $this->base() . $this->basePath() . 'dashboard';
        foreach ([self::DOCUMENT_EXPIRED, self::DOCUMENT_UPCOMING, self::PREVENTIVE_EXPIRED, self::PREVENTIVE_UPCOMING] as $card) {
            self::assertNotContains(
                $dashboard,
                $this->cardLinks($html, $card),
                'El indicador ' . $card . ' no debe caer en el boton global.',
            );
        }
    }

    /**
     * Criterio 2: con algo vencido hay boton "Ver vencidos" y apunta a la lista
     * filtrada. Es el atajo que evita entrar a navegar y filtrar a mano.
     */
    public function testOverdueCtaIsRenderedWhenSomethingIsOverdue(): void
    {
        $html = $this->render($this->counts(documentOverdue: 2, preventiveOverdue: 5));

        self::assertSame(
            $this->plansOverdue(),
            $this->anchorHref($html, 'Ver vencidos'),
            'Con 5 preventivos vencidos el CTA debe llevar al filtro preventivo de vencidos.',
        );
    }

    /**
     * Criterio explicito del issue: contador en 0 -> NO se muestra el boton. No
     * alcanza con que el enlace no lleve a ninguna parte: el boton no puede
     * llegar a imprimirse.
     */
    public function testOverdueCtaIsNotRenderedWhenNothingIsOverdue(): void
    {
        $html = $this->render($this->counts());

        self::assertNull(
            $this->anchorHref($html, 'Ver vencidos'),
            'Con los dos contadores de vencidos en 0 el correo no debe ofrecer el botón.',
        );
        self::assertNull($this->anchorHref($html, 'Ver próximos'));
        self::assertStringNotContainsString('Ir directo a lo que hay que resolver', $html);
    }

    /** El equivalente de "Ver próximos" tampoco aparece sin nada próximo. */
    public function testUpcomingCtaFollowsItsOwnCounter(): void
    {
        $soloDocumentacion = $this->render($this->counts(documentUpcoming: 3));

        self::assertSame($this->documentsUpcoming(), $this->anchorHref($soloDocumentacion, 'Ver próximos'));
        self::assertNull(
            $this->anchorHref($soloDocumentacion, 'Ver vencidos'),
            'Sin nada vencido en ninguno de los dos dominios no hay CTA de vencidos.',
        );

        $sinProximos = $this->render($this->counts(documentOverdue: 1, preventiveOverdue: 1));

        self::assertNull(
            $this->anchorHref($sinProximos, 'Ver próximos'),
            'Sin nada próximo el CTA de próximos no debe aparecer aunque haya vencidos.',
        );
        self::assertNotNull($this->anchorHref($sinProximos, 'Ver vencidos'));
    }

    /**
     * Cuando los dos dominios tienen vencidos hay un solo boton: va al que
     * tiene mas. En empate gana la documentacion, regla definida en Application
     * y no improvisada por el adaptador. Los enlaces por indicador siguen
     * presentes, asi que el destino perdedor no queda inaccesible.
     */
    public function testCtaGoesToTheHeavierOverdueDomainAndTieGoesToDocuments(): void
    {
        $documentos = $this->render($this->counts(documentOverdue: 9, preventiveOverdue: 1));
        self::assertSame($this->documentsOverdue(), $this->anchorHref($documentos, 'Ver vencidos'));

        $preventivos = $this->render($this->counts(documentOverdue: 1, preventiveOverdue: 9));
        self::assertSame($this->plansOverdue(), $this->anchorHref($preventivos, 'Ver vencidos'));

        $empate = $this->render($this->counts(documentOverdue: 4, preventiveOverdue: 4));
        self::assertSame($this->documentsOverdue(), $this->anchorHref($empate, 'Ver vencidos'));
        self::assertSame([$this->plansOverdue()], $this->cardLinks($empate, self::PREVENTIVE_EXPIRED));
    }

    /**
     * Los enlaces no pueden transportar datos de la empresa: el destino aplica
     * su propio alcance por empresa_id y sucursales desde la sesion
     * (Expirations::index y PreventivePlans::index resuelven el actor y filtran
     * por empresa). Se comprueba de dos formas: los href solo pueden usar la
     * base configurada y el filtro `estado`, y ademas el destino es IDENTICO
     * para dos empresas distintas, asi que no puede estar filtrando nada de
     * ninguna.
     */
    public function testLinksCarryNoTenantDataAndDependOnlyOnConfiguration(): void
    {
        $otraEmpresa = $this->render($this->counts(documentOverdue: 40, documentUpcoming: 7, preventiveOverdue: 12, preventiveUpcoming: 3));
        $estaEmpresa = $this->render($this->counts(documentOverdue: 1, documentUpcoming: 0, preventiveOverdue: 0, preventiveUpcoming: 0));

        // Solo los enlaces de accion: el pie del correo lleva links de la
        // firma comercial, que no son destinos del informe.
        $hrefs = $actionLinks = [];
        foreach ([self::DOCUMENT_EXPIRED, self::DOCUMENT_UPCOMING, self::PREVENTIVE_EXPIRED, self::PREVENTIVE_UPCOMING] as $label) {
            $actionLinks = array_merge($actionLinks, $this->cardLinks($otraEmpresa, $label));
        }
        foreach (['Ver vencidos', 'Ver próximos'] as $cta) {
            $href = $this->anchorHref($otraEmpresa, $cta);
            if ($href !== null) {
                $actionLinks[] = $href;
            }
        }
        $hrefs = array_values(array_unique($actionLinks));

        self::assertCount(6, $actionLinks, 'El informe debe llevar cuatro enlaces de indicador y dos CTA.');
        // Los dos CTA apuntan a destinos que ya estan en las tarjetas, asi que
        // en limpio son cuatro rutas distintas: ningun destino se repite por
        // accidente y ninguno falta.
        self::assertCount(4, $hrefs);

        foreach ($hrefs as $href) {
            self::assertStringStartsWith($this->base() . $this->basePath(), $href, 'Todo enlace debe armarse desde la base configurada, sin dominio hardcodeado.');
            self::assertDoesNotMatchRegularExpression(
                '/(empresa|sucursal|usuario|rol|token|password|clave|@)/i',
                $href,
                'Un enlace de correo no puede llevar datos de la empresa ni de la sucursal.',
            );
        }

        // Unico parametro admisible: el filtro de estado de la lista de destino.
        $states = [];
        foreach ($hrefs as $href) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            self::assertSame(['estado'], array_keys($query), 'El enlace solo puede filtrar por estado: ' . $href);
            $states[] = $query['estado'];
        }
        self::assertSame(
            ['vencidos', '30', 'VENCIDO', 'PROXIMO'],
            array_values(array_unique($states)),
            'Los cuatro destinos deben seguir siendo cuatro filtros distintos.',
        );

        // Los enlaces por indicador no dependen de la empresa ni de los numeros:
        // son la MISMA ruta para cualquiera. Un href que dependiera del tenant o
        // del volumen no podria ser igual en los dos informes.
        self::assertSame(
            $this->indicatorDestinations($estaEmpresa),
            $this->indicatorDestinations($otraEmpresa),
            'El destino de cada indicador no puede variar por empresa ni por cantidad.',
        );
    }

    /**
     * Un destino manipulado no debe terminar en el href: el adaptador descarta
     * lo que no sea una ruta segura en vez de imprimirlo.
     */
    public function testUnsafeDestinationIsDiscardedInsteadOfRendered(): void
    {
        $html = $this->renderSummary(
            "Empresa: Demo\n"
            . self::DOCUMENT_EXPIRED . ": 1\n"
            . '!LINK|' . self::DOCUMENT_EXPIRED . "|javascript:alert(1)\n"
            . '!CTA|Ver vencidos|javascript:alert(2)\n',
        );

        self::assertSame([], $this->cardLinks($html, self::DOCUMENT_EXPIRED));
        self::assertNull($this->anchorHref($html, 'Ver vencidos'));
        self::assertStringNotContainsString('javascript:', $html);
    }

    /**
     * El correo es multipart: la parte de texto plano tambien tiene que llevar
     * los destinos y no las directivas internas del resumen.
     */
    public function testPlainTextPartListsTheDestinationsWithoutInternalTokens(): void
    {
        $summary = $this->reportSummary($this->counts(documentOverdue: 3, preventiveOverdue: 1, preventiveUpcoming: 2));
        $text = $this->renderText($summary);

        self::assertStringContainsString($this->documentsOverdue(), $text);
        self::assertStringContainsString($this->plansOverdue(), $text);
        self::assertStringContainsString($this->plansUpcoming(), $text);
        // 3 documentos vencidos contra 1 preventivo: el CTA va al que pesa mas.
        self::assertStringContainsString('Ver vencidos: ' . $this->documentsOverdue(), $text);
        self::assertStringNotContainsString('!LINK|', $text);
        self::assertStringNotContainsString('!CTA|', $text);
    }

    /**
     * Regla de dependencia: el destino es un dato de negocio y vive en
     * Application. Si el adaptador empieza a hardcodear la pantalla o el
     * filtro, este test falla aunque el correo "anda".
     */
    public function testGatewayDoesNotDecideWhereAnIndicatorLeads(): void
    {
        $gateway = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterEmailNotificationGateway.php');
        self::assertIsString($gateway);

        self::assertStringNotContainsString('mantenimiento/vencimientos', $gateway);
        self::assertStringNotContainsString('mantenimiento/planes', $gateway);
        self::assertStringNotContainsString('estado=', $gateway);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{documentacion_vencida:int,documentacion_proxima:int,preventivos_vencidos:int,preventivos_proximos:int}
     */
    private function counts(
        int $documentOverdue = 0,
        int $documentUpcoming = 0,
        int $preventiveOverdue = 0,
        int $preventiveUpcoming = 0,
    ): array {
        return [
            'documentacion_vencida' => $documentOverdue,
            'documentacion_proxima' => $documentUpcoming,
            'preventivos_vencidos' => $preventiveOverdue,
            'preventivos_proximos' => $preventiveUpcoming,
        ];
    }

    /**
     * Resumen con las lineas reales que emite Application. Las metricas van
     * escritas a mano como las escribe el generador; los enlaces y el CTA
     * salen del generador de verdad, asi que el contrato probado es el que
     * viaja por la cola. Si una etiqueta de indicador se desincroniza de la
     * del enlace, la tarjeta se queda sin href y falla.
     *
     * @param array<string,int> $counts
     */
    private function reportSummary(array $counts): string
    {
        $lines = [
            'Empresa: Demo',
            'Equipos activos: 12',
            self::DOCUMENT_EXPIRED . ': ' . $counts['documentacion_vencida'],
            self::DOCUMENT_UPCOMING . ': ' . $counts['documentacion_proxima'],
            self::PREVENTIVE_EXPIRED . ': ' . $counts['preventivos_vencidos'],
            self::PREVENTIVE_UPCOMING . ': ' . $counts['preventivos_proximos'],
            'Órdenes abiertas: 4',
        ];

        return implode("\n", array_merge($lines, $this->actionLines($counts)));
    }

    /** @param array<string,int> $counts */
    private function render(array $counts): string
    {
        return $this->renderSummary($this->reportSummary($counts));
    }

    /** Generador real de enlaces, invocado sin base de datos. */
    private function actionLines(array $counts): array
    {
        $class = new ReflectionClass(ScheduleManagementReports::class);
        $method = $class->getMethod('actionLines');
        $method->setAccessible(true);

        return (array) $method->invoke($class->newInstanceWithoutConstructor(), $counts);
    }

    private function renderSummary(string $summary): string
    {
        return $this->invokeGateway('managementReportHtml', $summary);
    }

    private function renderText(string $summary): string
    {
        return $this->invokeGateway('managementReportText', $summary);
    }

    /** Renderiza con el MISMO metodo privado que usa el correo real. */
    private function invokeGateway(string $method, string $summary): string
    {
        $class = new ReflectionClass(CodeIgniterEmailNotificationGateway::class);
        $render = $class->getMethod($method);
        $render->setAccessible(true);

        return (string) $render->invoke(
            $class->newInstanceWithoutConstructor(),
            [
                'tipo_evento' => 'informe.gerencial.diario',
                'titulo' => 'Informe diario de mantenimiento · Demo · 02/10/2026',
                'resumen' => $summary,
                'url' => '/dashboard',
            ],
            'Informe diario de mantenimiento · Demo · 02/10/2026',
        );
    }

    /** Base efectiva de la configuracion activa, sin dominio literal. */
    private function base(): string
    {
        $base = parse_url(base_url());
        self::assertIsArray($base);
        self::assertArrayHasKey('scheme', $base);
        self::assertArrayHasKey('host', $base);

        return $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . (int) $base['port'] : '');
    }

    /** Subdirectorio configurado en app.baseURL, si lo hay. */
    private function basePath(): string
    {
        return rtrim((string) parse_url(base_url(), PHP_URL_PATH), '/') . '/';
    }

    private function documentsOverdue(): string
    {
        return $this->base() . $this->basePath() . 'mantenimiento/vencimientos?estado=vencidos';
    }

    private function documentsUpcoming(): string
    {
        return $this->base() . $this->basePath() . 'mantenimiento/vencimientos?estado=30';
    }

    private function plansOverdue(): string
    {
        return $this->base() . $this->basePath() . 'mantenimiento/planes?estado=VENCIDO';
    }

    private function plansUpcoming(): string
    {
        return $this->base() . $this->basePath() . 'mantenimiento/planes?estado=PROXIMO';
    }

    /**
     * Destinos de los cuatro indicadores, en el orden en que aparecen las
     * tarjetas del informe.
     *
     * @return list<string>
     */
    private function indicatorDestinations(string $html): array
    {
        $destinations = [];
        foreach ([self::DOCUMENT_EXPIRED, self::DOCUMENT_UPCOMING, self::PREVENTIVE_EXPIRED, self::PREVENTIVE_UPCOMING] as $label) {
            $destinations[] = $this->cardLinks($html, $label)[0] ?? '(sin enlace)';
        }

        return $destinations;
    }

    /**
     * Enlaces que cuelgan de la tarjeta de ese indicador. Si la etiqueta del
     * indicador y la del enlace se desincronizan, aca no hay nada y falla.
     *
     * @return list<string>
     */
    private function cardLinks(string $html, string $label): array
    {
        $needle = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $position = strpos($html, $needle);
        if ($position === false) {
            self::fail('No se encontro la tarjeta ' . $label);
        }

        $start = strrpos(substr($html, 0, $position), '<td width="50%"');
        $next = strpos($html, '<td width="50%"', $position);
        $card = $start === false
            ? ''
            : substr($html, $start, ($next === false ? strlen($html) : $next) - $start);

        preg_match_all('/href="([^"]*)"/', $card, $matches);

        return array_values($matches[1]);
    }

    /** href del boton cuyo texto visible es exactamente ese. */
    private function anchorHref(string $html, string $text): ?string
    {
        preg_match_all('/<a href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (trim(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) === $text) {
                return $match[1];
            }
        }

        return null;
    }
}
