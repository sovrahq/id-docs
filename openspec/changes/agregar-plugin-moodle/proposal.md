## Why

Las instituciones que forman con Moodle —la Agencia Atenea en Bogotá es la primera, Bogotá Trabaja la siguiente— necesitan emitir una credencial verificable cuando un estudiante termina un curso, y hoy no existe ningún boilerplate de Sovra ID para un LMS: el único boilerplate vigente (`whatsapp-meta`) sólo verifica. Lo que hay en el ecosistema de Moodle es comercial y centralizado (Accredible, Sertifier, Open Badge Factory) o se queda en Open Badges 2.x. Atenea tiene checkpoint técnico el martes 13/10.

## What Changes

- Nuevo boilerplate `boilerplate/templates/moodle-local-plugin/`: un **plugin local de Moodle** (`local_sovracred`, PHP) compatible con Moodle 4.5 LTS y 5.x, que emite una credencial SD-JWT VC por curso completado contra la plataforma actual (`Authorization: Bearer sovra_sk_…`, OID4VCI).
- Al completarse un curso, el plugin registra el **derecho** del estudiante y le avisa con una notificación de Moodle. **La oferta OID4VCI se crea recién cuando el estudiante la pide** desde «Mis credenciales», porque vence a las 24 h y es de un solo uso.
- Un endpoint de webhook propio (firma HMAC sobre el cuerpo crudo, idempotente) y una **tarea programada de reconciliación** que hace al webhook opcional (el firewall de Atenea restringe por país).
- Un reporte para el administrador con pendientes, diagnóstico de conexión y reintento.
- Guía nueva `docs/guides/credentials/12-moodle.md` y la entrada en `boilerplate/README.md`.
- Se inicializa OpenSpec en el repo (`openspec/`), que no lo tenía.

Stack: el estándar de Sovra para aplicaciones con escala (Elixir/Phoenix) **no aplica** — un plugin de Moodle sólo puede escribirse en PHP, dentro del propio Moodle.

## Capabilities

### New Capabilities
- `moodle-credenciales-de-curso`: emisión de credenciales verificables de curso desde Moodle — derecho al completar, oferta a pedido del estudiante, confirmación por webhook o por reconciliación, administración y privacidad.

### Modified Capabilities
<!-- Ninguna: el repo no tenía specs. -->

## Impact

- Código nuevo, aislado en `boilerplate/templates/moodle-local-plugin/`; no toca los otros boilerplates ni la API.
- Consume tres endpoints existentes de la API: `POST /api/v1/issuer/credential-offer`, `GET /api/v1/issuer/credentials/{id}` y el webhook `credential.*` del workspace.
- Dependencias: ninguna fuera del núcleo de Moodle (`\core\http_client`, TCPDF para el QR). Sin CDN.
- Fuera de esta versión: ingresar a Moodle con la SuperApp (plugin de autenticación), credencial al registrarse y disparo por insignia — quedan como «próximos pasos» en el README.
