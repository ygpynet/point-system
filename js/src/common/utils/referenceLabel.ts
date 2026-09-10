// @ts-nocheck
import type Mithril from 'mithril';

/**
 * Human label for a ledger row's reference ("post #66", "讨论 #45", …).
 *
 * `referenceType` slugs are machine identifiers; the UI localizes them via
 * `ygpynet-point-system.lib.ref.*` (the `lib.` segment ships the keys to BOTH
 * frontends — see reasonLabel). Legacy rows may carry a full PHP class name
 * ("Flarum\\Post\\Post"); those normalize to the short snake_case slug
 * ("post") before the lookup, matching what the migration fixed server-side.
 *
 * Unknown types degrade to the raw slug so the UI never breaks on a new
 * reference kind written by a third-party extension.
 */
export function referenceLabel(type: string | null | undefined, id: any, app: any): string {
  if (!type) return '—';

  const raw = String(type);
  const slug = raw.includes('\\')
    ? raw
        .split('\\')
        .pop()!
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .toLowerCase()
    : raw;

  const key = 'ygpynet-point-system.lib.ref.' + slug;
  let label = slug;
  if (app?.translator) {
    const tr = app.translator.trans(key, {}, true);
    if (tr && tr !== key) label = tr;
  }

  return id != null && id !== '' ? `${label} #${id}` : label;
}
