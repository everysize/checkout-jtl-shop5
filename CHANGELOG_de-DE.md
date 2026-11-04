# Änderungsprotokoll

## 1.1.0

* Import-Meldung: Vor dem Schreiben einer Bestellung liest das Plugin den
  aktuellen Brutto-Shop-Preis und den Bestand jedes bestellten Artikels.
  Abweichungen stehen als Warnung im Protokoll (der Lauf bleibt erfolgreich),
  und everysize erfährt sie einmal je Position. Die Bestellung wird immer
  zum bei everysize bezahlten Preis importiert; eine gescheiterte Meldung
  stoppt den Import nicht und wird nicht wiederholt
* Erstattungen abgesichert (Regelfassung 2): Ein Erstattungsaufruf wartet bis zu 90 s auf PayPal. Der
  Aufruf wird vor dem Senden gespeichert (Schlüssel, Betrag, Zeit; einer je Bestellung). Nur eine
  nachweisliche Ablehnung (422 PAYPAL_REFUND_FAILED oder REFUND_AMOUNT_INVALID, 400
  ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS) beendet ihn; jede andere Antwort (keine Antwort, 3xx,
  401, 403, 404, 429, 5xx, ...) lässt ihn als „Ergebnis unklar“ offen, ein gescheiterter Token-Abruf vor
  dem POST ändert nichts. Solange er offen ist, wird nichts anderes erstattet, auch nicht automatisch.
  Das Wiederholen ist eine eigene Aktion nach 150 s und liest vorher die PayPal-Liste (eine passende
  unbekannte Erstattung schließt den Vorgang ohne Senden ab); „Als geklärt markieren“ beendet ihn ohne
  Senden. Das Formular sendet die gesehene Erstattungssumme mit (veralteter Tab: neu laden) und erstattet
  nur bei lesbarer Liste; die Rückfrage nennt den Betrag. Der Vorgang wird zuerst reserviert, dann wird die
  Liste gelesen und ihr Stand (die refundIds) im Vorgang gespeichert (eine alte fremde Erstattung wird nie
  übernommen); eine 2xx-Antwort ohne refundId ist unklar; eine abgelehnte Wiederholung lässt den Vorgang
  offen; „Als geklärt markieren“ ist ein bedingtes Löschen mit Protokolleintrag
* Eine von Hand eingetragene Sendungsnummer, ein Lieferschein allein, eine gescheiterte Versandmeldung
  (wird jetzt gemerkt und nachgeholt) und die Sendungsnummer der Bestellung gelten überall als Versand:
  Versandmeldung, Storno als Retoure (RETOUR) statt Händler-Storno und keine automatische Erstattung
* Wird eine bei everysize schon widerrufene Bestellung storniert (der Partner hat widerrufen), steht das einmal im Protokoll – dann gibt es keine automatische Erstattung. Wird ein abgelehntes Formular erneut gesendet, steht dort „abgelehnt, kein Geld geflossen“ statt „bereits ausgelöst“
* Automatische Erstattung nur, wenn nichts versendet wurde und bei everysize keine andere Erstattung steht
  (COMPLETED oder PENDING, auch nicht direkt in PayPal gemacht); eine nicht lesbare Liste überspringt den
  Lauf. Sie nutzt denselben gespeicherten Vorgang wie das Formular und sendet höchstens einmal (ein offener
  Vorgang wird vom Lauf nur noch mit der Liste abgeglichen, nie erneut gesendet; Einstellung und Bedingungen
  werden direkt vor dem Senden noch einmal geprüft); nur eine nachweisliche Ablehnung ist endgültig, alte
  offene Vorgänge stehen in jedem Lauf als Fehler im Protokoll (gerechnet ab dem Anlegen). Ein von der Abfrage
  ausgeschlossenes Storno steht einmal im Protokoll. Ausschalten und Einschalten setzt den Stichtag neu

## 1.0.2

* Erstattungen im Bestell-Detail mit Datum in der Zeitzone des Shops statt
  der von PayPal gelieferten (US-)Zeit
* Automatische Erstattung in den Rückmeldungen mit Erstattungs-ID und
  „voller Betrag“ statt der ID in der Spalte Grund/Betrag

## 1.0.1

* Bestell-Detail: Versand, Sendungsnummer und Storno als Normalfall über die
  JTL-Wawi (Hinweis oben); Sendungsnummer und Retoure von Hand stehen
  eingeklappt als „Korrektur, falls nötig“ am Ende
* Retoure von Hand bietet nur noch Positionen an, deren Retoure noch nicht
  gemeldet ist
* Beträge als „1.234,50 €“ statt „1234,50 EUR“; Abstände der Formularfelder

## 1.0.0

* Import von everysize-Bestellungen als JTL-Bestellung (Kunde, Adressen,
  Warenkorbpositionen mit ungerundetem Netto, Versandposition,
  Zahlungseingang, Bestand, Verknüpfung — in einer Transaktion)
* Bestellnummern aus dem JTL-Zähler (createOrderNo-Semantik), direkt an
  everysize gemeldet
* Rückmeldungen: Versand mit Sendungsnummer/Versanddienstleister,
  Sendungsnachmeldung, Storno `MERCHANT_DECLINE`, Retoure `RETOUR`,
  automatische volle Erstattung nach Storno vor dem Versand (abschaltbar),
  manuelle Erstattung über den Bestell-Tab (Idempotency-Key)
* Admin-Tabs: Einstellungen mit Verbindungstest, Bestellungen mit Detail
  (Positionen, Rückmeldungen, Sendungsnummer, Retoure, Erstattung) und
  Abgleich-Button, Protokoll mit Filter
* CLI `everysize_checkout:sync` (+ `order:import`/`order:export`),
  JTL-Cronjob mit 5-Minuten-Drossel, Webcron-Endpoint mit Token
* Steuerermittlung je Steuerklasse und Lieferland, Währungs- und
  Versandart-Prüfung vor dem Import, SKU-Matching mit Vaterschutz
