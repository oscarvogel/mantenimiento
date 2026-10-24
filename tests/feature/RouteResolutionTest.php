<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Platform\GetPlatformModuleCatalog;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Http\ApplicationUrl;

/**
 * Cobertura de las rutas del sistema.
 *
 * Las rutas agrupadas bajo `mantenimiento` son el grueso de la aplicacion y
 * no tenian ninguna cobertura HTTP: los tests de feature solo pedian rutas de
 * primer nivel. Por eso la regresion del chatbot, que era una ruta mal
 * construida, pudo volver varias veces sin que ningun test la senalara.
 *
 * Estos seis tests cierran ese hueco:
 *
 * 1. Toda ruta agrupada declarada tiene que resolver.
 * 2. La URL que genera la app para una ruta tiene que volver a esa ruta.
 * 3. Toda URL literal que construye la app tiene que corresponder a una ruta
 *    real, y no a un archivo estatico.
 * 4. Toda entrada del catalogo de modulos tiene que existir como ruta.
 * 5. Todo enlace tiene que apuntar a una ruta GET.
 * 6. Todo formulario tiene que apuntar a una ruta del metodo que declara.
 *
 * Los tests 5 y 6 son los que detectan un rename de ruta: el enlace sigue
 * apuntando al nombre viejo, que ya no responde, y antes se breaking en
 * produccion sin que nadie lo viera.
 *
 * Los paths se comparan con `Tests\Support\Http\ApplicationUrl`, que emula el
 * recorte del directorio fisico que hace Apache, para que la suite no dependa
 * del `app.baseURL` de la maquina donde corre.
 */
final class RouteResolutionTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * Rutas que no se barren: las internas disparan tareas y las publicas
     * responden por contenido, no por contrato de ruta.
     */
    private const EXCLUIDAS = ['internal/', 'cron/', 'mantenimiento/publico/'];

    /**
     * URLs que la app publica como base de una familia y que el llamador
     * completa con un identificador antes de usarlas.
     *
     * El valor es el identificador de ejemplo con el que se verifica que la
     * familia existe: si se renombra la ruta de abajo, el test se da cuenta.
     *
     * @var array<string, string>
     */
    private const FAMILIAS_COMPLETADAS = [
        'notificaciones/leer' => '1',
    ];

    /**
     * @var list<string>
     */
    private const VERBOS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Fuentes de la app ya leidas: los tres escaneos comparten el mismo
     * recorrido y releer todo tres veces multiplicaba el tiempo del test.
     *
     * @var list<string>|null
     */
    private ?array $fuentes = null;

    /**
     * Patrones de ruta por verbo, cacheados: los tests los piden muchas
     * veces y cada carga vuelve a leer el archivo de rutas.
     *
     * @var array<string, list<string>>
     */
    private array $patronesPorVerbo = [];

    public function testTodaRutaAgrupadaDeclaradaResuelve(): void
    {
        $revisadas = 0;

        foreach ($this->patternsDeVerbo('GET') as $pattern) {
            if (! str_starts_with($pattern, 'mantenimiento/') || $this->estaExcluida($pattern)) {
                continue;
            }

            $path = $this->rutaConcreta($pattern);

            try {
                $this->get($path);
            } catch (PageNotFoundException $exception) {
                self::fail(sprintf(
                    'La ruta declarada "%s" no resuelve (se pidio "%s"): %s',
                    $pattern,
                    $path,
                    $exception->getMessage(),
                ));
            }

            $revisadas++;
        }

        self::assertGreaterThan(
            30,
            $revisadas,
            'Se esperaban al menos 30 rutas agrupadas y solo se revisaron ' . $revisadas . '.',
        );
    }

    public function testLaUrlGeneradaParaCadaRutaVuelveALaMismaRuta(): void
    {
        $revisadas = 0;

        foreach ($this->todosLosPatrones() as $pattern) {
            $path = $this->rutaConcreta($pattern);
            $url = base_url(ltrim($path, '/'));

            self::assertSame(
                $path,
                ApplicationUrl::routePath($url),
                sprintf(
                    'La URL generada para "%s" no vuelve a esa misma ruta. Con baseURL "%s" resuelve a "%s".',
                    $pattern,
                    base_url(''),
                    ApplicationUrl::routePath($url),
                ),
            );

            $revisadas++;
        }

        self::assertGreaterThan(100, $revisadas, 'Se esperaban mas de 100 rutas.');
    }

    public function testTodaUrlLiteralQueConstruyeLaAppCorrespondeAUnaRuta(): void
    {
        $patrones = $this->todosLosPatrones();
        $revisados = 0;

        foreach ($this->literalesDeLaApp() as $literal) {
            if ($this->esArchivoEstatico($literal)) {
                continue;
            }

            $ruta = explode('?', $literal, 2)[0];
            if ($ruta === '') {
                continue;
            }

            // Una URL que termina en barra es una familia: la app la completa
            // con los segmentos que faltan. Sin barra tiene que responder a
            // una ruta exacta, salvo que este declarada como familia
            // completada mas abajo.
            if (str_ends_with($ruta, '/')) {
                self::assertTrue(
                    $this->coincideConUnaFamilia($ruta, $patrones),
                    sprintf(
                        'La app construye la URL "%s" pero ninguna ruta declarada pertenece a esa familia.',
                        $literal,
                    ),
                );
                $revisados++;

                continue;
            }

            $completada = self::FAMILIAS_COMPLETADAS[$ruta] ?? null;
            if ($completada !== null) {
                self::assertTrue(
                    $this->coincideConUnaRuta($ruta . '/' . $completada, $patrones),
                    sprintf(
                        'La app construye la URL "%s" y la completa con un id, pero la familia no existe.',
                        $literal,
                    ),
                );
                $revisados++;

                continue;
            }

            self::assertTrue(
                $this->coincideConUnaRuta($ruta, $patrones),
                sprintf(
                    'La app construye la URL "%s" pero ninguna ruta declarada la responde.%s',
                    $literal,
                    $this->sugerencias($ruta, $patrones),
                ),
            );

            $revisados++;
        }

        self::assertGreaterThan(
            80,
            $revisados,
            'Se esperaban al menos 80 URLs de la app y solo se revisaron ' . $revisados . '.',
        );
    }

    public function testTodaEntradaDelCatalogoDeModulosExiste(): void
    {
        $patrones = $this->todosLosPatrones();
        $entradas = $this->rutasDeEntradaDelCatalogo();

        self::assertGreaterThan(
            5,
            count($entradas),
            'Se esperaban varias entradas del catalogo y solo se revisaron ' . count($entradas) . '.',
        );

        foreach ($entradas as $entrada) {
            self::assertTrue(
                $this->coincideConUnaRuta($entrada, $patrones),
                sprintf(
                    'La entrada del catalogo de modulos "%s" no corresponde a ninguna ruta declarada.%s',
                    $entrada,
                    $this->sugerencias($entrada, $patrones),
                ),
            );

            try {
                $this->get('/' . ltrim($entrada, '/'));
            } catch (PageNotFoundException $exception) {
                self::fail(sprintf(
                    'La entrada del catalogo de modulos "%s" no resuelve: %s',
                    $entrada,
                    $exception->getMessage(),
                ));
            }
        }
    }

    public function testTodoEnlaceDeLaAppApuntaAUnaRutaGet(): void
    {
        $patrones = $this->patternsDeVerbo('GET');
        $revisados = 0;

        foreach ($this->enlacesDeLaApp() as $enlace) {
            if ($this->esArchivoEstatico($enlace)) {
                continue;
            }

            self::assertTrue(
                $this->resuelve($enlace, $patrones),
                sprintf(
                    'La app enlaza a "%s" pero ninguna ruta GET declarada lo responde.%s',
                    $enlace,
                    $this->sugerencias($enlace, $patrones),
                ),
            );

            $revisados++;
        }

        self::assertGreaterThan(
            8,
            $revisados,
            'Se esperaban al menos 8 enlaces y solo se revisaron ' . $revisados . '.',
        );
    }

    public function testTodoFormularioDeLaAppApuntaAUnaRutaDeSuMetodo(): void
    {
        $revisados = 0;

        foreach ($this->formulariosDeLaApp() as $formulario) {
            $metodo = $formulario['metodo'];
            $accion = $formulario['accion'];

            if ($this->esArchivoEstatico($accion)) {
                continue;
            }

            $patrones = $this->patternsDeVerbo($metodo);
            self::assertTrue(
                $this->resuelve($accion, $patrones),
                sprintf(
                    'La app envia un formulario "%s" a "%s" pero ninguna ruta %s declarada lo responde.%s',
                    $metodo,
                    $accion,
                    $metodo,
                    $this->sugerencias($accion, $patrones),
                ),
            );

            $revisados++;
        }

        self::assertGreaterThan(
            20,
            $revisados,
            'Se esperaban al menos 20 formularios y solo se revisaron ' . $revisados . '.',
        );
    }

    public function testTodoPathQueConstruyeElFrontendPorSuCuentaExiste(): void
    {
        $patrones = $this->todosLosPatrones();
        $revisados = 0;

        foreach ($this->pathsHardcodeadosDelFrontend() as $path) {
            self::assertTrue(
                $this->resuelve($path, $patrones),
                sprintf(
                    'El frontend construye el path "%s" pero ninguna ruta declarada lo responde.%s',
                    $path,
                    $this->sugerencias($path, $patrones),
                ),
            );

            $revisados++;
        }

        self::assertGreaterThan(
            0,
            $revisados,
            'No se encontro ningun path de navegacion en el frontend para verificar.',
        );
    }

    /**
     * @return list<string>
     */
    private function patternsDeVerbo(string $verbo): array
    {
        if (isset($this->patronesPorVerbo[$verbo])) {
            return $this->patronesPorVerbo[$verbo];
        }

        $collection = service('routes');
        $collection->loadRoutes();

        $patrones = array_keys($collection->getRoutes($verbo));
        $this->patronesPorVerbo[$verbo] = $patrones;

        return $patrones;
    }

    /**
     * @return list<string>
     */
    private function todosLosPatrones(): array
    {
        $patrones = [];

        foreach (self::VERBOS as $verbo) {
            foreach ($this->patternsDeVerbo($verbo) as $patron) {
                $patrones[$patron] = true;
            }
        }

        return array_keys($patrones);
    }

    private function estaExcluida(string $pattern): bool
    {
        foreach (self::EXCLUIDAS as $prefijo) {
            if (str_starts_with($pattern, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convierte el patron de la ruta en una ruta concreta: los placeholders se
     * completan con un valor cualquiera.
     */
    private function rutaConcreta(string $pattern): string
    {
        $ruta = preg_replace('/\(\[\^\/\]\+\)/', 'ejemplo', $pattern);
        $ruta = preg_replace('/\(\[0-9\]\+\)/', '1', $ruta);

        return '/' . trim((string) $ruta, '/');
    }

    /**
     * La ruta tiene que responder a un patron declarado.
     *
     * @param list<string> $patrones
     */
    private function coincideConUnaRuta(string $ruta, array $patrones): bool
    {
        $objetivo = rtrim($ruta, '/');

        foreach ($patrones as $patron) {
            if (preg_match('#^' . rtrim($patron, '/') . '$#', $objetivo) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una familia existe si hay alguna ruta declarada que empiece por el
     * prefijo seguido de una barra: el resto de los segmentos los completa la
     * app.
     *
     * @param list<string> $patrones
     */
    private function coincideConUnaFamilia(string $familia, array $patrones): bool
    {
        $prefijo = rtrim($familia, '/');

        foreach ($patrones as $patron) {
            if (str_starts_with($patron, $prefijo . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una URL resuelve si matchea una ruta exacta o si es una familia: una
     * URL terminada en barra la completa la app con los segmentos que faltan.
     *
     * @param list<string> $patrones
     */
    private function resuelve(string $ruta, array $patrones): bool
    {
        if ($this->coincideConUnaRuta($ruta, $patrones)) {
            return true;
        }

        if (str_ends_with($ruta, '/')) {
            return $this->coincideConUnaFamilia($ruta, $patrones);
        }

        return false;
    }

    /**
     * Rutas de entrada que el catalogo de modulos puede publicar.
     *
     * Se leen todas las entradas de mantenimiento, no solo la primera que le
     * corresponde a un actor, porque el catalogo elige segun permisos: un
     * rename de una entrada que hoy nadie tiene habilitada seria invisible.
     *
     * @return list<string>
     */
    private function rutasDeEntradaDelCatalogo(): array
    {
        $entradas = [];

        $mapa = (new ReflectionClass(GetPlatformModuleCatalog::class))->getConstant('MAINTENANCE_ENTRY_PATHS');
        foreach ((array) $mapa as $landingPath) {
            $entradas[(string) $landingPath] = true;
        }

        $catalogo = new GetPlatformModuleCatalog();
        foreach ($this->actoresDelCatalogo() as $actor) {
            foreach ($catalogo->execute($actor) as $modulo) {
                if ($modulo['landingPath'] !== null) {
                    $entradas[$modulo['landingPath']] = true;
                }
            }
        }

        $lista = array_keys($entradas);
        sort($lista);

        return $lista;
    }

    /**
     * Actores minimos para que el catalogo publique cada landingPath posible.
     *
     * @return list<ActorContext>
     */
    private function actoresDelCatalogo(): array
    {
        $actores = [];

        foreach ([['viajes.ver'], ['viajes.maestros.ver'], ['gerencial.costo_km.ver']] as $permisos) {
            $actores[] = new ActorContext(1, 1, false, true, [], $permisos, []);
        }

        return $actores;
    }

    /**
     * @return list<string>
     */
    private function literalesDeLaApp(): array
    {
        $literales = [];

        foreach ($this->fuentesDeLaApp() as $fuente) {
            if (preg_match_all("/base_url\(\s*'([^']+)'/", $fuente, $coincidencias) === 0) {
                continue;
            }
            foreach ($coincidencias[1] as $literal) {
                $literales[$literal] = true;
            }
        }

        $lista = array_keys($literales);
        sort($lista);

        return $lista;
    }

    /**
     * Enlaces que la app construye con `base_url()`.
     *
     * Se toman los `<a href="...">` y tambien los `href` que se le pasan a un
     * partial, que es como la app arma la cabecera de cada pagina.
     *
     * @return list<string>
     */
    private function enlacesDeLaApp(): array
    {
        $enlaces = [];

        $patrones = [
            '/<a\b[^>]*\bhref\s*=\s*["\'][^"\']*base_url\(\s*\'([^\']+)\'/i',
            '/[\'"]href[\'"]\s*=>\s*base_url\(\s*\'([^\']+)\'/i',
        ];

        foreach ($this->fuentesDeLaApp() as $fuente) {
            foreach ($patrones as $patron) {
                if (preg_match_all($patron, $fuente, $coincidencias) === 0) {
                    continue;
                }
                foreach ($coincidencias[1] as $literal) {
                    $enlaces[$literal] = true;
                }
            }
        }

        $lista = array_keys($enlaces);
        sort($lista);

        return $lista;
    }

    /**
     * Formularios que la app construye con `base_url()`, con el metodo que
     * declaran.
     *
     * @return list<array{metodo: string, accion: string}>
     */
    private function formulariosDeLaApp(): array
    {
        $formularios = [];

        foreach ($this->fuentesDeLaApp() as $fuente) {
            if (preg_match_all('/<form\b[^>]*>/i', $fuente, $coincidencias) === 0) {
                continue;
            }

            foreach ($coincidencias[0] as $tag) {
                $patronAccion = '/\baction\s*=\s*["\'][^"\']*base_url\(\s*\'([^\']+)\'/i';
                if (preg_match($patronAccion, $tag, $coincidenciaAccion) !== 1) {
                    continue;
                }

                $metodo = 'GET';
                if (preg_match('/\bmethod\s*=\s*["\']post["\']/i', $tag) === 1) {
                    $metodo = 'POST';
                }

                $formularios[] = ['metodo' => $metodo, 'accion' => $coincidenciaAccion[1]];
            }
        }

        return $formularios;
    }

    /**
     * Paths que el frontend construye por su cuenta, sin que el backend se los
     * pase en un payload.
     *
     * El resto de las URLs del frontend llegan desde los controladores, asi
     * que ya las cubre el test de URLs literales.
     *
     * @return list<string>
     */
    private function pathsHardcodeadosDelFrontend(): array
    {
        $paths = [];
        $raiz = ROOTPATH . 'frontend/src';

        if (! is_dir($raiz)) {
            return [];
        }

        $archivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($archivos as $archivo) {
            if (! $archivo->isFile()) {
                continue;
            }
            if (! in_array(strtolower($archivo->getExtension()), ['js', 'vue'], true)) {
                continue;
            }
            // Los tests del frontend usan paths de ejemplo como datos: no son
            // navegacion real.
            if (str_contains($archivo->getFilename(), '.test.') || str_ends_with($archivo->getFilename(), '.spec.')) {
                continue;
            }

            $fuente = (string) file_get_contents($archivo->getPathname());
            if (preg_match_all('/(?<![\w\/])(mantenimiento\/[A-Za-z0-9_\-\/]*)/', $fuente, $coincidencias) === 0) {
                continue;
            }
            foreach ($coincidencias[1] as $literal) {
                $paths[$literal] = true;
            }
        }

        $lista = array_keys($paths);
        sort($lista);

        return $lista;
    }

    /**
     * @return list<string>
     */
    private function fuentesDeLaApp(): array
    {
        if ($this->fuentes !== null) {
            return $this->fuentes;
        }

        $fuentes = [];

        $archivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($archivos as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }
            $fuentes[] = (string) file_get_contents($archivo->getPathname());
        }

        $this->fuentes = $fuentes;

        return $fuentes;
    }

    /**
     * Una URL que apunta a un archivo del repositorio no es una ruta: la
     * sirve el front controller como archivo estatico.
     */
    private function esArchivoEstatico(string $literal): bool
    {
        $ruta = explode('?', $literal, 2)[0];

        if (str_starts_with($ruta, 'assets/')) {
            return true;
        }

        return is_file(ROOTPATH . $ruta);
    }

    /**
     * Cuando una URL no resuelve, mostrar los caminos mas parecidos ayuda
     * mucho mas que el nombre de la ruta que se busca.
     *
     * @param list<string> $patrones
     */
    private function sugerencias(string $ruta, array $patrones): string
    {
        $segmentos = explode('/', $ruta);
        $parecidas = [];

        foreach ($patrones as $patron) {
            $comunes = $this->segmentosComunes($segmentos, explode('/', $patron));
            if ($comunes === 0) {
                continue;
            }
            $parecidas[$patron] = $comunes;
        }

        if ($parecidas === []) {
            return '';
        }

        arsort($parecidas);
        $mejores = array_slice(array_keys($parecidas), 0, 5);

        return ' Rutas parecidas: ' . implode(', ', $mejores) . '.';
    }

    /**
     * Cuantos segmentos iniciales comparten dos paths.
     *
     * @param list<string> $uno
     * @param list<string> $otro
     */
    private function segmentosComunes(array $uno, array $otro): int
    {
        $comunes = 0;
        $total = min(count($uno), count($otro));

        for ($i = 0; $i < $total; $i++) {
            if ($uno[$i] !== $otro[$i]) {
                break;
            }
            $comunes++;
        }

        return $comunes;
    }
}
