# Testlijst Mollie Recurring (1.1.0)

Gedraaid op 4 nov 2026 met `tests/recurring/run.sh`: nagebootste WHMCS (SQLite, stubs volgens de WHMCS-docs) en een nagebootste Mollie-API achter de echte mollie-api-php 3.15.0. Uitkomst: **25/25 gevallen OK**, 129 controles.

Wat deze lijst niet bewijst staat onderaan: dat moet op een echte test-WHMCS met een Mollie-testsleutel.

## 01 Locale blijft zoals in c252558 (Belgische IBAN)

Uitkomst: OK

- [x] dutch + BE = nl_BE
- [x] dutch + NL = nl_NL
- [x] english + US = en_US
- [x] english + FR valt terug op en_BE
- [x] french + US valt terug op fr_BE
- [x] onbekende taal = en_BE

## 02 Kaart toevoegen zonder factuur: mandaat, betaalmethode, 0,01 terug

Uitkomst: OK

- [x] eerste betaling met sequenceType=first
- [x] bedrag 0.01
- [x] methode creditcard
- [x] locale nl_BE
- [x] redirect- en webhook-URL
- [x] start.php breekt uit de iframe naar Mollie
- [x] webhook 200
- [x] 1 kaart-betaalmethode in WHMCS
- [x] remote token = customer:mandaat
- [x] laatste 4, type MasterCard, vervaldatum MMYY
- [x] bevestigingsbedrag 1 keer teruggestort
- [x] dubbele webhooks: nog steeds 1 betaalmethode
- [x] dubbele webhooks: niet opnieuw teruggestort
- [x] 1 Mollie-customer
- [x] tweede betaalmethode hergebruikt de Mollie-customer

## 03 SEPA via Bancontact tijdens betaling factuur: factuur betaald, bankbetaalmethode

Uitkomst: OK

- [x] eerste betaling = saldo factuur
- [x] methodes bancontact, ideal, belfius, kbc
- [x] locale en_NL
- [x] factuur Paid
- [x] 1 boeking met Mollie-id
- [x] bankbetaalmethode, rekening eindigend op 7034
- [x] niets teruggestort
- [x] dubbele webhook: geen tweede boeking

## 04 Geknoeid formulier en verlopen link worden geweigerd

Uitkomst: OK

- [x] ander bedrag: 403
- [x] andere klant: 403
- [x] link ouder dan 2 uur: 403
- [x] onbekende gateway: 400
- [x] geen enkele betaling bij Mollie
- [x] factuur van andere klant: geweigerd
- [x] nog altijd geen betaling

## 05 Klant breekt checkout af: geen betaalmethode, factuur blijft open

Uitkomst: OK

- [x] geen betaalmethode
- [x] factuur Unpaid
- [x] return-pagina: terug naar factuur met paymentfailed

## 06 Return-pagina: wachten, daarna door naar factuur of betaalmethodes

Uitkomst: OK

- [x] nog open: wachtpagina die herlaadt
- [x] foute handtekening: 403
- [x] betaald: door naar betaalmethodes
- [x] return-pagina bewaart de betaalmethode ook zonder webhook
- [x] webhook daarna: geen tweede betaalmethode

## 07 Cron int met kaart: meteen betaald, late webhook boekt niet dubbel

Uitkomst: OK

- [x] capture success
- [x] factuur Paid
- [x] recurring betaling op het mandaat, juiste bedrag
- [x] webhooks na capture: 1 boeking
- [x] cron slaat betaalde factuur over
- [x] 1 inning bij Mollie

## 08 Cron int met SEPA: pending, retries innen niet dubbel, webhook paid zet betaald

Uitkomst: OK

- [x] capture pending
- [x] factuur Payment Pending, niet Paid
- [x] nog geen boeking
- [x] cron de dag nadien slaat Payment Pending over
- [x] retry op Unpaid: weer pending
- [x] retry maakte geen tweede inning
- [x] na webhook paid: factuur Paid
- [x] dubbele webhook: 1 boeking
- [x] geen mislukt-mail

## 09 SEPA-inning mislukt (AM04): factuur terug open, nieuwe betaalmethode nodig

Uitkomst: OK

- [x] factuur terug Unpaid (WS Invordering neemt over)
- [x] betaalmethode verwijderd
- [x] mandaat ingetrokken bij Mollie
- [x] klant krijgt Direct Debit Payment Failed
- [x] dubbele webhook: geen tweede mail
- [x] cron int niet meer zonder nieuwe betaalmethode
- [x] na nieuwe betaalmethode: factuur Paid

## 10 Mislukte inning met optie uit: mandaat blijft, cron probeert opnieuw

Uitkomst: OK

- [x] factuur Unpaid, betaalmethode blijft
- [x] nieuwe poging maakt 1 nieuwe inning
- [x] AC04 (rekening dicht): betaalmethode vervalt toch

## 11 Terugboeking na betaling: betaling teruggedraaid, factuur Unpaid, mandaat weg

Uitkomst: OK

- [x] eerst Paid
- [x] terugboeking geboekt via paymentReversed
- [x] factuur Unpaid (niet Collections)
- [x] betaalmethode en mandaat weg
- [x] klant gemaild
- [x] dubbele webhook: niets extra
- [x] cron int niet opnieuw op het oude mandaat

## 12 Terugboeking pas gezien door de dagelijkse nacontrole

Uitkomst: OK

- [x] gemiste webhook paid: nacontrole zet factuur Paid
- [x] gemiste webhook terugboeking: nacontrole zet factuur Unpaid
- [x] nacontrole opnieuw: niets dubbel

## 13 Mandaat ingetrokken bij de bank: geen inning, nieuwe betaalmethode nodig

Uitkomst: OK

- [x] capture declined
- [x] geen inning aangemaakt
- [x] betaalmethode verwijderd
- [x] factuur Unpaid
- [x] klant krijgt de mislukt-mail (WHMCS failedEmail)

## 14 Verlopen kaart: geen inning, mandaat ingetrokken, nieuwe betaalmethode nodig

Uitkomst: OK

- [x] capture declined met reden kaart vervallen
- [x] geen inning aangemaakt
- [x] betaalmethode en mandaat weg
- [x] klant krijgt Credit Card Payment Failed

## 15 Klant betaalt zelf terwijl SEPA loopt: inning geannuleerd

Uitkomst: OK

- [x] lopende inning geannuleerd bij Mollie
- [x] factuur 1 keer betaald
- [x] betaalmethode blijft (geen mislukking van de klant)
- [x] geen mislukt-mail

## 16 Klant betaalt zelf, inning niet meer te annuleren en komt binnen: teruggestort

Uitkomst: OK

- [x] geen tweede boeking
- [x] inning volledig teruggestort
- [x] dubbele webhook: 1 terugstorting

## 17 Time-out na aanmaken bij Mollie: volgende poging int niet dubbel

Uitkomst: OK

- [x] eerste poging geeft geen success (pending)
- [x] 1 inning bij Mollie (time-out na aanmaken)
- [x] tweede poging vindt de bestaande inning terug
- [x] nog altijd 1 inning

## 18 Mollie onbereikbaar tijdens webhook: 500 zodat Mollie opnieuw probeert

Uitkomst: OK

- [x] eerste webhook 500
- [x] factuur nog Payment Pending
- [x] tweede webhook 200 en Paid
- [x] onbekend id: 404 (Mollie probeert later opnieuw)
- [x] ongeldig id: 400

## 19 Betaalmethode verwijderen in klantenzone trekt mandaat in

Uitkomst: OK

- [x] storeremote delete: success
- [x] mandaat ingetrokken bij Mollie
- [x] mandaat op revoked in onze tabel
- [x] nog eens verwijderen (al weg bij Mollie): success
- [x] capture op ingetrokken mandaat: declined, geen inning

## 20 Ongeldig token en terugbetalen vanuit WHMCS

Uitkomst: OK

- [x] ongeldig token: declined
- [x] gedeeltelijke terugbetaling bij Mollie

## 21 Kaart en SEPA gebruiken het juiste WHMCS-type betaalmethode

Uitkomst: OK

- [x] kaart via createCardPayMethod, SEPA via createBankPayMethod
- [x] 2 mandaten bewaard

## 22 Teksten NL en EN volledig

Uitkomst: OK

- [x] 12 teksten voor automatisch betalen
- [x] elke NL-tekst bestaat ook in het Engels
- [x] onbekende taal valt terug op Engels
- [x] geen gedachtestreepjes

## 23 Kaartinning meteen geweigerd: declined, 1 mail, nieuwe betaalmethode nodig

Uitkomst: OK

- [x] capture declined
- [x] factuur Unpaid, geen boeking
- [x] betaalmethode en mandaat weg (optie staat aan)
- [x] 1 mislukt-mail (WHMCS), webhook stuurt er geen tweede

## 24 Cron tijdens open checkout voor dezelfde factuur: wacht, int niet

Uitkomst: OK

- [x] capture pending, geen inning
- [x] factuur blijft Unpaid (niet Payment Pending)
- [x] na verlopen checkout: gewone inning

## 25 Na een nieuw mandaat: standaardgateway en open facturen op automatisch

Uitkomst: OK

- [x] voor het mandaat: cron laat de factuur met Mollie Checkout liggen
- [x] standaardgateway klant = domiciliëring
- [x] open factuur omgezet naar domiciliëring
- [x] cron int de oude open factuur
- [x] nieuwe factuur krijgt meteen domiciliëring

## Nog te bevestigen op een echte test-WHMCS

De nabootsing volgt de WHMCS-documentatie, maar deze punten hangen af van gedrag binnen WHMCS zelf:

- [ ] Klantenzone > Betaalmethodes > Toevoegen toont beide gateways, de iframe stuurt door naar Mollie en na betaling staat de betaalmethode er (createCardPayMethod en createBankPayMethod met deze argumenten).
- [ ] Factuur betalen met een nieuwe betaalmethode via beide gateways.
- [ ] WHMCS aanvaardt `pending` uit `_capture` en de status Payment Pending (zet WHMCS die zelf, of enkel onze code?).
- [ ] De cron "Process Credit Card Charges" slaat Payment Pending over en int een factuur pas op de ingestelde dag.
- [ ] Betaalmethode verwijderen roept `_storeremote` met action delete op.
- [ ] Verwijderen via `WHMCS\Payment\PayMethod\Model` na een mislukking werkt (anders weigert onze tabel de inning, maar staat de betaalmethode nog zichtbaar).
- [ ] `paymentReversed()` met chargeback-id en de factuurstatus nadien (onze code zet Unpaid).
- [ ] Mails Direct Debit Payment Failed, Pending en Confirmation en Credit Card Payment Failed bestaan en zijn in het NL en EN aangepast.
- [ ] Hookbestand `includes/hooks/mollie_subscribe.php` laadt, InvoicePaid annuleert een lopende domiciliëring, DailyCronJob draait.
- [ ] `tests/recurring/mollie_testmode.php` met de testsleutel geeft OK (payloads aanvaard door Mollie).
