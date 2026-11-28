# Plan de implementación — Destinatario del resumen diario por usuario, separado del login

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan checkbox (`- [ ]`) para seguimiento. No iniciar implementación sin aprobación explícita.

**Goal:** permitir que el resumen diario de cada usuario llegue a un correo distinto del de ingreso, sin modificar las credenciales de Mariela, Carlos ni de ningún otro usuario, guardando el destinatario efectivo en cada entrega.

**Architecture:** monolito modular CI4 con `Presentation / Infrastructure → Application → Domain`. La precedencia del destinatario vive en una política pura de `Application/Notifications` (`UserNotificationEmailPolicy`), espejo de `CompanyNotificationRecipientPolicy`. `Infrastructure` solo consulta la política, persiste el snapshot en `notificacion_entregas.destinatario` y deja la traza; `Presentation` expone el campo en el ABM del tenant. No se agrega CQRS, eventos de dominio ni consistencia eventual.

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7.4, MariaDB/MySQL, PHPUnit con SQLite en memoria, Vue 3 + Vite + Tailwind, Vitest.

**Diseño aprobado:** `docs/superpowers/specs/2026-11-28-destinatario-resumen-diario-por-usuario-design.md` (commit `4a8015d0`).

**Especificación:** `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md` §10 y §18; `docs/CONTRATOS_ENTRE_CONTEXTOS.md`; `docs/NOTIFICACIONES_Y_WEBPUSH.md` §5.

---

## Restricciones globales

- **No cambiar `usuarios.email`** de Mariela (`mariela@tsa.com.ar`), Carlos (`gimenezcarlosgabriel@gmail.com`) ni de nadie como parte de este trabajo. El login usa `UsuarioModel::findByEmail()` y no se toca.
- `staging = fasa_189 / Docker / Coolify`; `producción = Ferozo / FTPS / sin CLI`. No desplegar ni tocar producción sin aprobación explícita.
- No editar migraciones ya aplicadas: la migración nueva es correctiva y su `down()` es seguro.
- Importes, claves de idempotencia, reintentos, bloqueo y agrupación por destinatario + sucursal quedan intactos.
- La clave lógica sigue siendo `{$eventKey}:usuario:{$userId}:email` / `:push`. El snapshot **no** entra en la clave.
- Los schemas SQLite de tests replican el tipo real de `usuarios.email_notificaciones` y de `notificacion_entregas.destinatario` (`VARCHAR(255) NULL`), según la regla obligatoria de `AGENTS.md`.
- El árbol tiene cambios ajenos preexistentes (`scripts/`, bitácoras, `node_modules/`). **No tocarlos ni incluirlos en `git add`.**
- Convención de commits: español, Conventional Commits, acotado por contexto.
- Una tarea no termina si solo compila: requiere prueba que falle primero, implementación, prueba en verde y commit.

## Mapa de archivos

| Archivo | Responsabilidad en este plan |
|---|---|
| `app/Application/Notifications/UserNotificationEmailPolicy.php` | **Nuevo.** Precedencia pura del correo de entrega. |
| `app/Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php` | **Nuevo.** Agrega las dos columnas con guardas. |
| `app/Application/Notifications/Port/NotificationDeliveryQueue.php` | `schedule()` recibe `?string $deliveryEmail = null`. |
| `app/Application/Notifications/PublishNotifiableEvent.php` | Pasa `$recipient->email` a `schedule()`. |
| `app/Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php` | Resuelve con la política; el DTO pasa a significar correo de entrega. |
| `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php` | Escribe y lee `destinatario`. |
| `app/Config/Services.php` | Inyecta la política en resolver y cola. |
| `app/Application/Organization/TenantAdministrationService.php` | Normaliza `email_notificaciones`; informa si cambió el correo de ingreso. |
| `app/Application/Organization/Port/TenantAdministrationPort.php` | Contrato de alta/edición con el campo nuevo. |
| `app/Infrastructure/Organization/CodeIgniterTenantAdministration.php` | Persiste, audita y lee el campo nuevo. |
| `app/Controllers/TenantAdmin.php` | Validación, POST y mensajes diferenciados. |
| `app/Presentation/AdministrationPayload.php` | Expone `oldInput` y `notificationEmail`. |
| `app/Models/UsuarioModel.php` | `allowedFields` incluye el campo. |
| `frontend/src/pages/admin/UsersAdminPage.vue` | Dos campos con etiquetas distintas. |
| `frontend/tests/admin/fixtures.js`, `frontend/tests/admin/AdminPages.test.js` | Datos y aserciones del campo nuevo. |
| `docs/CONTRATOS_ENTRE_CONTEXTOS.md`, bitácora nueva | Contrato y evidencia operativa. |

---

## Task 1: Política pura del destinatario

**Files:**
- Create: `app/Application/Notifications/UserNotificationEmailPolicy.php`
- Test: `tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

declare(strict_types=1);

use App\Application\Notifications\UserNotificationEmailPolicy;
use PHPUnit\Framework\TestCase;

final class UserNotificationEmailPolicyTest extends TestCase
{
    private UserNotificationEmailPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserNotificationEmailPolicy();
    }

    public function testValidNotificationEmailWinsAndIsNormalized(): void
    {
        self::assertSame(
            'mariela.resumen@tsa.com.ar',
            $this->policy->resolve('  Mariela.Resumen@TSA.com.ar ', 'mariela@tsa.com.ar'),
        );
    }

    public function testEmptyNotificationEmailFallsBackToTheLoginEmail(): void
    {
        self::assertSame('mariela@tsa.com.ar', $this->policy->resolve(null, ' Mariela@TSA.com.ar '));
        self::assertSame('mariela@tsa.com.ar', $this->policy->resolve('   ', 'mariela@tsa.com.ar'));
    }

    public function testInvalidNotificationEmailFallsBackToTheLoginEmail(): void
    {
        self::assertSame(
            'gimenezcarlosgabriel@gmail.com',
            $this->policy->resolve('no-es-un-correo', 'GimenezCarlosGabriel@gmail.com'),
        );
    }

    public function testResolveWithReasonReportsTheFallbackForAnInvalidValue(): void
    {
        self::assertSame(
            ['email' => 'mariela@tsa.com.ar', 'usedFallback' => true],
            $this->policy->resolveWithReason('casilla-inexistente', 'mariela@tsa.com.ar'),
        );
        self::assertSame(
            ['email' => 'resumen@tsa.com.ar', 'usedFallback' => false],
            $this->policy->resolveWithReason(' Resumen@TSA.com.ar ', 'mariela@tsa.com.ar'),
        );
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php
```
Esperado: `ERROR`/`FAIL`, clase `UserNotificationEmailPolicy` no encontrada. Si Windows no tiene `php` en PATH:
```bash
wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php"
```

- [ ] **Step 3: Implementar la política**

```php
<?php

declare(strict_types=1);

namespace App\Application\Notifications;

/**
 * Destinatario del resumen diario por usuario.
 *
 * `email` es la credencial de ingreso y nunca se modifica aquí. La política
 * sólo elige a qué dirección se entrega el resumen: un correo de resumen
 * ausente o inválido cae al correo de ingreso, conservando el comportamiento
 * anterior. Es la única fuente de esta precedencia; los adaptadores no
 * repiten el condicional.
 */
final readonly class UserNotificationEmailPolicy
{
    public function resolve(?string $notificationEmail, string $loginEmail): string
    {
        return $this->resolveWithReason($notificationEmail, $loginEmail)['email'];
    }

    /** @return array{email: string, usedFallback: bool} */
    public function resolveWithReason(?string $notificationEmail, string $loginEmail): array
    {
        $login = mb_strtolower(trim($loginEmail));
        $candidate = mb_strtolower(trim((string) $notificationEmail));

        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            return ['email' => $candidate, 'usedFallback' => false];
        }

        return ['email' => $login, 'usedFallback' => true];
    }
}
```

- [ ] **Step 4: Verificar en verde**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php
```
Esperado: `OK (4 tests)`.

- [ ] **Step 5: Commit**

```bash
git add app/Application/Notifications/UserNotificationEmailPolicy.php tests/unit/Application/Notifications/UserNotificationEmailPolicyTest.php
git commit -m "feat(notificaciones): politica de correo de resumen con fallback al login"
```

---

## Task 2: Migración correctiva de las dos columnas

**Files:**
- Create: `app/Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php`
- Test: `tests/database/AddUserNotificationEmailSnapshotMigrationTest.php`

- [ ] **Step 1: Escribir el test de migración**

```php
<?php

declare(strict_types=1);

use App\Database\Migrations\AddUserNotificationEmailSnapshot;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddUserNotificationEmailSnapshotMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de la migración requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email VARCHAR(255) NULL, nombre VARCHAR(255) NULL)');
        $this->database->query('CREATE TABLE notificacion_entregas (id INTEGER PRIMARY KEY AUTOINCREMENT, clave_entrega VARCHAR(220) NOT NULL)');
        require_once APPPATH . 'Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsBothNullableColumnsWithoutTouchingExistingData(): void
    {
        $this->database->table('usuarios')->insert([
            'id' => 7, 'nombre' => 'Carlos', 'email' => 'gimenezcarlosgabriel@gmail.com',
        ]);
        $this->database->table('notificacion_entregas')->insert([
            'id' => 1, 'clave_entrega' => 'evento:1:usuario:7:email',
        ]);

        $migration = new AddUserNotificationEmailSnapshot(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('email_notificaciones', 'usuarios'));
        self::assertTrue($this->database->fieldExists('destinatario', 'notificacion_entregas'));

        $user = $this->database->table('usuarios')->where('id', 7)->get()->getRowArray();
        self::assertNull($user['email_notificaciones']);
        self::assertSame('gimenezcarlosgabriel@gmail.com', $user['email']);

        $delivery = $this->database->table('notificacion_entregas')->where('id', 1)->get()->getRowArray();
        self::assertNull($delivery['destinatario']);
        self::assertSame('evento:1:usuario:7:email', $delivery['clave_entrega']);
    }

    public function testDownDropsOnlyTheNewColumnsAndIsSafeToRepeat(): void
    {
        $migration = new AddUserNotificationEmailSnapshot(Database::forge($this->database));
        $migration->up();
        $migration->down();
        $migration->down();

        self::assertFalse($this->database->fieldExists('email_notificaciones', 'usuarios'));
        self::assertFalse($this->database->fieldExists('destinatario', 'notificacion_entregas'));
        self::assertTrue($this->database->fieldExists('email', 'usuarios'));
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage tests/database/AddUserNotificationEmailSnapshotMigrationTest.php
```
Esperado: `ERROR`, migración no encontrada.

- [ ] **Step 3: Escribir la migración**

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Correo de resumen por usuario y foto del destinatario en cada entrega.
 *
 * `usuarios.email` sigue siendo la credencial de ingreso y no se modifica.
 * `notificacion_entregas.destinatario` es la dirección efectiva al encolar;
 * NULL significa "entrega antigua": se resuelve con la política vigente al
 * despachar, sin reescribir ni reenviar nada.
 */
final class AddUserNotificationEmailSnapshot extends Migration
{
    private const USERS = 'usuarios';

    private const DELIVERIES = 'notificacion_entregas';

    public function up(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::USERS)
            && ! $this->db->fieldExists('email_notificaciones', self::USERS)) {
            $this->forge->addColumn(self::USERS, [
                'email_notificaciones' => [
                    'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'email',
                ],
            ]);
            $this->db->resetDataCache();
        }

        if ($this->db->tableExists(self::DELIVERIES)
            && ! $this->db->fieldExists('destinatario', self::DELIVERIES)) {
            $this->forge->addColumn(self::DELIVERIES, [
                'destinatario' => [
                    'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'clave_entrega',
                ],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists(self::DELIVERIES)
            && $this->db->fieldExists('destinatario', self::DELIVERIES)) {
            $this->forge->dropColumn(self::DELIVERIES, 'destinatario');
            $this->db->resetDataCache();
        }

        if ($this->db->tableExists(self::USERS)
            && $this->db->fieldExists('email_notificaciones', self::USERS)) {
            $this->forge->dropColumn(self::USERS, 'email_notificaciones');
            $this->db->resetDataCache();
        }
    }
}
```

- [ ] **Step 4: Verificar en verde**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage tests/database/AddUserNotificationEmailSnapshotMigrationTest.php
```
Esperado: `OK (2 tests)`.

- [ ] **Step 5: Commit**

```bash
git add app/Database/Migrations/2026-11-28-100000_AddUserNotificationEmailSnapshot.php tests/database/AddUserNotificationEmailSnapshotMigrationTest.php
git commit -m "feat(notificaciones): migracion del correo de resumen y del destinatario por entrega"
```

---

## Task 3: Firma opcional del destinatario en el puerto y sus nueve implementaciones

**Files:**
- Modify: `app/Application/Notifications/Port/NotificationDeliveryQueue.php:12`
- Modify: `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php:40`
- Modify: los 7 dobles listados abajo
- Test: `tests/unit/Application/Notifications/NotificationDeliveryQueueContractTest.php`

PHP exige que cada implementación acepte todos los parámetros declarados en la interfaz, incluso los opcionales. Por eso el cambio es mecánico y debe aplicarse en **los 9 archivos** de la tabla.

| Archivo | Método |
|---|---|
| `app/Application/Notifications/Port/NotificationDeliveryQueue.php` | interfaz |
| `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php` | adaptador real |
| `tests/unit/Application/Notifications/NotificationUseCasesTest.php` | `FakeNotificationDeliveryQueue` |
| `tests/unit/Application/Notifications/WebPushOperationalTest.php` | `WebPushQueueFake` |
| `tests/unit/Application/Notifications/RunNotificationCycleStagesTest.php` | `CycleDeliveryQueue` |
| `tests/unit/Application/Notifications/NotificationEmailDispatchTest.php` | `Issue144Queue` |
| `tests/unit/Application/Notifications/NotificationDispatchEligibilityTest.php` | `EligibilityFakeDeliveryQueue` |
| `tests/unit/Application/Notifications/NotificationDispatchCancellationTest.php` | `CancellationRaceDeliveryQueue` |
| `tests/unit/Application/Notifications/ManagementReportExpirationSeparationTest.php` | `ManagementReportQueue` |

- [ ] **Step 1: Escribir el test de contrato**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationDeliveryQueueContractTest extends TestCase
{
    public function testEveryScheduleDeclarationAcceptsTheOptionalDeliveryEmail(): void
    {
        $sources = [
            APPPATH . 'Application/Notifications/Port/NotificationDeliveryQueue.php',
            APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php',
            __DIR__ . '/NotificationUseCasesTest.php',
            __DIR__ . '/WebPushOperationalTest.php',
            __DIR__ . '/RunNotificationCycleStagesTest.php',
            __DIR__ . '/NotificationEmailDispatchTest.php',
            __DIR__ . '/NotificationDispatchEligibilityTest.php',
            __DIR__ . '/NotificationDispatchCancellationTest.php',
            __DIR__ . '/ManagementReportExpirationSeparationTest.php',
        ];

        foreach ($sources as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            self::assertStringContainsString(
                'NotificationPreference $preference, ?string $deliveryEmail = null): void',
                $source,
                $path,
            );
        }
    }

    public function testTheLogicalDeliveryKeyDoesNotIncludeTheRecipient(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php');
        self::assertIsString($source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:email', $source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:push', $source);
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationDeliveryQueueContractTest.php
```
Esperado: `FAIL`, la firma buscada no aparece.

- [ ] **Step 3: Reemplazar la firma en los 9 archivos**

La firma actual, idéntica en los nueve lugares, es:

```php
public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void
```

La nueva firma es:

```php
public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference, ?string $deliveryEmail = null): void
```

Aplicar esa misma sustitución exacta en cada uno de los 9 archivos de la tabla. En el puerto, además, actualizar el DocBlock para documentar el parámetro:

```php
    /**
     * @param string|null $deliveryEmail correo de entrega ya resuelto; null conserva
     *                                  el comportamiento histórico y se resuelve en `due()`
     */
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference, ?string $deliveryEmail = null): void;
```

Verificar que no quede ninguna firma vieja:

```bash
grep -rn "NotificationPreference \$preference): void" app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php app/Application/Notifications/Port/NotificationDeliveryQueue.php tests/unit/Application/Notifications/
```
Esperado: sin resultados.

- [ ] **Step 4: Verificar en verde**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationDeliveryQueueContractTest.php
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/
```
Esperado: `OK`, sin errores de firma en los dobles.

- [ ] **Step 5: Commit**

```bash
git add app/Application/Notifications/Port/NotificationDeliveryQueue.php app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php tests/unit/Application/Notifications/
git commit -m "refactor(notificaciones): destinatario opcional en la firma de la cola"
```

---

## Task 4: El resolver entrega el correo de resumen

**Files:**
- Modify: `app/Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php:16,25,45`
- Modify: `app/Config/Services.php:94,724-731`
- Test: `tests/unit/Infrastructure/Notifications/NotificationRecipientEmailTest.php`

- [ ] **Step 1: Escribir el test del adaptador con SQLite**

```php
<?php

declare(strict_types=1);

use App\Application\Notifications\NotificationRecipientScopePolicy;
use App\Application\Notifications\UserNotificationEmailPolicy;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use App\Infrastructure\Notifications\CodeIgniterNotificationRecipientResolver;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class NotificationRecipientEmailTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del resolver requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre TEXT, email VARCHAR(255) NULL, email_notificaciones VARCHAR(255) NULL, telefono VARCHAR(20) NULL, activo INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL)');
        $this->db->query('CREATE TABLE usuario_roles (usuario_id INTEGER, rol_id INTEGER)');
        $this->db->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre VARCHAR(255) NOT NULL)');
        $this->db->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER)');
        $this->db->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY, clave VARCHAR(255) NOT NULL)');
        $this->db->query('CREATE TABLE usuario_sucursales (usuario_id INTEGER, sucursal_id INTEGER)');

        $this->db->table('roles')->insert(['id' => 3, 'nombre' => 'Técnico']);
        $this->db->table('permisos')->insertBatch([
            ['id' => 30, 'clave' => 'ordenes.ver'],
            ['id' => 31, 'clave' => 'notificaciones.ver'],
        ]);
        $this->db->table('rol_permisos')->insertBatch([
            ['rol_id' => 3, 'permiso_id' => 30],
            ['rol_id' => 3, 'permiso_id' => 31],
        ]);
        $this->db->table('usuario_roles')->insert(['usuario_id' => 7, 'rol_id' => 3]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testRecipientUsesTheNotificationEmailWhenPresent(): void
    {
        $this->seedUser('resumen@example.test');

        self::assertSame('resumen@example.test', $this->resolve()[0]->email);
    }

    public function testRecipientFallsBackToTheLoginEmailWhenTheNotificationEmailIsMissing(): void
    {
        $this->seedUser(null);

        self::assertSame('operador@example.test', $this->resolve()[0]->email);
    }

    public function testRecipientFallsBackToTheLoginEmailWhenTheNotificationEmailIsInvalid(): void
    {
        $this->seedUser('casilla-inexistente');

        self::assertSame('operador@example.test', $this->resolve()[0]->email);
    }

    private function seedUser(?string $notificationEmail): void
    {
        $this->db->table('usuarios')->insert([
            'id' => 7, 'empresa_id' => 5, 'nombre' => 'Operador',
            'email' => 'operador@example.test', 'email_notificaciones' => $notificationEmail,
            'telefono' => null, 'activo' => 1, 'deleted_at' => null,
        ]);
    }

    /** @return list<\App\Application\Notifications\NotificationRecipient> */
    private function resolve(): array
    {
        $resolver = new CodeIgniterNotificationRecipientResolver(
            new NotificationRecipientScopePolicy(),
            new UserNotificationEmailPolicy(),
            $this->db,
        );

        return $resolver->resolve(new NotifiableEvent(
            5,
            null,
            'orden.generada',
            NotificationSeverity::WARNING,
            'Orden generada',
            'Detalle',
            'orden',
            '1',
            'orden:1',
            null,
            new DateTimeImmutable('2026-11-28 07:00:00', new DateTimeZone('UTC')),
        ));
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationRecipientEmailTest.php
```
Esperado: `ERROR`, el constructor no acepta la política y la columna no existe en el `SELECT`.

- [ ] **Step 3: Modificar el resolver**

Agregar el import:
```php
use App\Application\Notifications\UserNotificationEmailPolicy;
```

Reemplazar el constructor:
```php
    public function __construct(
        private NotificationRecipientScopePolicy $scope,
        private UserNotificationEmailPolicy $emails,
        private ?BaseConnection $db = null,
    ) {
        $this->db ??= Database::connect();
    }
```

Reemplazar el `select` en `:25`:
```php
            ->select('DISTINCT u.id, u.empresa_id, u.email, u.email_notificaciones, u.telefono', false)
```

Reemplazar la construcción del DTO en `:45`:
```php
                $rawNotificationEmail = $row['email_notificaciones'] ?? null;
                $resolved = $this->emails->resolveWithReason(
                    $rawNotificationEmail === null ? null : (string) $rawNotificationEmail,
                    (string) $row['email'],
                );
                if ($resolved['usedFallback'] && $rawNotificationEmail !== null && trim((string) $rawNotificationEmail) !== '') {
                    log_message('notice', 'Correo de resumen inválido para el usuario {user}; se usa el correo de ingreso.', [
                        'user' => $userId,
                    ]);
                }
                $recipients[] = new NotificationRecipient($userId, (int) $row['empresa_id'], $resolved['email'], $row['telefono'] === null ? null : (string) $row['telefono']);
```

La comparación previa al `log_message()` es **solo para la traza**: la decisión la toma la política.

- [ ] **Step 4: Actualizar el composition root**

En `app/Config/Services.php`, agregar junto a los imports de `App\Application\Notifications\` (cerca de `:94`):
```php
use App\Application\Notifications\UserNotificationEmailPolicy;
```

Reemplazar `Services::notificationRecipients()` (`:724-731`):
```php
    public static function notificationRecipients(bool $getShared = true): CodeIgniterNotificationRecipientResolver
    {
        if ($getShared) {
            return static::getSharedInstance('notificationRecipients');
        }

        return new CodeIgniterNotificationRecipientResolver(new NotificationRecipientScopePolicy(), new UserNotificationEmailPolicy(), db_connect());
    }
```

En el mismo archivo, `notificationDeliveryQueue()` (`:631-645`) recibe la política como séptimo argumento explícito:

```php
        return new CodeIgniterNotificationDeliveryQueue(
            static::notificationClock(false),
            new NotificationDeliverySchedule(),
            new NotificationChannelPolicy(),
            static::notificationChannelAvailability(false),
            (string) env('alerts.dailyRunTime', '07:00'),
            db_connect(),
            new UserNotificationEmailPolicy(),
        );
```

El adaptador conserva un valor por defecto para no romper los llamadores posicionales de los tests (ver Task 5).

- [ ] **Step 5: Verificar en verde y sin regresiones**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationRecipientEmailTest.php
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationUseCasesTest.php
```
Esperado: `OK (3 tests)` y `OK`.

- [ ] **Step 6: Commit**

```bash
git add app/Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php app/Config/Services.php tests/unit/Infrastructure/Notifications/NotificationRecipientEmailTest.php
git commit -m "feat(notificaciones): resolver el resumen con el correo de notificaciones del usuario"
```

---

## Task 5: Snapshot en la cola y despacho con el destinatario efectivo

**Files:**
- Modify: `app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php:35,40-64,113-123,228-240`
- Modify: `app/Application/Notifications/PublishNotifiableEvent.php:49`
- Modify: `tests/unit/Application/Notifications/NotificationChannelAvailabilityTest.php`
- Modify: `tests/unit/Application/Notifications/NotificationUseCasesTest.php:226-227`

- [ ] **Step 1: Ampliar el schema SQLite y el helper de siembra**

En `NotificationChannelAvailabilityTest.php`, la tabla `usuarios` (`:95-102`) pasa a:
```sql
id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL,
email VARCHAR(255) NULL, email_notificaciones VARCHAR(255) NULL, activo INTEGER NOT NULL DEFAULT 1, deleted_at DATETIME NULL
```

La tabla `notificacion_entregas` (`:55-67`) agrega después de `clave_entrega`:
```sql
destinatario VARCHAR(255) NULL,
```

Reemplazar `seedPendingEmailDelivery()` (`:304-323`) por:

```php
    private function seedPendingEmailDelivery(?string $notificationEmail = null, ?string $snapshot = null): int
    {
        $this->db->table('usuarios')->insert([
            'id' => 7, 'empresa_id' => 5, 'nombre' => 'Operador de prueba',
            'email' => 'operador@example.test', 'email_notificaciones' => $notificationEmail,
            'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('notificaciones')->insert([
            'id' => 10, 'empresa_id' => 5, 'sucursal_id' => null, 'usuario_id' => 7,
            'tipo_evento' => 'evento', 'severidad' => 'WARNING', 'titulo' => 'Titulo',
            'resumen' => 'Detalle', 'entidad_tipo' => 'equipo', 'entidad_id' => '1',
            'url' => null, 'clave_evento' => 'evento:1', 'estado' => 'PENDIENTE',
            'leida_en' => null, 'created_at' => '2026-11-17 07:00:00', 'updated_at' => null,
        ]);
        $this->db->table('notificacion_entregas')->insert([
            'notificacion_id' => 10, 'canal' => 'EMAIL', 'clave_entrega' => 'evento:1:usuario:7:email',
            'destinatario' => $snapshot, 'estado' => 'PENDIENTE', 'intentos' => 0,
            'created_at' => '2026-11-17 07:00:00',
        ]);

        return (int) $this->db->table('notificacion_entregas')->select('id')->get()->getRowArray()['id'];
    }
```

Reemplazar el gateway de grabación (`:410-419`) para capturar el destinatario:

```php
final class ChannelAvailabilityRecordingEmailGateway implements \App\Application\Notifications\Port\EmailNotificationGateway
{
    /** @var list<array<int,array<string,mixed>>> */
    public array $batches = [];

    /** @var list<string> */
    public array $recipients = [];

    public function sendDigest(string $recipient, array $notifications): void
    {
        $this->recipients[] = $recipient;
        $this->batches[] = $notifications;
    }
}
```

- [ ] **Step 2: Escribir los tests que fallan**

Agregar estos cuatro métodos a `NotificationChannelAvailabilityTest.php`:

```php
    public function testSchedulePersistsTheDeliveryEmailSnapshotWithoutChangingTheLogicalKey(): void
    {
        $queue = $this->queue(true, true);
        $queue->schedule(10, 7, 'evento:1', NotificationSeverity::WARNING, $this->preference(), 'resumen@example.test');
        $queue->schedule(10, 7, 'evento:1', NotificationSeverity::WARNING, $this->preference(), 'resumen@example.test');

        $rows = $this->db->table('notificacion_entregas')->get()->getResultArray();
        self::assertCount(1, $rows, 'La idempotencia no cambia con el snapshot.');
        self::assertSame('evento:1:usuario:7:email', $rows[0]['clave_entrega']);
        self::assertSame('resumen@example.test', $rows[0]['destinatario']);
    }

    public function testDispatchUsesTheStoredSnapshotEvenIfTheLoginEmailChanged(): void
    {
        $this->seedPendingEmailDelivery(null, 'resumen@example.test');
        $this->db->table('usuarios')->where('id', 7)->update(['email' => 'nuevo-ingreso@example.test']);
        $gateway = new ChannelAvailabilityRecordingEmailGateway();

        (new \App\Application\Notifications\RunNotificationDispatch(
            $this->queue(true, true), $gateway, new ChannelAvailabilityEmptyPushGateway(), new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-28-07-00');

        self::assertSame(['resumen@example.test'], $gateway->recipients);
    }

    public function testDispatchResolvesLiveFromTheNotificationEmailWhenTheSnapshotIsNull(): void
    {
        $this->seedPendingEmailDelivery('resumen@example.test', null);
        $gateway = new ChannelAvailabilityRecordingEmailGateway();

        (new \App\Application\Notifications\RunNotificationDispatch(
            $this->queue(true, true), $gateway, new ChannelAvailabilityEmptyPushGateway(), new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-28-07-00');

        self::assertSame(['resumen@example.test'], $gateway->recipients);
    }

    public function testLegacyDeliveryWithoutSnapshotOrNotificationEmailUsesTheLoginEmail(): void
    {
        $this->seedPendingEmailDelivery(null, null);
        $gateway = new ChannelAvailabilityRecordingEmailGateway();

        (new \App\Application\Notifications\RunNotificationDispatch(
            $this->queue(true, true), $gateway, new ChannelAvailabilityEmptyPushGateway(), new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-28-07-00');

        self::assertSame(['operador@example.test'], $gateway->recipients);
    }
```

Actualizar el helper `queue()` (`:325-335`) para inyectar la política de forma explícita:

```php
        return new CodeIgniterNotificationDeliveryQueue(
            $this->clock,
            new NotificationDeliverySchedule(),
            new NotificationChannelPolicy(),
            new ChannelAvailabilityStub($emailAvailable, $pushAvailable),
            '07:00',
            $this->db,
            new \App\Application\Notifications\UserNotificationEmailPolicy(),
        );
```

- [ ] **Step 3: Ejecutar y confirmar que fallan**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage --filter 'Schedule|Snapshot|Legacy' tests/unit/Application/Notifications/NotificationChannelAvailabilityTest.php
```
Esperado: `FAIL`, la columna `destinatario` no existe y el despacho sigue entregando a `operador@example.test`.

- [ ] **Step 4: Modificar la cola**

Agregar el import:
```php
use App\Application\Notifications\UserNotificationEmailPolicy;
```

Reemplazar el constructor (`:35-38`) — el parámetro se agrega **al final** con valor por defecto para no romper los llamadores posicionales existentes:

```php
    public function __construct(
        private NotificationClock $clock,
        private NotificationDeliverySchedule $schedule,
        private NotificationChannelPolicy $policy,
        private NotificationChannelAvailability $channels,
        private string $dailyRunTime = '07:00',
        private ?BaseConnection $db = null,
        private ?UserNotificationEmailPolicy $recipientEmails = null,
    ) {
        $this->db ??= Database::connect();
        $this->recipientEmails ??= new UserNotificationEmailPolicy();
    }
```

En `schedule()` (`:48-63`), reemplazar las dos llamadas a `enqueue()`:

```php
        if ($wantsEmail && $emailAvailable) {
            $this->enqueue($notificationId, 'EMAIL', "{$eventKey}:usuario:{$userId}:email", $preference->email, $deliveryEmail);
        } elseif ($wantsEmail && ! $emailAvailable) {
```
```php
        if ($this->policy->shouldSchedule($preference->push, $severity, $this->channels->isAvailable(NotificationChannel::PUSH))) {
            $this->enqueue($notificationId, 'PUSH', "{$eventKey}:usuario:{$userId}:push", $preference->push);
        }
```

Reemplazar `due()` (`:113-123`):

```php
    public function due(string $channel, int $limit): array
    {
        $rows = $this->db->table('notificacion_entregas d')
            ->select('d.id, d.notificacion_id, n.usuario_id, n.sucursal_id, d.destinatario, u.email AS login_email, u.email_notificaciones, d.canal, n.titulo, n.resumen, n.url, n.severidad, d.intentos')
            ->join('notificaciones n', 'n.id = d.notificacion_id', 'inner')
            ->join('usuarios u', 'u.id = n.usuario_id AND u.activo = 1 AND u.deleted_at IS NULL', 'inner')
            ->where('d.canal', strtoupper($channel))
            ->whereIn('d.estado', ['PENDIENTE', 'REINTENTO'])
            ->groupStart()->where('d.proximo_intento', null)->orWhere('d.proximo_intento <=', $this->clock->now()->format('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('d.id')->limit(max(1, min(1000, $limit)))->get()->getResultArray();

        foreach ($rows as $index => $row) {
            if ($row['destinatario'] !== null) {
                $rows[$index]['email'] = (string) $row['destinatario'];
                continue;
            }

            $rawNotificationEmail = $row['email_notificaciones'] ?? null;
            $resolved = $this->recipientEmails->resolveWithReason(
                $rawNotificationEmail === null ? null : (string) $rawNotificationEmail,
                (string) $row['login_email'],
            );
            if ($resolved['usedFallback'] && $rawNotificationEmail !== null && trim((string) $rawNotificationEmail) !== '') {
                log_message('notice', 'Correo de resumen inválido para el usuario {user}; se usa el correo de ingreso.', [
                    'user' => (int) $row['usuario_id'],
                ]);
            }
            $rows[$index]['email'] = $resolved['email'];
        }

        return $rows;
    }
```

`due()` sigue devolviendo la clave `email` que consume `RunNotificationDispatch::dispatchEmail()`; ahora significa correo de entrega.

Reemplazar `enqueue()` (`:228-240`):

```php
    private function enqueue(int $notificationId, string $channel, string $key, DeliveryMode $mode, ?string $recipientEmail = null): void
    {
        $now = $this->clock->now();
        $next = $this->schedule->nextAttempt($mode, $now, $this->dailyRunTime)?->format('Y-m-d H:i:s');
        $this->db->table('notificacion_entregas')->ignore(true)->insert([
            'notificacion_id' => $notificationId,
            'canal' => $channel,
            'clave_entrega' => $key,
            'destinatario' => $recipientEmail,
            'estado' => 'PENDIENTE',
            'proximo_intento' => $next,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
```

- [ ] **Step 5: Pasar el destinatario desde el caso de uso**

En `app/Application/Notifications/PublishNotifiableEvent.php:49`:

```php
                $this->deliveries->schedule($notificationId, $recipient->userId, $event->logicalKey(), $event->severity(), $preference, $recipient->email);
```

En `tests/unit/Application/Notifications/NotificationUseCasesTest.php`, el doble registra el correo (`:226-227`):

```php
    public array $scheduled = []; public array $dueByChannel = []; public array $delivered = []; public array $failed = []; public array $skipped = []; public array $deferred = [];
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference, ?string $deliveryEmail = null): void { $this->scheduled[] = ['notification' => $notificationId, 'user' => $userId, 'email' => $deliveryEmail]; }
```

Agregar el test del caso de uso en la misma clase:

```php
    public function testPublishPassesTheResolvedDeliveryEmailToTheQueue(): void
    {
        $repository = new FakeNotificationRepository();
        $queue = new FakeNotificationDeliveryQueue();
        $resolver = new FakeNotificationRecipientResolver([
            new NotificationRecipient(10, 5, 'resumen@example.com'),
        ]);
        $useCase = new PublishNotifiableEvent($resolver, $repository, new FakeNotificationPreferenceStore(), $queue, new FakeNotificationUnitOfWork());

        $useCase->execute($this->event());

        self::assertSame('resumen@example.com', $queue->scheduled[0]['email']);
    }
```

- [ ] **Step 6: Verificar en verde**

```bash
php -d extension=sqlite3 vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationChannelAvailabilityTest.php
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationUseCasesTest.php
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications/NotificationPersistenceContractTest.php
```
Esperado: `OK` en las tres. El test de contrato de persistencia sigue pasando porque la clave no cambió.

- [ ] **Step 7: Commit**

```bash
git add app/Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php app/Application/Notifications/PublishNotifiableEvent.php tests/unit/Application/Notifications/
git commit -m "feat(notificaciones): guardar y usar el destinatario efectivo de cada entrega"
```

---

## Task 6: Normalización y persistencia del campo en Organización

**Files:**
- Modify: `app/Application/Organization/TenantAdministrationService.php:51-99,197-212`
- Modify: `app/Application/Organization/Port/TenantAdministrationPort.php:25-46`
- Modify: `app/Infrastructure/Organization/CodeIgniterTenantAdministration.php:51-70,233-331,433-445`
- Test: `tests/unit/Application/Organization/TenantAdministrationServiceTest.php`

- [ ] **Step 1: Escribir los tests que fallan**

Actualizar el test existente `testUpdateUserNormalizesEmailAndPreservesAccountUpdateScope` (`:81-103`) para incluir el campo nuevo. El payload esperado pasa a ser:

```php
        self::assertSame([
            'nombre' => 'Carlos',
            'email' => 'gimenezcarlosgabriel@gmail.com',
            'email_notificaciones' => null,
            'telefono' => '5493764123456',
            'activo' => 1,
        ], $port->userData);
```

Agregar tres tests a la misma clase:

```php
    public function testUpdateUserNormalizesTheNotificationEmailWithoutTouchingTheLoginEmail(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $service->updateUser($this->administrator(), 2, [
            'nombre' => 'Mariela',
            'email' => 'mariela@tsa.com.ar',
            'email_notificaciones' => '  Mariela.Resumen@TSA.com.ar ',
            'telefono' => '5493764123456',
            'activo' => 1,
        ], 'Cambio de destino de resumen');

        self::assertSame('mariela.resumen@tsa.com.ar', $port->userData['email_notificaciones']);
        self::assertSame('mariela@tsa.com.ar', $port->userData['email']);
    }

    public function testUpdateUserRejectsAnInvalidNotificationEmail(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El correo para resúmenes no es válido.');

        $service->updateUser($this->administrator(), 2, [
            'nombre' => 'Mariela', 'email' => 'mariela@tsa.com.ar',
            'email_notificaciones' => 'casilla-inexistente', 'telefono' => '', 'activo' => 1,
        ], 'Cambio de destino de resumen');
    }

    public function testCreateUserNormalizesTheNotificationEmail(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $service->createUser($this->administrator(), [
            'nombre' => 'Mariela', 'email' => 'mariela@tsa.com.ar',
            'email_notificaciones' => ' Resumen@TSA.com.ar ', 'telefono' => '', 'password' => 'clave-segura-123',
        ], [3], [], 'Alta aprobada');

        self::assertSame('resumen@tsa.com.ar', $port->userData['email_notificaciones']);
        self::assertSame('mariela@tsa.com.ar', $port->userData['email']);
    }
```

El doble `RecordingTenantAdministration` (`:181-249`) necesita un campo para simular el resultado:

```php
    public bool $loginEmailChanged = false;
```

y su `updateUser` (`:233-238`) pasa a:

```php
    public function updateUser(int $companyId, int $userId, array $data, string $reason, int $actorUserId): bool
    {
        $this->lastCompanyId = $companyId;
        $this->userData = $data;
        $this->reason = $reason;

        return $this->loginEmailChanged;
    }
```

- [ ] **Step 2: Ejecutar y confirmar que fallan**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Organization/TenantAdministrationServiceTest.php
```
Esperado: `FAIL`, la clave `email_notificaciones` no aparece en el payload.

- [ ] **Step 3: Normalizar en el servicio**

Agregar el helper privado junto a `normalizePhone()`:

```php
    private function normalizeNotificationEmail(mixed $value): ?string
    {
        $email = mb_strtolower(trim((string) $value));
        if ($email === '') {
            return null;
        }
        if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('El correo para resúmenes no es válido.');
        }

        return $email;
    }
```

En `normalizeNewUser()` (`:197-212`), agregar la normalización y la clave al array devuelto:

```php
        $notificationEmail = $this->normalizeNotificationEmail($data['email_notificaciones'] ?? null);
        // ...validaciones existentes...
        return ['nombre' => $name, 'email' => $email, 'email_notificaciones' => $notificationEmail, 'telefono' => $phone, 'password' => $password];
```

En `updateUser()` (`:73-99`), agregar:

```php
        $notificationEmail = $this->normalizeNotificationEmail($data['email_notificaciones'] ?? null);
```

y reemplazar el llamado al puerto por:

```php
        return $this->administration->updateUser(
            $companyId,
            $userId,
            ['nombre' => $name, 'email' => $email, 'email_notificaciones' => $notificationEmail, 'telefono' => $phone, 'activo' => $active],
            $reason,
            $actor->userId(),
        );
```

El tipo de retorno del método pasa de `: void` a `: bool`.

- [ ] **Step 4: Actualizar el puerto**

En `TenantAdministrationPort.php`:

```php
    /** @param array{nombre: string, email: string, email_notificaciones: string|null, telefono: string|null, password: string} $data */
```
```php
    /**
     * @param array{nombre: string, email: string, email_notificaciones: string|null, telefono: string|null, activo: int} $data
     * @return bool true cuando cambió el correo de ingreso
     */
    public function updateUser(
        int $companyId,
        int $userId,
        array $data,
        string $reason,
        int $actorUserId,
    ): bool;
```

- [ ] **Step 5: Persistir y auditar en el adaptador**

En `CodeIgniterTenantAdministration.php`:

`usersOverview()` (`:62`):
```php
            ->select('id, empresa_id, nombre, email, email_notificaciones, telefono, activo, ultimo_acceso')
```

`createUser()` (`:248-260`), agregar al `insert`:
```php
                'email'         => $data['email'],
                'email_notificaciones' => $data['email_notificaciones'],
```
y al `after` de la auditoría (`:277-283`):
```php
                    'email'      => $data['email'],
                    'email_notificaciones' => $data['email_notificaciones'],
```

`lockedTenantUser()` (`:436`):
```php
            'SELECT id, nombre, email, email_notificaciones, telefono, activo FROM usuarios '
```

`updateUser()` (`:295-331`): reemplazar el bloque `try` por:

```php
        try {
            $user = $this->lockedTenantUser($companyId, $userId);
            $this->assertUniqueEmail($data['email'], $userId);
            $before = [
                'nombre' => $user['nombre'], 'email' => $user['email'],
                'email_notificaciones' => $user['email_notificaciones'] ?? null,
                'telefono' => $user['telefono'] ?? null, 'activo' => (int) $user['activo'],
            ];
            $loginEmailChanged = mb_strtolower(trim((string) $user['email'])) !== mb_strtolower(trim((string) $data['email']));
            $this->database->table('usuarios')->where('id', $userId)->where('empresa_id', $companyId)->update([
                ...$data,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
            $this->audit->record(new AuditEntry(
                scope: AuditScope::EMPRESA,
                companyId: $companyId,
                branchId: null,
                action: AuditAction::USUARIO_ACTUALIZADO,
                entity: 'usuarios',
                entityId: (string) $userId,
                actorUserId: $actorUserId,
                actorCompanyId: $companyId,
                before: $before,
                after: $data,
                reason: $reason,
            ));
            $this->commitOrFail();

            return $loginEmailChanged;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
```

El `...$data` existente ya escribe `email_notificaciones` como columna; no se agrega ninguna ruta alternativa que escriba `email`.

- [ ] **Step 6: Verificar en verde**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Organization/TenantAdministrationServiceTest.php
php -l app/Infrastructure/Organization/CodeIgniterTenantAdministration.php
php -l app/Application/Organization/Port/TenantAdministrationPort.php
```
Esperado: `OK` y `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add app/Application/Organization/TenantAdministrationService.php app/Application/Organization/Port/TenantAdministrationPort.php app/Infrastructure/Organization/CodeIgniterTenantAdministration.php tests/unit/Application/Organization/TenantAdministrationServiceTest.php
git commit -m "feat(organizacion): correo de resumen en altas y ediciones de usuario"
```

---

## Task 7: ABM visible y mensajes que no confunden

**Files:**
- Modify: `app/Controllers/TenantAdmin.php:90-157`
- Modify: `app/Presentation/AdministrationPayload.php:174-187`
- Modify: `app/Models/UsuarioModel.php:14-16`
- Modify: `frontend/src/pages/admin/UsersAdminPage.vue:58-67,157-166,183-187`
- Modify: `frontend/tests/admin/fixtures.js:134,143-176`
- Modify: `frontend/tests/admin/AdminPages.test.js:139-164`
- Create: `tests/unit/Application/Organization/TenantUserNotificationEmailContractTest.php`

- [ ] **Step 1: Escribir el test de contrato que falla**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TenantUserNotificationEmailContractTest extends TestCase
{
    public function testLoginStillReadsOnlyTheCredentialField(): void
    {
        $model = file_get_contents(APPPATH . 'Models/UsuarioModel.php');
        $login = file_get_contents(APPPATH . 'Controllers/Login.php');
        self::assertIsString($model);
        self::assertIsString($login);
        self::assertStringContainsString("return \$this->where('email', \$email)->where('activo', 1)->first();", $model);
        self::assertStringContainsString('findByEmail', $login);
    }

    public function testTenantAdminPostsAndValidatesBothEmailsSeparately(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/TenantAdmin.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression("/'email'\\s*=>\\s*'required\\|valid_email\\|max_length\\[255\\]'/", $source);
        self::assertMatchesRegularExpression("/'email_notificaciones'\\s*=>\\s*'permit_empty\\|valid_email\\|max_length\\[255\\]'/", $source);
        self::assertMatchesRegularExpression("/'email'\\s*=>\\s*\\(string\\) \\\$this->request->getPost\\('email'\\)/", $source);
        self::assertMatchesRegularExpression("/'email_notificaciones'\\s*=>\\s*\\(string\\) \\\$this->request->getPost\\('email_notificaciones'\\)/", $source);
    }

    public function testTenantAdministrationWritesDistinctColumns(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Organization/CodeIgniterTenantAdministration.php');
        self::assertIsString($source);
        self::assertStringContainsString("'email'         => \$data['email']", $source);
        self::assertStringContainsString("'email_notificaciones' => \$data['email_notificaciones']", $source);
    }
}
```

- [ ] **Step 2: Ejecutar y confirmar que falla**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Organization/TenantUserNotificationEmailContractTest.php
```
Esperado: `FAIL`, las validaciones y el POST del campo nuevo no existen.

- [ ] **Step 3: Modificar el controlador**

En `createUser()`, agregar la regla después de `'email'`:

```php
            'email'                => 'required|valid_email|max_length[255]',
            'email_notificaciones' => 'permit_empty|valid_email|max_length[255]',
```

y agregar al array del payload:

```php
                    'email'    => (string) $this->request->getPost('email'),
                    'email_notificaciones' => (string) $this->request->getPost('email_notificaciones'),
```

En `updateUser()`, agregar la misma regla de validación y la misma clave del payload. Reemplazar el llamado y el mensaje (`:142-153`) por:

```php
            $loginEmailChanged = $this->service()->updateUser(
                $this->actor(),
                $userId,
                $data,
                (string) $this->request->getPost('motivo'),
            );
            if ($userId === $this->actor()->userId()) {
                session()->set('usuario_nombre', trim($data['nombre']));
                session()->set('usuario_email', mb_strtolower(trim($data['email'])));
            }

            $message = $loginEmailChanged
                ? 'Usuario actualizado correctamente. Si cambiaste el email, desde ahora debe ingresar con el nuevo correo.'
                : 'Usuario actualizado correctamente. El correo de resumen no modifica el usuario de ingreso.';

            return redirect()->to('/administracion/usuarios')->with('success', $message);
```

Nota de decisión: el segundo mensaje es válido aunque el operador haya cambiado solo teléfono o estado; por eso no afirma que el correo de resumen "cambió", sino que **no altera el ingreso**.

- [ ] **Step 4: Exponer el campo en el payload y en el modelo**

En `AdministrationPayload.php:174-178`:

```php
            'oldInput' => [
                'nombre' => old('nombre') ?? '', 'email' => old('email') ?? '', 'email_notificaciones' => old('email_notificaciones') ?? '', 'telefono' => old('telefono') ?? '', 'motivo' => old('motivo') ?? '',
                'roleIds' => array_map('intval', (array) old('roles', [])),
                'branchIds' => array_map('intval', (array) old('sucursales', [])),
            ],
```

En el `users` map (`:187`):

```php
                    'id' => (int) $row['id'], 'name' => $row['nombre'], 'email' => $row['email'], 'notificationEmail' => $row['email_notificaciones'] ?? '', 'phone' => $row['telefono'] ?? '',
```

En `UsuarioModel.php:14-16`:

```php
        'empresa_id', 'nombre', 'email', 'email_notificaciones', 'telefono', 'password_hash', 'es_superadmin', 'activo', 'ultimo_acceso',
```

`findByEmail()` queda exactamente como está.

- [ ] **Step 5: Modificar la pantalla Vue**

En el formulario de alta, renombrar la etiqueta `Email` a `Email de ingreso` (`:59`) e insertar después de ese `<label>` (`:62`):

```html
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">Correo para resúmenes</span>
          <input type="email" name="email_notificaciones" maxlength="255" :value="data.oldInput.email_notificaciones" autocomplete="off" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          <span class="mt-1 block text-xs text-ink-subtle">Si se deja vacío, los resúmenes llegan al email de ingreso. Cambiar este correo no modifica el ingreso.</span>
        </label>
```

En el formulario de edición, renombrar `Email` a `Email de ingreso` (`:158`) e insertar después de `:161`:

```html
                <label class="block">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Correo para resúmenes</span>
                  <input type="email" name="email_notificaciones" maxlength="255" :value="user.notificationEmail" autocomplete="off" class="min-h-11 w-full rounded-lg border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                  <span class="mt-1 block text-xs text-ink-subtle">Si se deja vacío, los resúmenes llegan al email de ingreso. Cambiar este correo no modifica el ingreso.</span>
                </label>
```

En el `<dl v-else>` (`:183-187`), agregar después de la entrada de Email:

```html
                <div><dt class="text-ink-subtle">Correo para resúmenes</dt><dd class="mt-1 break-all font-medium text-ink">{{ user.notificationEmail || 'Igual al de ingreso' }}</dd></div>
```

- [ ] **Step 6: Actualizar fixtures y tests del frontend**

En `frontend/tests/admin/fixtures.js:134`:

```js
  oldInput: { nombre: '', email: '', email_notificaciones: '', telefono: '', motivo: '', roleIds: [2], branchIds: [4] },
```

En el primer usuario (`:143-163`), después de `email`:
```js
      notificationEmail: 'resumen@transportes.test',
```
En el segundo usuario, después de `email`:
```js
      notificationEmail: '',
```

En `frontend/tests/admin/AdminPages.test.js`, agregar dentro de `it('preserva alta, cuenta, acceso y restablecimiento...')`:

```js
    expect(wrapper.get('form[action="/administracion/usuarios"] input[name="email_notificaciones"]').attributes('type')).toBe('email')
    expect(wrapper.get('form[action="/administracion/usuarios/2"] input[name="email_notificaciones"]').element.value).toBe('resumen@transportes.test')
    expect(wrapper.get('form[action="/administracion/usuarios/3"] input[name="email_notificaciones"]').element.value).toBe('')
    expect(wrapper.text()).toContain('Cambiar este correo no modifica el ingreso')
```

Además, en el test que pasa `oldInput` explícito (`:206`), agregar `email_notificaciones: ''` para que el fixture no deje el campo sin valor.

- [ ] **Step 7: Verificar en verde**

```bash
php vendor/bin/phpunit --no-coverage tests/unit/Application/Organization/TenantUserNotificationEmailContractTest.php
cd frontend && npx vitest run tests/admin/AdminPages.test.js
cd frontend && npm run build
```
Esperado: `OK (3 tests)`, Vitest en verde y build sin errores de encoding.

- [ ] **Step 8: Commit**

```bash
git add app/Controllers/TenantAdmin.php app/Presentation/AdministrationPayload.php app/Models/UsuarioModel.php frontend/src/pages/admin/UsersAdminPage.vue frontend/tests/admin/fixtures.js frontend/tests/admin/AdminPages.test.js tests/unit/Application/Organization/TenantUserNotificationEmailContractTest.php
git commit -m "feat(admin): correo para resumenes separado del ingreso en usuarios"
```

---

## Task 8: Contratos entre contextos, documentación y suite completa

**Files:**
- Modify: `docs/CONTRATOS_ENTRE_CONTEXTOS.md`
- Modify: `docs/superpowers/specs/2026-11-28-destinatario-resumen-diario-por-usuario-design.md:4`
- Create: `docs/BITACORA_2026-11-28_DESTINATARIO_RESUMEN_DIARIO.md`

- [ ] **Step 1: Registrar el contrato**

Agregar una fila a la tabla de contratos de `docs/CONTRATOS_ENTRE_CONTEXTOS.md`, junto a la de elegibilidad de destinatarios chofer:

```markdown
| Destinatario del resumen diario por usuario | `UserNotificationEmailPolicy` + `NotificationDeliveryQueue::schedule(..., ?string $deliveryEmail = null)` | `app/Application/Notifications/UserNotificationEmailPolicy.php` y `app/Application/Notifications/Port/NotificationDeliveryQueue.php` | Notifications → Organization (lectura de `usuarios.email_notificaciones`) y Presentation (ABM tenant) | **Implementado.** La política resuelve `email_notificaciones → email`; la cola guarda el efectivo en `notificacion_entregas.destinatario`. `usuarios.email` sigue siendo exclusivamente la credencial de ingreso. |

- [ ] **Step 2: Actualizar el estado del diseño**

En el spec aprobado, línea 4:
```markdown
**Estado:** Aprobado e implementado el 28/11/2026. Ejecución verificada localmente; pendiente de verificación en `fasa_189` y de carga de las direcciones confirmadas por el usuario.
```

- [ ] **Step 3: Escribir la bitácora**

Contenido mínimo de `docs/BITACORA_2026-11-28_DESTINATARIO_RESUMEN_DIARIO.md`:

```markdown
# Bitácora — Destinatario del resumen diario por usuario (28/11/2026)

## Problema
El resumen diario usaba `usuarios.email`, el mismo campo del login. Cambiarlo para arreglar la entrega cambiaba el usuario de acceso.

## Decisión
Campo opcional `usuarios.email_notificaciones`, resuelto por `UserNotificationEmailPolicy` con fallback a `usuarios.email`. Snapshot en `notificacion_entregas.destinatario`. Clave de idempotencia sin cambios. Informes gerenciales y alertas de empresa no se tocan.

## Contextos afectados
Notifications (política, resolver, cola y publicación) y Organización/Identidad (ABM de usuarios del tenant). Integrador: Arquitectura e integración.

## Archivos y migración
(listar los archivos del mapa y la migración `2026-11-28-100000_AddUserNotificationEmailSnapshot`)

## Evidencia
- Política pura, migración, resolver SQLite, cola SQLite, contrato de puerto, servicio de organización y contrato HTTP-source: resultado de PHPUnit.
- Frontend: resultado de `npm test` y `npm run build`.
- Staging `fasa_189`: contenedor, stack, commit y resultado del smoke HTTP. Registrar aquí la evidencia real; no afirmar éxito sin ella.

## Datos operativos
Pendiente de confirmación por escrito: correo de ingreso vigente y correo de resumen de Mariela y Carlos. No se cargan direcciones inventadas. Producción Ferozo no se toca sin aprobación explícita.
```

- [ ] **Step 4: Ejecutar toda la verificación local**

```bash
git status --short
php vendor/bin/phpunit --no-coverage
cd frontend && npm test && npm run build
```

Con WSL si Windows no tiene PHP:
```bash
wsl.exe bash -lc "cd /mnt/c/Programacion/mantenimiento && php vendor/bin/phpunit --no-coverage"
```

Esperado: suite PHPUnit en verde (la línea de resumen del estado base es ~1497 pruebas; debe aumentar, no disminuir), Vitest en verde y build correcto. `git status --short` debe mostrar **solo** los archivos tocados por este plan además de los cambios preexistentes del usuario.

- [ ] **Step 5: Commit**

```bash
git add docs/CONTRATOS_ENTRE_CONTEXTOS.md docs/superpowers/specs/2026-11-28-destinatario-resumen-diario-por-usuario-design.md docs/BITACORA_2026-11-28_DESTINATARIO_RESUMEN_DIARIO.md
git commit -m "docs(notificaciones): contrato y bitacora del destinatario de resumen"
```

---

## Task 9: Verificación en staging y datos operativos (sin producción)

**Files:** ninguno de aplicación; solo evidencia en la bitácora de Task 8.

Esta tarea se ejecuta **después** de aprobado el plan y con el stack de prueba identificado. No reemplaza la aprobación para tocar producción.

- [ ] **Step 1: Identificar el entorno antes de cualquier acción**

```bash
ssh fasa_189 'hostname && docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"'
```
Confirmar: host `fasa_189`, stack `mantenimiento-staging`, commit/checkout que contiene los commits de este plan, puerto propio, volúmenes preservados. Si no se puede identificar con seguridad, **detenerse e informar el bloqueo**. No usar `fasa_195`, PHP local, `php -S` ni SQLite como sustituto del entorno obligatorio.

- [ ] **Step 2: Aplicar la migración en staging**

Dentro del contenedor identificado, ejecutar la migración con el mecanismo ya documentado para el stack Docker/Coolify y verificar:

```sql
SHOW COLUMNS FROM usuarios LIKE 'email_notificaciones';
SHOW COLUMNS FROM notificacion_entregas LIKE 'destinatario';
SELECT id, email, email_notificaciones FROM usuarios WHERE id IN (...);
```
Esperado: ambas columnas `varchar(255) DEFAULT NULL` y `usuarios.email` sin cambios.

- [ ] **Step 3: Verificación HTTP real**

1. Abrir `Administración → Usuarios` en escritorio y móvil: los dos campos se ven con etiquetas distintas y la ayuda "Cambiar este correo no modifica el ingreso".
2. Editar un usuario de prueba: cargar solo `email_notificaciones`, guardar y confirmar el mensaje que dice que el ingreso no se modificó.
3. Verificar en la tabla que `email` quedó igual y `email_notificaciones` cambió, y que la auditoría `USUARIO_ACTUALIZADO` incluye ambos valores en `before`/`after`.
4. Ejecutar el ciclo de notificaciones del stack y verificar que las entregas nuevas tienen `destinatario` no nulo y que el correo llegó a esa dirección.
5. Ejecutar el ciclo dos veces: no hay correos duplicados (misma `clave_entrega`).

- [ ] **Step 4: Pedir los datos reales antes de cargarlos**

Preguntar por escrito al usuario, sin adivinar:
1. Correo de ingreso vigente de Mariela (debe seguir siendo el mismo).
2. Correo al que debe llegar su resumen diario.
3. Correo de ingreso vigente de Carlos (debe seguir siendo el mismo).
4. Correo al que debe llegar su resumen diario.

Solo con esos valores, actualizar `usuarios.email_notificaciones` por `usuario_id` + `empresa_id` mediante el ABM o una sentencia acotada. No usar el correo de ingreso como "nuevo destino". Registrar en la bitácora los ids y empresas, no datos ajenos.

- [ ] **Step 5: Cierre**

Actualizar la bitácora con contenedor, commit, resultados y fecha. **No aplicar nada en Ferozo** sin aprobación explícita; cuando se autorice, seguir `docs/DEPLOY_FEROZO.md` y validar con smoke HTTP.

---

## Definition of Done

- Política pura con fallback probada sin framework ni base.
- Migración reproducible, con `up()`/`down()` idempotentes y datos antiguos preservados.
- Resolver y cola usan la política; el destinatario efectivo queda en cada entrega; las entregas antiguas con `NULL` se resuelven en vivo.
- Clave de idempotencia y reintentos sin cambios; dos ejecuciones no duplican correos.
- ABM del tenant con "Email de ingreso" y "Correo para resúmenes"; `findByEmail()` intacto; auditoría incluye el campo nuevo.
- Contratos, spec y bitácora actualizados con evidencia real.
- PHPUnit, Vitest y build en verde.
- Verificación HTTP real en `fasa_189`, escritorio y móvil, sin tocar producción.
- Correos reales de Mariela y Carlos cargados solo después de confirmación por escrito.

## Riesgos y bordes

| Riesgo | Mitigación |
|---|---|
| Cambiar `email` creyendo que es el resumen | Dos campos con etiquetas distintas; contrato source que vigila `findByEmail()`; mensaje de éxito diferenciado. |
| Firma del puerto rompa dobles | Los 9 declarations se cambian en la misma tarea y hay test de contrato que los enumera. |
| Entregas antiguas se reenvíen al nuevo destino | `destinatario IS NULL` se resuelve en vivo solo al despachar; no se reescriben filas ni se reintentan entregas ya enviadas. |
| Cambio de destino no se refleje en entregas ya encoladas | Es el comportamiento acordado: la entrega es una foto. El cambio aplica a las entregas nuevas. Documentarlo en la bitácora. |
| Correo de resumen inválido heredado | La política cae al login y deja `log_message('notice', ...)` con `usuario_id`; no detiene el ciclo. |
| Schema SQLite distinto de MariaDB | Los tests replican `VARCHAR(255) NULL` en ambas columnas; revisar el tipo real en staging. |
| Producir un mensaje que afirme un cambio que no ocurrió | El mensaje sin cambio de login dice que el correo de resumen **no modifica** el ingreso, sin afirmar que cambió. |
| Tocar cambios ajenos del árbol | `git add` explícito por archivo en cada commit; nunca `git add -A`. |
