// @ts-nocheck
import type Mithril from 'mithril';

/**
 * Resolve a human label for a points-ledger reason code.
 *
 * Reason codes (e.g. "post.posted", "tip.in") are stable machine identifiers;
 * the ledger UI should not show them raw. The labels live under
 * `ygpynet-point-system.lib.reasons.*` — the `lib.` segment is REQUIRED:
 * Flarum only packs IDs with a `.forum.` / `.admin.` / `.lib.` segment into
 * the frontend locale JS (AddTranslations::forFrontend), so a top-level
 * `reasons.*` section never reaches the browser and every code shows raw.
 * Locale keys are flat snake_case while codes use dots — the lookup
 * normalizes dots to underscores and falls back to the raw code for an
 * unknown reason, so the UI never breaks on an unlabelled code.
 *
 * `app` is passed in (never imported) so this shared util works from both the
 * forum and admin bundles — the same convention used by `pointsLabel()`.
 */
export function reasonLabel(code: string, app: any): string {
  if (!app || !app.translator) return code;
  const flat = String(code).replace(/\./g, '_');
  for (const key of ['ygpynet-point-system.lib.reasons.' + flat, 'ygpynet-point-system.reasons.' + flat]) {
    const tr = app.translator.trans(key, {}, true) as string;
    if (tr && tr !== key) return tr;
  }
  return code;
}
