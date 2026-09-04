# Prueba local del QR público desde celular

Este procedimiento valida el flujo anónimo de kilometraje sin tocar staging ni producción.

## 1. Actualizar y migrar

```powershell
git checkout main
git pull origin main
php spark migrate
```

## 2. Obtener la IPv4 de la PC

```powershell
ipconfig
```

Ejemplo: `192.168.1.35`.

## 3. Configurar la URL local

En `.env`:

```ini
app.baseURL = 'http://192.168.1.35:8080/'
```

Usar la IPv4 real de la PC.

## 4. Levantar el servidor accesible en LAN

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-local-server.ps1 -Host 0.0.0.0 -Port 8080
```

`-Host` es alias de `-BindHost`.

La PC y el teléfono deben estar en la misma red Wi-Fi/LAN. Si Windows Firewall solicita autorización, permitir PHP para redes privadas.

## 5. Generar el QR

1. Entrar al sistema desde la PC.
2. Ir a **Mantenimiento > Equipos**.
3. Abrir **QR** del equipo a probar.
4. Si el equipo todavía no tiene token público, un usuario con permiso de edición lo genera automáticamente al abrir el QR.
5. Escanear el código con el celular.

La URL contenida debe tener esta forma:

```text
http://192.168.1.35:8080/mantenimiento/publico/equipo/<token>/lectura
```

No debe contener el ID interno del equipo.

## 6. Smoke obligatorio

- [ ] QR válido abre sin login.
- [ ] Se ve sólo código/patente, última lectura y formulario.
- [ ] Lectura válida se registra.
- [ ] En la ficha aparece con origen `QR_ANONIMO`.
- [ ] Lectura menor a la actual es rechazada.
- [ ] Doble envío no genera dos lecturas.
- [ ] Un salto mayor a 5.000 km o 500 h exige confirmación.
- [ ] Regenerar QR invalida el QR anterior.
- [ ] El QR nuevo funciona.
- [ ] Alterar manualmente el token no revela otro equipo.
- [ ] El resto de la aplicación sigue exigiendo autenticación.

## 7. Importante

Esta prueba debe realizarse únicamente contra una base local/de prueba.

No usar:

- staging `fasa_189`;
- producción Ferozo;
- datos productivos reales.

El smoke físico con celular se considera aprobado sólo después de completar el checklist anterior.
