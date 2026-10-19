# Changelog

## 1.0.0 – in Arbeit

- Demo für fluid als eigenes AddOn:
  - Besucher starten die Demo per Knopf nach einer Bot-Prüfung ohne Dienste Dritter (Rechenaufgabe, unsichtbares
    Feld, Mindestzeit, Begrenzung je IP).
  - Sie bearbeiten die Website wie echt, gespeichert wird nichts: Jede Anfrage läuft in einer Transaktion, die
    zurückgerollt wird.
  - Gäste sehen nur Öffentliches: Live-Fassung, ohne Offline-Blöcke, ohne interne Einstellungen.
  - Hervorgegangen aus einem Entwurf, der zunächst direkt in fluid steckte.
- Kit ausprobieren: Knopf „Kit: …“ in der Leiste (ab zwei Kits) wechselt das Kit nur im eigenen Browser (Cookie
  `fluid_demo_kit`, ausgewertet über `FLUID_KIT` nur in einer gestarteten Demo); Design-Vorschau je Kit.
- Angepasst an REDAXO-Core `cf598c1`: kein `uninstall()` mehr. Entfernen per `composer remove` und
  `console migrate:cleanup`, die Demo vorher ausschalten (README).
