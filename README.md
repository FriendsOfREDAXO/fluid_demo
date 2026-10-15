# fluid_demo – Demo für fluid

Ein AddOn für [fluid](https://github.com/FriendsOfREDAXO/fluid) (REDAXO 6). Besucher probieren das Bearbeiten auf der
Website aus, ohne Anmeldung und ohne dass etwas gespeichert wird. Gedacht für Vorführungen und Demo-Websites.

fluid selbst enthält keine Demo. Wer sie will, installiert dieses AddOn zusätzlich. Wer es nicht installiert oder die
Demo nicht einschaltet, hat keinen Gastzugang.

## So erleben es Besucher

1. Unten links steht der Knopf **„Demo starten“**.
2. Ein Klick öffnet eine kurze Erklärung. Mit **„Demo starten“** prüft der Browser in ein bis drei Sekunden, dass
   kein Bot klickt. Danach öffnet sich die Seite im Bearbeiten-Modus.
3. Texte direkt auf der Seite ändern, Blöcke in der Seitenleiste bearbeiten, einfügen, verschieben, verdoppeln,
   löschen, online/offline schalten: alles wie echt. **„Speichern“** und Neuladen funktionieren.
4. Die Änderungen bleiben **nur in diesem Browser**. Andere Besucher sehen davon nichts.
5. **„Zurücksetzen“** verwirft die Änderungen an der Seite, **„Demo beenden“** schließt die Demo.

Gesperrt sind: Veröffentlichen (zeigt nur einen Hinweis), Seiten anlegen, Seiteneinstellungen, Design,
Website-Einstellungen, Medienpool und Linkmap, Bildeditor, Seitenbaum, Verlauf, Postfach.

## Einrichten

1. Installieren, zum Beispiel über Composer: `composer require friendsofredaxo/fluid_demo`. Danach `php bin/console migrate`.
2. Im Backend unter **Benutzer** einen Benutzer **ohne Admin-Rechte** anlegen, z. B. „demo-besuch“, mit einer Rolle,
   die die gewünschten Kategorien und Blöcke erlaubt. Optionen wie „Blöcke verschieben“ und „online/offline“ schalten
   die passenden Knöpfe frei. Das Passwort wird nie gebraucht.
3. Auf der Website angemeldet: Menü ⋯ → **Website …** → Reiter **Demo**. Dort **„Demo einschalten“** anhaken und
   bei **„Rechte wie Benutzer“** den Benutzer wählen. Optional: Beschriftung des Knopfs und Hinweis in der Leiste.

**Ausschalten:** Haken bei „Demo einschalten“ entfernen. Ganz entfernen: `php bin/console addon:uninstall fluid_demo`
(löscht auch die Einstellungen), danach `composer remove friendsofredaxo/fluid_demo`.

Wer im Backend angemeldet ist, sieht keinen Demo-Knopf und arbeitet ganz normal.

## Sicherheit

- **Nichts wird gespeichert.** Jede Anfrage eines Gastes läuft in einer Datenbank-Transaktion, die am Ende
  zurückgerollt wird. Die Änderungen schickt der Browser jedes Mal mit (localStorage, je Seite, höchstens 60).
- **Nur Öffentliches.**
  - Gäste sehen die veröffentlichte Fassung, nie Entwürfe der Redaktion.
  - Offline-Blöcke und Spalten von Offline-Layouts sind entfernt.
  - Interne Einstellungen werden geleert, bevor Gäste etwas zu sehen bekommen: Empfänger, Betreff und
    Bestätigungstexte von Formularen, Platzhalter, Filterwerte, interne Feldnamen.
- **Nur Block-Aktionen dieser Seite.** Alles andere lehnt fluid für Gäste ab. Der Demo-Benutzer hat nie
  Admin-Rechte.
- **Einlass nur per Knopf mit Bot-Prüfung**, ohne Dienste Dritter und ohne Cookies Dritter:
  - Der Browser löst eine Rechenaufgabe (SHA-256 mit führenden Null-Bits). Die Aufgabe ist signiert und gilt fünf
    Minuten und nur einmal.
  - Dazu kommen ein unsichtbares Feld, das nur Bots ausfüllen, und eine Mindestzeit zwischen Öffnen und Start.
  - Danach gibt es ein eigenes, signiertes Cookie (HttpOnly, SameSite=Lax, zwei Stunden). Ohne dieses Cookie gibt es
    keine Leiste und keine Demo-Aktionen.
- **Begrenzung je IP:** 30 Aufgaben und 10 Starts je Stunde, 120 Demo-Anfragen je 5 Minuten.

Ein automatisierter Browser, der JavaScript ausführt, kann die Prüfung bestehen. Er bekommt dann nur, was jeder
Besucher sieht, und die Begrenzungen bremsen ihn.

## Für Entwickler

Das AddOn nutzt den Gastzugang von fluid (`FLUID_GUEST_USER`, `FLUID_API_ACTION`, `FLUID_EDITOR_CONFIG`, siehe
[Entwickler-Referenz von fluid](https://github.com/FriendsOfREDAXO/fluid/blob/main/docs/entwickler.md#gastzugang-für-addons)).

| Datei | Aufgabe |
|---|---|
| `src/Demo.php` | Einstellungen, Gastzugang, Ausgangsstand (`prepare`), Transaktion mit Wiederholung der Aktionen, feste IDs neuer Blöcke |
| `src/Gate.php` | Knopf-Einlass: Aufgabe, Prüfung, Cookie, Begrenzung je IP (`?fluid_demo_gate=challenge\|start\|end`) |
| `assets/demo-start.js` | Knopf und Dialog „Demo starten“, Lösung der Aufgabe im Browser |
| `assets/demo.js` | im Editor: Aktionen merken und mitschicken, Seite mit Änderungen laden, Zurücksetzen, Beenden |

Eigene Listener auf `ART_CONTENT_UPDATED` usw. laufen in der Demo mit. Wer Dateien schreibt oder externe Dienste
aufruft, prüft `\FriendsOfRedaxo\Fluid\Editing::isGuest()` und tut dann nichts.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
