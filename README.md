# fluid_demo – Ergänzung für fluid

**fluid_demo ist eine Ergänzung für das REDAXO-6-AddOn [fluid](https://github.com/FriendsOfREDAXO/fluid)** und läuft
nur zusammen mit fluid. Es macht aus einer fluid-Website eine Demo zum Ausprobieren: Besucher bearbeiten die Seiten
ohne Anmeldung, und nichts davon wird gespeichert. Gedacht für Vorführungen, Schulungen und öffentliche Demo-Websites.

fluid selbst bringt keine Demo mit und öffnet ohne diese Ergänzung keinen Gastzugang. Wer die Demo nicht braucht,
installiert fluid_demo einfach nicht.

**Voraussetzungen:** REDAXO 6 (`redaxo/core` 6.x), [fluid](https://github.com/FriendsOfREDAXO/fluid) 1.x, PHP 8.5.

> REDAXO 6 und fluid sind noch in Entwicklung. Es kann sich noch einiges ändern.

## So erleben es Besucher

1. Der Knopf **„Demo starten“** steht oben im Hinweis-Streifen (falls eingeschaltet) und unten links.
2. Ein Klick öffnet eine kurze Erklärung. Mit **„Demo starten“** prüft der Browser in ein bis drei Sekunden, dass
   kein Bot klickt. Danach öffnet sich die Seite im Bearbeiten-Modus.
3. Texte direkt auf der Seite ändern, Blöcke in der Seitenleiste bearbeiten, einfügen, verschieben, verdoppeln,
   löschen, online/offline schalten: alles wie echt. **„Speichern“** und Neuladen funktionieren.
4. Die Änderungen bleiben **nur in diesem Browser**. Andere Besucher sehen davon nichts.
5. **„Zurücksetzen“** verwirft die Änderungen an der Seite, **„Demo beenden“** schließt die Demo.
6. Gibt es mehrere Kits (z. B. „Fluid“ und „NativeCSS“), schaltet **„Kit: …“** in der Leiste zum nächsten – nur in
   diesem Browser, die Website bleibt für alle anderen unverändert. Design-Vorschauen merkt sich die Demo je Kit.

Gesperrt sind: Veröffentlichen (zeigt nur einen Hinweis), Seiten anlegen, Seiteneinstellungen, Design,
Website-Einstellungen, Medienpool und Linkmap, Bildeditor, Seitenbaum, Verlauf, Postfach.

## Einrichten

1. Installieren, zum Beispiel über Composer: `composer require friendsofredaxo/fluid_demo`. Danach `php bin/console migrate`.
2. Im Backend unter **Benutzer** einen Benutzer **ohne Admin-Rechte** anlegen, z. B. „demo-besuch“, mit einer Rolle,
   die die gewünschten Kategorien und Blöcke erlaubt. Optionen wie „Blöcke verschieben“ und „online/offline“ schalten
   die passenden Knöpfe frei. Das Passwort wird nie gebraucht.
3. Auf der Website angemeldet: Menü ⋯ → **Website …** → Reiter **Demo**. Dort **„Demo einschalten“** anhaken und
   bei **„Rechte wie Benutzer“** den Benutzer wählen. Optional: Beschriftung des Knopfs und Hinweis in der Leiste.
4. Für öffentliche Demo-Websites: **„Hinweis „Demo-Website“ für alle Besucher zeigen“** mit eigenem Text und
   „Betrieben von“ (Name und Adresse). Der Streifen steht ganz oben auf jeder Seite, mit dem Knopf zum Start.

**Ausschalten:** Haken bei „Demo einschalten“ entfernen.

**Ganz entfernen:**
1. Zuerst die Demo ausschalten. Die Einstellungen im Reiter **Demo** gehören zu fluid und bleiben erhalten. So startet
   die Demo nicht von selbst wieder, falls das AddOn später erneut installiert wird.
2. `composer remove friendsofredaxo/fluid_demo`
3. `php bin/console migrate:cleanup` entfernt Schlüssel und Cache des AddOns.

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
- **Feste IDs neuer Blöcke:** Neue Blöcke bekommen in der Demo IDs ab 1 900 000 000, damit sie bei jedem Durchlauf
  gleich heißen. MariaDB und MySQL 8 heben dabei den Zähler der Tabelle an, und das Zurückrollen nimmt das nicht zurück.
  Deshalb setzt die Demo den Zähler danach zurück (`ALTER TABLE … AUTO_INCREMENT = 1`). Der Datenbank-Benutzer braucht
  dafür das Recht ALTER.
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
