# Secure Notification Web Cron Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Proveer un endpoint HTTP POST autenticado para ejecutar el ciclo central de notificaciones en Ferozo sin PHP CLI, con timezone explícito, rate limiting, respuestas redacted y compatibilidad legacy controlada.

**Architecture:** El composition root expondrá un único \`notificationCycle\` que será usado por Spark y por el controlador HTTP. El controlador será un adaptador de entrada: método, token, rate limiting, status HTTP y redacción; el caso de uso conservará lock, idempotencia, collector, entregas, retries y trazabilidad. El reloj de infraestructura leerá \`Config\\\\App::$appTimezone\`.

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7.4, PHPUnit 10.5, \`Config\\\\Email\`, \`CodeIgniter\\\\Throttle\\\\Throttler\`, MariaDB/MySQL.

---

### Task 1: Persist design and establish baseline

**Files:**
- Create: \`docs/superpowers/specs/2026-08-31-secure-notification-web-cron-design.md\`
- Create: \`docs/superpowers/plans/2026-08-31-secure-notification-web-cron.md\`

- [ ] **Step 1: Confirm branch and baseline**

Run from the isolated worktree:

~~~
git status -sb
git log -1 --format='%H %s'
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications tests/unit/Infrastructure/Notifications
~~~

Expected: branch \`codex/issue-144-ferozo-web-cron-secure\`, commit \`93e3c9169455934a29fc28f7a4dd6faf2d579af9\`, and \`48 tests, 0 failures\` before feature changes.

- [ ] **Step 2: Commit design artifacts**

~~~
git add docs/superpowers/specs/2026-08-31-secure-notification-web-cron-design.md docs/superpowers/plans/2026-08-31-secure-notification-web-cron.md
git commit -m "docs(notificaciones): diseñar cron HTTP seguro para Ferozo"
~~~

### Task 2: Make application timezone explicit

**Files:**
- Modify: \`app/Infrastructure/Notifications/SystemNotificationClock.php\`
- Test: \`tests/unit/Infrastructure/Notifications/SystemNotificationClockTest.php\`

- [ ] **Step 1: Write the failing timezone test**

Add a test that constructs \`SystemNotificationClock('America/Argentina/Buenos_Aires')\`, calls \`now()\`, and asserts that the timezone name is exactly \`America/Argentina/Buenos_Aires\`.

- [ ] **Step 2: Run the test and verify RED**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/SystemNotificationClockTest.php
~~~

Expected: failure because the current class has no constructor and returns the server default timezone.

- [ ] **Step 3: Implement the minimal clock change**

Add an optional timezone string constructor argument. When omitted, resolve \`config(App::class)->appTimezone\`, validate it with \`DateTimeZone\`, and return \`new DateTimeImmutable('now', $timezone)\`.

- [ ] **Step 4: Run the timezone test and related schedule tests**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/SystemNotificationClockTest.php tests/unit/Application/Notifications/NotificationUseCasesTest.php
~~~

Expected: all selected tests pass.

### Task 3: Centralize notification-cycle composition

**Files:**
- Modify: \`app/Config/Services.php\`
- Modify: \`app/Commands/DispatchNotifications.php\`
- Modify: \`app/Controllers/NotificationCron.php\`
- Modify: \`tests/unit/Infrastructure/Notifications/NotificationWebCronContractTest.php\`

- [ ] **Step 1: Add the failing composition contract**

Assert that \`Services\` exposes \`notificationCycle\`, and that Spark and the controller obtain it with \`service('notificationCycle')\` instead of constructing \`RunNotificationCycle\` separately.

- [ ] **Step 2: Run the contract test and verify RED**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationWebCronContractTest.php
~~~

Expected: failure because the service and both entry points currently wire dependencies independently.

- [ ] **Step 3: Implement the shared composition root service**

Add \`Services::notificationCycle(bool $getShared = true): RunNotificationCycle\`, wiring \`detectOverduePlansAutomatically\`, \`operationalNotificationCollector\`, \`notificationDispatch\`, and \`notificationClock\`. Replace duplicate constructors in Spark and HTTP with \`service('notificationCycle')\`.

- [ ] **Step 4: Run the contract and notification suite**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications tests/unit/Application/Notifications
~~~

Expected: all existing and new composition tests pass.

### Task 4: Add configurable cron rate limiting

**Files:**
- Create: \`app/Application/Notifications/Port/NotificationCronRateLimiter.php\`
- Create: \`app/Infrastructure/Notifications/CodeIgniterNotificationCronRateLimiter.php\`
- Modify: \`app/Config/Services.php\`
- Modify: \`tests/unit/Infrastructure/Notifications/NotificationCronRateLimiterTest.php\`
- Modify: \`.env.example\`

- [ ] **Step 1: Write the failing adapter contract**

Test that the adapter delegates to CodeIgniter \`Throttler\` with a hashed IP key, configured maximum attempts, and configured window, without including the raw IP in the key.

- [ ] **Step 2: Run the test and verify RED**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationCronRateLimiterTest.php
~~~

Expected: failure because the port and adapter do not exist.

- [ ] **Step 3: Implement the adapter and service**

Use \`Throttler::check('notification_cron_' . hash('sha256', $ip), max(1, env('alerts.webCronRateLimit', 6)), max(1, env('alerts.webCronRateWindowSeconds', 60)))\`. Expose \`retryAfterSeconds()\` from \`getTokenTime()\`.

- [ ] **Step 4: Run the adapter test**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationCronRateLimiterTest.php
~~~

Expected: PASS with no raw IP in the generated key.

### Task 5: Replace the new HTTP entry point while preserving legacy

**Files:**
- Modify: \`app/Controllers/NotificationCron.php\`
- Modify: \`app/Config/Routes.php\`
- Modify: \`app/Config/Filters.php\`
- Modify: \`tests/unit/Infrastructure/Notifications/NotificationWebCronContractTest.php\`
- Create: \`tests/unit/Infrastructure/Notifications/NotificationCronResponseContractTest.php\`

- [ ] **Step 1: Write failing route/security contracts**

Cover the following source/HTTP contract: POST \`/internal/cron/notifications/dispatch\`; GET on that same path maps to \`405\` with \`Allow: POST\`; \`X-Cron-Token\` and \`Authorization: Bearer\` are accepted; missing/incorrect tokens return \`401\`; disabled endpoint remains \`404\`; invalid attempts are rate limited to \`429\`; the legacy GET route remains present and is explicitly deprecated.

- [ ] **Step 2: Run the tests and verify RED**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationWebCronContractTest.php tests/unit/Infrastructure/Notifications/NotificationCronResponseContractTest.php
~~~

Expected: failure because the route is currently GET-only with a path token and the response returns the full cycle.

- [ ] **Step 3: Implement the controller adapter**

Add a POST \`dispatch()\` action that checks the rate limiter, reads \`X-Cron-Token\` or a Bearer token from headers, compares with \`hash_equals\`, calls \`service('notificationCycle')->execute(null, ...)\`, and maps the result to counts only. Map lock contention to \`409\`, rate limiting to \`429\`, authentication failure to \`401\`, and unexpected failures to generic \`500\` without exception messages. Keep \`legacyDispatch(string $token)\` for the old route, mark it deprecated in comments/docs/logging, and do not add new uses of it.

- [ ] **Step 4: Configure routing and CSRF deliberately**

Register POST for the new endpoint, GET/other methods to \`methodNotAllowed()\` where required, keep the legacy GET route, and exempt only the new token-authenticated path from global CSRF because it has no browser session. Set \`Allow: POST\` on \`405\` responses.

- [ ] **Step 5: Run the HTTP contract tests**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Infrastructure/Notifications/NotificationWebCronContractTest.php tests/unit/Infrastructure/Notifications/NotificationCronResponseContractTest.php
~~~

Expected: PASS; response assertions must prove no token, email, SMTP value, payload, exception message, or stack trace is present.

### Task 6: Document configuration and operational boundaries

**Files:**
- Modify: \`.env.example\`
- Modify: \`docs/notificaciones-cron-ferozo.md\`
- Modify: \`docs/notificaciones-email-cron.md\`
- Modify: \`docs/notificaciones-qa.md\`
- Modify: \`docs/DEPLOY_COOLIFY.md\`

- [ ] **Step 1: Document exact environment keys**

Document \`email.fromEmail\`, \`email.fromName\`, \`email.protocol\`, \`email.SMTPHost\`, \`email.SMTPUser\`, \`email.SMTPPass\`, \`email.SMTPPort\`, \`email.SMTPCrypto\`, \`alerts.webCronEnabled\`, \`alerts.webCronToken\`, \`alerts.webCronRateLimit\`, \`alerts.webCronRateWindowSeconds\`, \`alerts.lockTimeoutSeconds\`, and \`app.appTimezone\`, always as placeholders.

- [ ] **Step 2: Document the new and legacy URLs**

Document the new HTTPS POST path and \`X-Cron-Token\` header, \`07:00\` application-timezone behavior, \`401/405/409/429/500\` troubleshooting, token rotation, temporary disablement, and the legacy GET route as deprecated pending Ferozo panel verification. Explicitly state that POST/header support is not yet verified in Ferozo.

- [ ] **Step 3: Check documentation for forbidden production commands**

~~~
rg -n "production|Ferozo|cron/notificaciones|internal/cron|php spark notifications:dispatch" docs/notificaciones-cron-ferozo.md docs/notificaciones-email-cron.md docs/notificaciones-qa.md docs/DEPLOY_COOLIFY.md
~~~

Expected: production instructions use the new HTTP endpoint and do not require \`php spark\`, SSH, shell, Composer, MySQL CLI, or Bash.

### Task 7: Full verification and staging gate

**Files:**
- No production files or \`.env\` values.

- [ ] **Step 1: Run targeted and full PHP tests**

~~~
php vendor/bin/phpunit --no-coverage tests/unit/Application/Notifications tests/unit/Infrastructure/Notifications
php vendor/bin/phpunit --no-coverage
~~~

Expected: exit code 0 and zero failures. Any pre-existing failure must be reported separately and block completion claims.

- [ ] **Step 2: Check syntax and diff hygiene**

~~~
git diff --check
git diff --stat
git status -sb
~~~

Expected: no whitespace errors, no \`.env\`, tokens, SMTP credentials, backups, or generated artifacts in the diff.

- [ ] **Step 3: Commit implementation and documentation**

~~~
git add app .env.example docs tests
git commit -m "feat(notificaciones): agregar cron HTTP seguro para Ferozo"
~~~

- [ ] **Step 4: Push the branch**

~~~
git push -u origin codex/issue-144-ferozo-web-cron-secure
~~~

- [ ] **Step 5: Validate staging only after code verification**

Inspect SSH target \`fasa_195\`, confirm Docker stack \`/home/ferreteria/mantenimiento-staging\`, containers, checkout and port \`8090\`; preserve volumes and \`.env\`. Run the new endpoint with a staging token and SMTP test configuration only if the user has configured them. Do not touch Ferozo production, do not alter production \`.env\`, and do not remove the legacy endpoint before scheduler verification.
