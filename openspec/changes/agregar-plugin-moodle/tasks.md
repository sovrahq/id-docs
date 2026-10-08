## 1. Base del plugin

- [ ] 1.1 Esqueleto `local_sovracred` (`version.php`, `lang/en`, `lang/es`, `settings.php`, `db/install.xml`, `db/access.php`, `db/messages.php`, `db/events.php`, `db/tasks.php`) y verificar que se instala en Moodle 4.5 sin errores
- [ ] 1.2 Cliente de la API (`classes/api_client.php`) con `Bearer` y `\core\http_client`; verificar con pruebas que la autenticación y el manejo de errores son los de la guía

## 2. Derecho y oferta

- [ ] 2.1 Observer de `course_completed` (derecho único + notificación) y verificar los escenarios de «Registro del derecho» con pruebas automáticas
- [ ] 2.2 `classes/claims.php` y verificar los escenarios de «Los claims de la credencial»
- [ ] 2.3 Servicio de oferta a pedido (crear, reutilizar, consultar antes de recrear, errores) y verificar los escenarios de «La oferta se crea cuando el estudiante la pide»
- [ ] 2.4 Página «Mis credenciales» con QR del núcleo y botón de enlace directo; verificar en el navegador en ancho de computadora y de teléfono

## 3. Confirmación

- [ ] 3.1 `webhook.php` + procesador idempotente; verificar los escenarios de «Recepción de avisos» con pruebas y con entregas firmadas por `curl`
- [ ] 3.2 Tarea de reconciliación; verificar los escenarios de «Reconciliación» con pruebas y contra el workspace de test

## 4. Administración y privacidad

- [ ] 4.1 Reporte `index.php` (estados, prueba de conexión, consultar estado, reenviar aviso); verificar el acceso restringido y la prueba de conexión contra test
- [ ] 4.2 Proveedor de privacidad; verificar exportación y borrado con pruebas
- [ ] 4.3 Script CLI para registrar derechos de cursos completados antes de instalar

## 5. Documentación y punta a punta

- [ ] 5.1 README del boilerplate (instalación, configuración, Moodle local, prueba con billetera, próximos pasos), guía `11-moodle.md` y entrada en `boilerplate/README.md`
- [ ] 5.2 Recorrido punta a punta en Moodle 4.5 local contra el workspace de test: curso completado → notificación → página → oferta → QR y enlace → webhook simulado con firma buena y mala → reconciliación
- [ ] 5.3 `openspec validate agregar-plugin-moodle` en verde y PR abierto contra `main`
