/**
 * Comparar el teléfono de la credencial contra el número de WhatsApp.
 *
 * Es más sutil de lo que parece. Los dos lados escriben el mismo número
 * distinto:
 *
 *   wa_id de Meta        5491122223333    E.164 sin '+', y en Argentina con el
 *                                         9 de celular intercalado
 *   claim de la cred.    +54 11 2222-3333 como lo cargó el emisor: con '+', con
 *                                         espacios, con guiones, a veces sin
 *                                         código de país
 *
 * Comparar strings crudos da falso negativo casi siempre. Comparamos los
 * últimos dígitos, que es la parte que no cambia entre formatos.
 */

/**
 * Cuántos dígitos finales comparamos.
 *
 * 8 cubre el número local de la mayoría de los países sin arrastrar código de
 * país ni prefijos de celular. Es una heurística: dos personas distintas pueden
 * compartir los últimos 8 dígitos, pero la otra además tendría que estar
 * presentando una credencial válida en esa misma sesión.
 *
 * Si operás en un solo país y conocés el formato exacto con que el emisor carga
 * el claim, normalizá a E.164 y compará completo — es más estricto.
 */
const SIGNIFICANT_DIGITS = 8;

/** Deja solo los dígitos: '+54 11 2222-3333' → '541122223333'. */
export function digitsOnly(input: string): string {
  return input.replace(/\D/g, "");
}

/**
 * `true` si los dos valores parecen el mismo número.
 *
 * Falla cerrado: si alguno no tiene suficientes dígitos para comparar, devuelve
 * `false` en vez de dar por buena una coincidencia que no pudo verificar.
 */
export function samePhone(a: string, b: string): boolean {
  const left = digitsOnly(a);
  const right = digitsOnly(b);

  if (left.length < SIGNIFICANT_DIGITS || right.length < SIGNIFICANT_DIGITS) {
    return false;
  }

  return left.slice(-SIGNIFICANT_DIGITS) === right.slice(-SIGNIFICANT_DIGITS);
}

/** Enmascara un número para poder mostrarlo o loguearlo: '••••3333'. */
export function mask(input: string): string {
  const digits = digitsOnly(input);
  return digits.length <= 4 ? "••••" : `••••${digits.slice(-4)}`;
}
