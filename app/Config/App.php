<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Base Site URL
     * --------------------------------------------------------------------------
     *
     * Si la variable de entorno app.baseURL esta definida, se usa esa.
     * Si no, se autodetecta desde $_SERVER para soportar dos deploys con la
     * misma instalacion:
     *
     *   - https://vogelconsultoria.com.ar/mantenimiento/
     *   - https://mantenimiento.vogelconsultoria.com.ar/
     *
     * Para forzar una URL fija, definir app.baseURL en .env.
     */
    public string $baseURL = '';

    /**
     * Allowed Hostnames in the Site URL other than the hostname in the baseURL.
     * Si el sistema recibe un Host que no esta en baseURL ni en esta lista,
     * CodeIgniter responde 400. Dejar vacio para aceptar cualquier host.
     *
     * En produccion conviene completar con los dominios reales.
     *
     * @var list<string>
     */
    public array $allowedHostnames = [];

    /**
     * --------------------------------------------------------------------------
     * Index File
     * --------------------------------------------------------------------------
     *
     * Tipicamente index.php. Si el servidor lo oculta de las URLs, dejar vacio.
     */
    public string $indexPage = '';

    /**
     * --------------------------------------------------------------------------
     * URI PROTOCOL
     * --------------------------------------------------------------------------
     */
    public string $uriProtocol = 'REQUEST_URI';

    public string $permittedURIChars = 'a-z 0-9~%.:_\-';

    /**
     * --------------------------------------------------------------------------
     * Idioma y zona horaria por defecto
     * --------------------------------------------------------------------------
     */
    public string $defaultLocale = 'es';

    public bool $negotiateLocale = false;

    /**
     * @var list<string>
     */
    public array $supportedLocales = ['es'];

    public string $appTimezone = 'America/Argentina/Buenos_Aires';

    public string $charset = 'UTF-8';

    /**
     * --------------------------------------------------------------------------
     * HTTPS forzado
     * --------------------------------------------------------------------------
     *
     * Si esta en true, toda peticion HTTP se redirige a HTTPS.
     * Por defecto false; se activa en produccion via app.forceGlobalSecureRequests.
     */
    public bool $forceGlobalSecureRequests = false;

    /**
     * --------------------------------------------------------------------------
     * Reverse Proxy IPs
     * --------------------------------------------------------------------------
     *
     * Si Ferozo pone un proxy delante (comun en hosting compartido), whiteliste
     * la IP del proxy aca para que $_SERVER['REMOTE_ADDR'] sea el cliente real.
     *
     * @var array<string, string>
     */
    public array $proxyIPs = [];

    public bool $CSPEnabled = false;

    /**
     * --------------------------------------------------------------------------
     * Autodeteccion de baseURL
     * --------------------------------------------------------------------------
     */
    public function __construct()
    {
        parent::__construct();

        if ($this->baseURL === '') {
            $this->baseURL = $this->detectBaseURL();
        }
    }

    /**
     * Detecta la URL base a partir de la peticion actual.
     * Funciona tanto en subdirectorio como en subdominio dedicado.
     */
    private function detectBaseURL(): string
    {
        // En CLI (php spark) no hay HTTP_HOST, devolvemos un default razonable.
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || empty($_SERVER['HTTP_HOST'] ?? '')) {
            return 'http://localhost:8080/';
        }

        $host   = $_SERVER['HTTP_HOST'];
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        // Caso `php spark serve`: el SCRIPT_NAME no es util, devolver solo host.
        if (PHP_SAPI === 'cli-server') {
            return $scheme . '://' . $host . '/';
        }

        // SCRIPT_NAME tipico: /index.php o /mantenimiento/index.php
        $scriptName  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir   = str_replace('/index.php', '', $scriptName);
        $basePath    = rtrim($scriptDir, '/');

        return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath) . '/';
    }
}