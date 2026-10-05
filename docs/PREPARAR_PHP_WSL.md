# Preparar PHP en WSL para verificar el PR #435

Esta maquina no tiene PHP, Docker ni MariaDB, asi que `php -l` y PHPUnit no se
pueden correr todavia. El CI de GitHub los corre, pero conviene verificar antes
de hacer push para no dejar un PR rojo.

El shell del agente no es interactivo, asi que `sudo` pide la contrasena y no
puede completarse solo. Ejecuta vos el bloque de abajo en una terminal WSL
(Ubuntu 26.04) y luego decime "listo".

## 1. Instalar PHP CLI y extensiones

    sudo apt-get update
    sudo apt-get install -y php-cli php-mbstring php-intl php-mysql php-sqlite3 php-xml php-curl

## 2. Confirmar

    php -v
    php -m | grep -E 'mbstring|intl|mysqli|pdo_sqlite|sqlite3|curl|dom'

## 3. Correr la verificacion del repo

Desde Windows, en `C:\Programacion\mantenimiento`:

    wsl.exe -e sh -lc "cd /mnt/c/Programacion/mantenimiento && php spark --help >/dev/null 2>&1; vendor/bin/phpunit --testsuite App --filter ManagementReport"

Y la suite completa:

    wsl.exe -e sh -lc "cd /mnt/c/Programacion/mantenimiento && vendor/bin/phpunit --no-coverage"

## Nota sobre la base de datos

Los tests de este repo son en su mayoria contractuales (leen archivos) y de
dominio puro: no requieren MariaDB. Los que si usan base declaran su propio
entorno y se saltan si no hay conexion. Por eso `vendor/bin/phpunit` deberia
poder correr completo sin levantar una base.
