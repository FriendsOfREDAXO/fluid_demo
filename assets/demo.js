/*
 * fluid_demo – Editor-Seite der Demo (geladen über cfg.scripts, vor dem Editor von fluid).
 *
 * Die schreibenden Aktionen des Editors merkt sich der Browser im localStorage (je Seite und Sprache, cfg.demo.key) und
 * schickt sie bei jeder Anfrage mit (Ereignis fluid:api-request → Feld fluid_demo). Der Server spielt sie in einer
 * Transaktion ab und rollt danach zurück. Beim Laden holt dieses Skript die Seite mit den Änderungen (POST fluid_demo an
 * dieselbe Adresse) und tauscht <main> und die Einstellungen des Editors aus, bevor der Editor startet (window.fluidEditorWait).
 * Knöpfe der Hinweis-Leiste: „Zurücksetzen“ (fluid-demo:reset), „Demo beenden“ (fluid-demo:end).
 */
(() => {
  'use strict';

  const cfgEl = document.getElementById('blocks-editor-config');
  let cfg = null;
  try { cfg = JSON.parse(cfgEl?.textContent || 'null'); } catch { cfg = null; }
  if (!cfg?.demo) return;

  const KEY = cfg.demo.key;
  const MAX = cfg.demo.maxOps || 60;
  const WRITES = new Set(['save', 'add', 'move', 'reorder', 'col_move', 'paste', 'duplicate', 'status', 'delete']);
  const load = () => {
    try { const v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch { return []; }
  };
  let ops = load();
  const store = () => {
    try { localStorage.setItem(KEY, JSON.stringify(ops)); } catch { /* voll oder gesperrt: dann nur bis zum Neuladen */ }
  };
  const payload = () => JSON.stringify(ops);
  const ui = () => document.getElementById('blocks-ui')?.shadowRoot;
  const showReset = () => ui()?.querySelector('[data-notice-event="fluid-demo:reset"]')?.toggleAttribute('hidden', !ops.length);

  // Design-Vorschau: übernommene Werte gelten in diesem Browser für die ganze Demo (alle Seiten), je Kit getrennt
  const DESIGN_KEY = 'fluid-demo:design' + (cfg.demo.kit && cfg.demo.kit !== 'fluid' ? ':' + cfg.demo.kit : '');
  try { window.fluidDesignInitial = JSON.parse(localStorage.getItem(DESIGN_KEY) || 'null'); } catch { window.fluidDesignInitial = null; }
  document.addEventListener('fluid:design-keep', (e) => {
    try { localStorage.setItem(DESIGN_KEY, JSON.stringify(e.detail.values)); } catch { /* */ }
  });

  // Kit ausprobieren: zum nächsten Kit wechseln (Cookie für den Server, gilt nur in diesem Browser und nur in der Demo)
  const KIT_COOKIE = cfg.demo.kitCookie || 'fluid_demo_kit';
  const setKitCookie = (value, maxAge) => {
    document.cookie = `${KIT_COOKIE}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  };
  document.addEventListener('fluid-demo:kit', () => {
    const kits = Array.isArray(cfg.demo.kits) ? cfg.demo.kits : [];
    if (kits.length < 2) return;
    const next = kits[(kits.indexOf(cfg.demo.kit) + 1) % kits.length];
    setKitCookie(next, 7200);
    location.reload();
  });

  // Auswahl aus Medienpool/Linkmap gibt es in der Demo nicht (form.js zeigt diesen Hinweis)
  window.fluidPickerOff = 'In der Demo lassen sich keine Bilder und Links aus dem Backend auswählen – auf einer echten Website öffnet sich hier der Medienpool bzw. die Seitenauswahl.';

  // FormData → verschachteltes Objekt wie PHPs $_POST („f[1][titel]“ → {f: {1: {titel: …}}}, „a[]“ → Liste)
  function nest(fd) {
    const out = {};
    for (const [name, value] of fd.entries()) {
      if (typeof value !== 'string' || name === '_csrf_token' || name === 'fluid_demo') continue;
      const keys = name.replace(/\]/g, '').split('[');
      let cur = out;
      keys.forEach((k, i) => {
        const last = i === keys.length - 1;
        if (k === '') {
          if (!Array.isArray(cur.__list)) cur.__list = [];
          if (last) cur.__list.push(value);
          return;
        }
        if (last) cur[k] = value;
        else cur = (cur[k] && typeof cur[k] === 'object') ? cur[k] : (cur[k] = {});
      });
    }
    const fix = (o) => {
      if (!o || typeof o !== 'object') return o;
      if (Array.isArray(o.__list)) return o.__list;
      for (const k of Object.keys(o)) o[k] = fix(o[k]);
      return o;
    };
    return fix(out);
  }

  // Anfragen des Editors: gemerkte Änderungen mitschicken, erfolgreiche schreibende Aktion merken
  document.addEventListener('fluid:api-request', (e) => e.detail.fd.append('fluid_demo', payload()));
  document.addEventListener('fluid:api-done', (e) => {
    const { action, fd } = e.detail;
    if (!WRITES.has(action)) return;
    if (ops.length >= MAX) {
      document.dispatchEvent(new CustomEvent('fluid:notice', { detail: `Die Demo merkt sich höchstens ${MAX} Änderungen je Seite – „Zurücksetzen“ fängt neu an.` }));
      return;
    }
    ops.push({ a: action, p: nest(fd) });
    store();
    showReset();
  });

  // Hinweis-Leiste
  document.addEventListener('fluid-demo:reset', async () => {
    const ask = window.FluidEditor?.ask;
    const ok = ask ? await ask({ title: 'Demo zurücksetzen?', body: 'Alle Ihre Änderungen an dieser Seite werden verworfen – sie waren ohnehin nur in diesem Browser gespeichert.', ok: 'Zurücksetzen', danger: true }) : true;
    if (!ok) return;
    window.FluidEditor?.discard?.();
    ops = [];
    try { localStorage.removeItem(KEY); } catch { /* */ }
    location.reload();
  });
  document.addEventListener('fluid-demo:end', async () => {
    const ask = window.FluidEditor?.ask;
    if (ask && !(await ask({ title: 'Demo beenden?', body: 'Ihre Änderungen werden verworfen, die Seite erscheint wieder wie für alle Besucher.', ok: 'Beenden' }))) return;
    window.FluidEditor?.discard?.();
    try { Object.keys(localStorage).filter((k) => k.startsWith('fluid-demo:')).forEach((k) => localStorage.removeItem(k)); } catch { /* */ }
    setKitCookie('', 0);
    try { await fetch(cfg.demo.gate + 'end', { method: 'POST', credentials: 'same-origin' }); } catch { /* */ }
    location.href = String(cfg.viewUrl).replace(/&amp;/g, '&');
  });

  // Seite mit den gemerkten Änderungen holen und <main> austauschen, bevor der Editor startet
  const ready = (async () => {
    if (!ops.length) return;
    const root = document.documentElement;
    const style = document.createElement('style');
    style.textContent = 'html.fluid-demo-loading main{opacity:.4;transition:opacity .15s}';
    document.head.append(style);
    root.classList.add('fluid-demo-loading');
    try {
      const fd = new FormData();
      fd.append('fluid_demo', payload());
      const res = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
      if (!res.ok) return;
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const fresh = doc.querySelector('main');
      const current = document.querySelector('main');
      if (fresh && current) current.replaceWith(document.adoptNode(fresh));
      const freshCfg = doc.getElementById('blocks-editor-config');
      if (freshCfg && cfgEl) cfgEl.textContent = freshCfg.textContent;
      document.querySelectorAll('main [data-block]').forEach((block) => document.dispatchEvent(new CustomEvent('fluid:rendered', { detail: { block } })));
    } catch { /* dann ohne Änderungen */ } finally {
      root.classList.remove('fluid-demo-loading');
    }
  })();
  (window.fluidEditorWait = window.fluidEditorWait || []).push(ready);
  ready.then(() => setTimeout(showReset, 0));
})();
