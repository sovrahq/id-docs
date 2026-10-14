## Context

Ver `proposal.md` — Why. Restricciones que dan forma a la solución:

- La oferta OID4VCI **vence a las 24 h y es de un solo uso** (guía 03). Generarla al completar el curso haría vencer la mayoría.
- `\core\event\course_completed` lo emite el cron de completitud: puede llegar minutos después, y su `userid` es quien lo dispara; el estudiante es `relateduserid`.
- Un **4xx** al webhook hace que Sovra descarte el evento sin reintentar (guía 05). El timeout es de 5 s.
- El firewall de Atenea restringe por país: no se puede asumir que los avisos de Sovra lleguen.
- Moodle 4.5 LTS hoy, 5.x en evaluación. PHP 8.1–8.3 en 4.5; 8.2–8.4 en 5.x.

## Goals / Non-Goals

**Goals:** un plugin que Atenea instala, configura en una pantalla y prueba en su instancia de pruebas; código chico y legible como referencia para otros clientes con Moodle.

**Non-Goals:** ingresar con la SuperApp (autenticación OID4VP), credencial al registrarse, disparo por insignia, revocación desde Moodle, multi-workspace por categoría de curso.

## Decisions

**Plugin `local` y no servicio externo.** Vive adentro, conoce al estudiante logueado y le muestra la credencial en su plataforma. Un servicio externo vía Web Services obligaría a recorrer inscriptos y a mandar QR por correo. Alternativa descartada: `mod_` (actividad) — obligaría a agregarla curso por curso.

**PHP, no el stack SaaS.** Un plugin de Moodle sólo puede ser PHP; Elixir/Phoenix no aplica.

**Una tabla de derechos, única por (usuario, curso).** Estados: `available` → `offered` → `issued` / `suspended` / `revoked`. El vencimiento de una oferta no es un estado propio: vuelve a `available`. Se guardan `credentialid`, `offeruri`, `offerexpires`, `holderdid`, `statustime` (para descartar eventos desordenados: guarda **sólo horas del reloj de Sovra**, nunca el de Moodle), `timechecked` (última consulta a la API, ordena la reconciliación) y `lasterror`. Una segunda tabla guarda los `id` de entregas de webhook ya procesadas, con índice único: idempotencia que sobrevive reinicios y varias instancias.

**No se guarda el SD-JWT firmado.** La guía 03 dice que es la única copia; pero para este caso Moodle no lo necesita (no reimprime ni verifica), y guardarlo sería conservar datos personales sin uso. Quien lo necesite lo agrega en `classes/webhook.php`.

**Una sola oferta canjeable por derecho.** Antes de crear otra se consulta a Sovra el estado de la anterior: si se canjeó (aviso perdido) se marca emitida; si sigue pendiente se reutiliza, **aunque le falten minutos**; sólo si Sovra la informa vencida se crea otra. Alternativa descartada: reemplazarla con un margen de 10 minutos — la anterior seguiría canjeable unos minutos con su `credential_id` ya pisado, y si el estudiante la escaneaba, ni el webhook ni la reconciliación podrían asociarla al derecho. Un lock por derecho (`\core\lock`) impide que dos pestañas o un doble clic creen dos ofertas.

**Claims en `classes/claims.php`.** Qué datos lleva la credencial es una decisión de producto y privacidad: pasa por revisión, no se edita en caliente. Mapeo de prueba para `bogota-formacion`: `holderName` ← nombre completo; `documento` ← campo configurable (`idnumber` o un campo propio del perfil); `programa` ← categoría del curso; `curso` ← nombre del curso; `horas` y `nivel` ← campos personalizados del curso (`sovra_horas`, `sovra_nivel`); `entidadFormadora` ← nombre del sitio; `fechaFinalizacion` ← fecha de completitud; `codigoVerificacion` ← código aleatorio generado al registrar el derecho. Los nombres definitivos se acuerdan con Atenea.

**HTTP con `\core\http_client` obtenido del contenedor `\core\di`.** Respeta hosts y puertos bloqueados y permite inyectar un cliente simulado en las pruebas. Existe desde 4.4, así que cubre 4.5 y 5.x.

**QR con TCPDF del núcleo** (`TCPDF2DBarcode`, SVG). Sin dependencias ni CDN.

**Webhook: `NO_MOODLE_COOKIES`, cuerpo crudo de `php://input`, `hash_equals`.** 401 sólo por firma inválida; 503 si el secreto no está configurado (Sovra reintenta hasta 8 veces, tiempo para terminar la puesta en marcha); **500 ante cualquier falla interna** —Moodle convierte una excepción sin atrapar en un 404, que Sovra descartaría—; 200 para todo lo demás. Marcar la entrega y aplicarla van en una misma transacción. El trabajo es una o dos escrituras a la base: se hace en línea, dentro de los 5 s.

**Reconciliación cada 5 minutos**: hasta 100 ofertas abiertas por corrida, y hasta 50 credenciales emitidas o suspendidas que no se consultan hace más de un día (para reflejar revocaciones sin webhook). Siempre las consultadas hace más tiempo primero. La misma tarea borra los ids de entregas del webhook de más de 30 días.

**Vencimiento calculado por el plugin.** La API sólo informa `expired` para ofertas sin canjear; una credencial emitida sigue `issued` después de su `expires_at`. El plugin guarda esa fecha (`credexpires`) y muestra «vencida» al leerla, sin un estado propio ni una tarea que lo escriba: así no hay nada que pueda quedar desincronizado.

**Detección de dispositivo con `core_useragent::get_device_type()`** sólo para el orden de los elementos: ambos (QR y botón) están siempre presentes.

## Risks / Trade-offs

- [El webhook nunca llega por el firewall] → la reconciliación confirma en ≤ 5 min mientras haya hasta 100 ofertas abiertas; con N ofertas abiertas, en ~5·⌈N/100⌉ min, porque cada corrida toma un lote de 100. Para Atenea no es un límite real (28.800 consultas por día contra ofertas que viven 24 h). La página del estudiante también consulta el estado al reabrirla.
- [El nombre del campo del documento no se conoce] → configurable; si queda vacío, el error aparece en el reporte antes de que un estudiante choque con él.
- [Atenea migra a Azure en ~1 mes] → el plugin no guarda nada del host; sólo cambia la URL del webhook en la consola.
- [Moodle 5.1 movió el código a `public/`] → los plugins se instalan igual en `public/local/`; documentado en el README.
- [Volumen: 600 cursos, 32.800 usuarios] → el observer sólo escribe una fila; la API tiene límite de 120 ofertas por minuto, holgado para ofertas a pedido. La página del estudiante consulta como mucho una vez cada 10 s por oferta abierta.
- [Datos editables por el estudiante] → nombre, apellido y el campo del documento tienen que estar bloqueados en el sitio; la configuración lo advierte. El plugin no lo puede imponer.
- [Una oferta por vencer no se reemplaza] → si al estudiante le quedan pocos minutos y no llega a escanear, espera a que venza y pide otra. Es el costo de no tener nunca dos ofertas canjeables.

## Migration Plan

Instalación nueva: copiar `local/sovracred`, completar la actualización desde la administración, configurar. Desinstalar borra las tablas. No hay datos previos que migrar; un script CLI permite registrar derechos de cursos completados antes de instalar.

## Open Questions

- Nombre del campo de perfil donde Atenea guarda el número de documento (se configura, no cambia el código).
- Nombres definitivos de los claims y si `horas` viaja como número o texto (se acuerdan con Atenea; cambia sólo `claims.php`).
