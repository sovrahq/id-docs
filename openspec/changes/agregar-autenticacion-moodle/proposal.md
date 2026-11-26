## Why

La Academia Atenea (Bogotá) quiere que una persona entre a su Moodle con la **Credencial de Identidad de la SuperApp de Bogotá**, sin usuario ni contraseña. Ningún plugin existente sirve: OAuth 2 del núcleo une cuentas por correo (la credencial no siempre lo trae), `auth_kipmi` está atado a su propio verificador y `moodle-auth_eudiwallet` es alfa y une por `issuer`+`sub`. La identidad que Atenea ya tiene cargada de cada persona es su **número de documento**.

## What Changes

- Nuevo boilerplate `templates/moodle-auth-plugin/sovra/`: un **plugin de autenticación de Moodle** (`auth_sovra`, PHP) compatible con Moodle 4.5 LTS y 5.1, que agrega «Ingresar con la SuperApp» a la pantalla de ingreso.
- El plugin abre una verificación OID4VP en la API de Sovra que pide sólo el número de documento, y el tipo si se compara (más `iss` y `vct`, que no son datos personales y permiten controlar el emisor y el tipo), muestra QR + enlace `openid4vp://`, y espera el resultado por webhook `presentation.verified` o consultando la verificación.
- Con el resultado, busca **la** cuenta por el campo de documento configurado (`idnumber` o un campo de perfil) y la deja entrar. **Sin cuenta, no la crea**: muestra «No tienes cuenta en <plataforma>» y lleva al registro (URL configurable).
- Controles: lista de **emisores permitidos** (falla cerrada), exactamente una cuenta coincidente, documento normalizado, tipo de documento opcional con tabla de equivalencias, sesión atada al navegador, de un solo uso y con vencimiento, y registro sin datos personales en claro.
- Guía de instalación en español para Atenea (`README.md` del plugin) y entrada en `templates/README.md`.

Stack: el estándar de Sovra para aplicaciones con escala (Elixir/Phoenix) **no aplica** — un plugin de Moodle sólo puede escribirse en PHP, dentro del propio Moodle.

## Capabilities

### New Capabilities
- `moodle-ingreso-con-credencial`: ingreso a Moodle presentando una credencial verificable desde la billetera, identificando a la persona por su documento.

### Modified Capabilities
<!-- Ninguna. -->

## Impact

- Código nuevo, aislado en `templates/moodle-auth-plugin/`; no toca `local_sovracred` ni la API.
- Consume endpoints existentes: `POST /api/v1/verifier/verifications`, `GET /api/v1/verifier/verifications/{id}` y el webhook `presentation.*` del workspace.
- Dependencias: ninguna fuera del núcleo de Moodle (`\core\http_client`, TCPDF para el QR).
- Fuera de esta versión: crear la cuenta desde la credencial (decisión de Lucas, 19/11: no se crea), vincular la credencial a una cuenta ya abierta, y el flujo en el mismo dispositivo sin QR más allá del enlace `openid4vp://`.
