/*
 * fluid_demo – Knopf „Demo starten“ für Besucher (nur wenn die Demo eingeschaltet und noch nicht gestartet ist).
 *
 * Klick → Dialog mit Erklärung → „Demo starten“: Aufgabe vom Server holen (Gate::challenge), im Browser lösen
 * (SHA-256 mit führenden Null-Bits, ≈ 1–3 s, kein Dienst Dritter), Lösung senden (Gate::start). Danach setzt der Server das
 * Demo-Cookie und die Seite öffnet sich im Bearbeiten-Modus. Honigtopf-Feld und Mindestzeit erschweren Bots den Einlass.
 * Oberfläche im Shadow DOM (keine Kollision mit dem CSS der Website).
 */
(() => {
  'use strict';

  const me = document.currentScript;
  const gate = me?.dataset.gate;
  if (!gate || !window.fetch || !window.HTMLDialogElement) return;
  const label = me.dataset.label || 'Demo starten';

  // ------------------------------------------------------------------ SHA-256 für einen Block (Nachricht < 56 Bytes)
  const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2]);
  const W = new Uint32Array(64);
  const B = new Uint8Array(64);
  /** erstes 32-Bit-Wort des Hashs von str (ASCII) – reicht für bis zu 32 führende Null-Bits */
  function first32(str) {
    B.fill(0);
    for (let i = 0; i < str.length; i++) B[i] = str.charCodeAt(i);
    B[str.length] = 0x80;
    const bits = str.length * 8;
    B[62] = (bits >>> 8) & 0xff; B[63] = bits & 0xff;
    for (let i = 0; i < 16; i++) W[i] = (B[i * 4] << 24) | (B[i * 4 + 1] << 16) | (B[i * 4 + 2] << 8) | B[i * 4 + 3];
    for (let i = 16; i < 64; i++) {
      const a = W[i - 15], b = W[i - 2];
      const s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
      const s1 = ((b >>> 17) | (b << 15)) ^ ((b >>> 19) | (b << 13)) ^ (b >>> 10);
      W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0;
    }
    let a = 0x6a09e667, b = 0xbb67ae85, c = 0x3c6ef372, d = 0xa54ff53a, e = 0x510e527f, f = 0x9b05688c, g = 0x1f83d9ab, h = 0x5be0cd19;
    for (let i = 0; i < 64; i++) {
      const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
      const t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[i] + W[i]) | 0;
      const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
      const t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    return (0x6a09e667 + a) >>> 0;
  }
  /** Zahl suchen, deren Hash mit `bits` Null-Bits beginnt – in Häppchen, damit die Seite bedienbar bleibt */
  function solve(salt, bits, onProgress) {
    const limit = 2 ** (32 - bits);
    return new Promise((resolve) => {
      let n = 0;
      const step = () => {
        const end = n + 25000;
        for (; n < end; n++) {
          if (first32(salt + n) < limit) { resolve(String(n)); return; }
        }
        onProgress?.(n);
        setTimeout(step, 0);
      };
      step();
    });
  }

  // ------------------------------------------------------------------ Oberfläche
  const host = document.createElement('div');
  host.id = 'fluid-demo-start';
  const root = host.attachShadow({ mode: 'open' });
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  root.innerHTML = `
    <style>
      :host { all: initial; }
      .fab { position: fixed; left: max(16px, env(safe-area-inset-left)); bottom: max(16px, env(safe-area-inset-bottom)); z-index: 2147483000;
        display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 18px; border: 0; border-radius: 999px;
        background: #1d2230; color: #fff; font: 600 15px/1 system-ui, sans-serif; box-shadow: 0 6px 20px rgb(0 0 0 / .25); cursor: pointer; }
      .fab:hover { background: #2b3245; }
      .fab:focus-visible, button:focus-visible { outline: 3px solid #8a96ff; outline-offset: 3px; }
      .dot { width: 9px; height: 9px; border-radius: 50%; background: #ffb020; }
      dialog { width: min(440px, calc(100vw - 32px)); padding: 24px; border: 0; border-radius: 14px; background: #fff; color: #1d2230;
        font: 15px/1.55 system-ui, sans-serif; box-shadow: 0 20px 60px rgb(0 0 0 / .3); }
      dialog::backdrop { background: rgb(15 18 28 / .55); }
      h2 { margin: 0 0 8px; font-size: 20px; line-height: 1.25; }
      p { margin: 0 0 12px; color: #3d4455; }
      ul { margin: 0 0 16px; padding-left: 20px; color: #3d4455; }
      .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
      .row { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-top: 8px; }
      .btn { min-height: 44px; padding: 0 18px; border: 1px solid #c9ced8; border-radius: 10px; background: #fff; color: #1d2230; font: 600 15px/1 system-ui, sans-serif; cursor: pointer; }
      .btn--primary { border-color: #1d2230; background: #1d2230; color: #fff; }
      .btn[disabled] { opacity: .6; cursor: progress; }
      .status { min-height: 1.5em; margin: 8px 0 0; color: #3d4455; font-size: 14px; }
      .status.is-error { color: #a4161a; font-weight: 600; }
      @media (prefers-reduced-motion: no-preference) { .fab { transition: background-color .15s; } }
    </style>
    <button type="button" class="fab" aria-haspopup="dialog"><span class="dot" aria-hidden="true"></span>${esc(label)}</button>
    <dialog aria-labelledby="fd-title" aria-describedby="fd-text">
      <form method="dialog">
        <h2 id="fd-title">Website selbst bearbeiten</h2>
        <div id="fd-text">
          <p>Probieren Sie aus, wie Redakteure mit fluid arbeiten: Texte direkt auf der Seite ändern, Blöcke einfügen, verschieben und löschen.</p>
          <ul><li>Gespeichert wird nichts – Ihre Änderungen bleiben nur in diesem Browser.</li>
          <li>Vor dem Start prüft Ihr Browser kurz, dass hier kein Bot klickt. Dafür werden keine Daten an Dritte gesendet.</li></ul>
        </div>
        <label class="hp" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        <p class="status" role="status" aria-live="polite"></p>
        <div class="row">
          <button type="submit" class="btn" value="cancel" formnovalidate>Abbrechen</button>
          <button type="button" class="btn btn--primary" data-go>${esc(label)}</button>
        </div>
      </form>
    </dialog>`;
  const fab = root.querySelector('.fab'), dlg = root.querySelector('dialog'), go = root.querySelector('[data-go]'), status = root.querySelector('.status');
  let opened = 0;
  const say = (t, err = false) => { status.textContent = t; status.classList.toggle('is-error', err); };
  fab.addEventListener('click', () => { opened = Date.now(); say(''); go.disabled = false; dlg.showModal(); go.focus(); });
  go.addEventListener('click', async () => {
    go.disabled = true;
    say('Einen Moment – Ihr Browser prüft …');
    try {
      const ch = await (await fetch(gate + 'challenge', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
      if (!ch.ok) throw new Error(ch.error || 'Die Demo ist gerade nicht verfügbar.');
      const nonce = await solve(ch.salt, ch.difficulty);
      const fd = new FormData();
      for (const k of ['salt', 'exp', 'difficulty', 'sig']) fd.append(k, ch[k]);
      fd.append('nonce', nonce);
      fd.append('website', root.querySelector('[name=website]').value);
      // Mindestzeit seit dem Öffnen (Gate::MIN_MS) abwarten – Menschen merken davon nichts, Bots kostet es Zeit
      const wait = 1600 - (Date.now() - opened);
      if (wait > 0) await new Promise((r) => setTimeout(r, wait));
      fd.append('t', String(Date.now() - opened));
      const res = await (await fetch(gate + 'start', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
      if (!res.ok) throw new Error(res.error || 'Die Prüfung hat nicht geklappt.');
      say('Geschafft – die Demo startet …');
      const u = new URL(location.href);
      u.searchParams.set('fluid_edit', '1');
      location.href = u.href;
    } catch (e) {
      say(e.message || 'Das hat nicht geklappt. Bitte noch einmal versuchen.', true);
      go.disabled = false;
    }
  });
  const mount = () => document.body.append(host);
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
})();
