// @ts-nocheck
import type Mithril from 'mithril';

/**
 * Resolve a human label for a points-ledger reason code.
 *
 * Reason codes (e.g. "post.posted", "tip.in") are stable machine identifiers;
 * the ledger UI should not show them raw. We translate via the
 * `ygpynet-point-system.reasons.*` locale keys — falling back to the raw code
 * when an extension introduces a reason we haven't labelled yet, so the UI never
 * breaks on an unknown code.
 *
 * `app` is passed in (never imported) so this shared util works from both the
 * forum and admin bundles — the same convention used by `pointsLabel()`.
 */
export function reasonLabel(code: string, app: any): string {
  if (!app || !app.translator) return code;
  const key = `ygpynet-point-system.reasons.${code}`;
  const tr = app.translator.trans(key, {}, true) as string;
  return tr === key ? code : tr;
}
