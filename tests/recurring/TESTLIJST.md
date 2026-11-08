# Testlijst Mollie Recurring (1.1.0)

Gedraaid op 4 nov 2026 met `tests/recurring/run.sh`: nagebootste WHMCS (SQLite, stubs volgens de WHMCS-docs) en een nagebootste Mollie-API achter de echte mollie-api-php 3.15.0. Uitkomst: **25/25 gevallen OK**, 129 controles. Op 8 nov 2026 aangevuld met 4 gevallen (26 tot 29) na de test op hub-dev: **29/29 OK**.

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
- [x] methodes bancontact, ideal
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

## 26 tot 29: fixes na de test op hub-dev (8 nov 2026)

Uitkomst: OK

- [x] 26 Lege of foute instelling methodes eerste betaling: kaart altijd creditcard, domiciliëring zonder kaart (1.1.1)
- [x] 27 Iframe laden maakt nog niets bij Mollie: eerst gateway kiezen, dan Ga verder naar Mollie (1.1.2)
- [x] 28 Bevestiging domiciliëring minstens 0,02 (minimum Bancontact), kaart 0,01 (1.1.3)
- [x] 29 Factuur op domiciliëring betalen zonder bewaarde rekening: eigen knop naar Mollie op de betaalpagina (1.1.4)

## Bevestigd op de echte test-WHMCS hub-dev (8 nov 2026)

WHMCS 9.0.8 op `hub-dev.webscout.eu` (Combell, ontwikkellicentie), Mollie-testsleutel "hub-dev Test", testklanten op test@webscout.be. De klikken in de checkout van Mollie (testkaart, Bancontact Betaald) deed Jens. Opzet en opruimen: handboekartikel `mod-mollie`, deel Testomgeving hub-dev.

- [x] **1. Betaalmethode toevoegen in de klantenzone.** Beide gateways staan bij Betaalmethodes > Toevoegen. Kaart: testkaart, mandaat `creditcard`, betaalmethode RemoteCreditCard, 0,01 teruggestort, dubbele webhook geen tweede betaalmethode. Domiciliëring: Bancontact, mandaat `directdebit`, RemoteBankAccount, 0,02 teruggestort. `createCardPayMethod` en `createBankPayMethod` aanvaard. Standaardgateway en open facturen gaan mee naar de nieuwe gateway.
  Gevonden en opgelost: zonder opgeslagen gatewayinstellingen gaf de kaart-gateway geen methode mee en toonde Mollie alle methodes (1.1.1). WHMCS verstuurt het iframe van de eerst getoonde gateway zelf, waardoor "Add New Bank Account" meteen naar het kaartformulier ging (1.1.2). Met 0,01 toonde Mollie geen Bancontact (1.1.3).
- [x] **2. Factuur betalen met een nieuwe betaalmethode.** Kaart: factuur 6 betaald, mandaat bewaard, 1 boeking, late webhook boekt niet dubbel, mail Credit Card Payment Confirmation. Domiciliëring: factuur 7 betaald via Bancontact, domiciliëring bewaard.
  Gevonden en opgelost: voor een gateway van het type Bank toont WHMCS op de betaalpagina geen iframe maar eigen rekeningvelden. Hook `ClientAreaFooterOutput` toont nu een knop naar Mollie (1.1.4).
- [x] **3. `pending` uit `_capture`.** WHMCS aanvaardt het, zet de factuur **zelf** op Payment Pending (getest met onze `mollie_subscribe_mark_pending` tijdelijk uit) en stuurt Direct Debit Payment Pending. Onze aanroep is dus dubbel, maar onschuldig.
- [x] **4. Cron Process Credit Card Charges.** Int enkel facturen die precies over Process Days Before Due (3) vervallen: vervaldag vandaag of over 2 dagen werd overgeslagen, over 3 dagen geïnd. Facturen op Payment Pending worden overgeslagen. Met de hand gemaakte facturen int de cron nooit (WHMCS-documentatie), enkel facturen uit diensten. Kaart: meteen Paid. Domiciliëring: Payment Pending.
- [x] **5. Betaalmethode verwijderen.** Klantenzone > Delete roept `_storeremote` met delete op ("Remote Delete Success"), mandaat ingetrokken bij Mollie. Vereist `CCAllowCustomerDelete` aan.
- [x] **6. Verwijderen via `WHMCS\Payment\PayMethod\Model`.** Werkt: `deleted_at` gezet na de terugboeking, niet meer zichtbaar in de klantenzone.
- [x] **7. `paymentReversed()`.** Met een nagebootste terugboeking op een echte betaalde testbetaling (Mollie maakt in testmodus geen terugboekingen): tegenboeking met het chargeback-id, factuur van Paid naar Unpaid, WHMCS zet ook de vervaldag van de dienst terug, mail Credit Card Payment Failed (onderwerp "Invoice Payment Failed"), mandaat ingetrokken. Tweede keer: "terugboeking al verwerkt".
- [x] **8. Mails.** Direct Debit Payment Failed, Pending en Confirmation en Credit Card Payment Failed bestaan in WHMCS 9.0.8 en worden verstuurd. Nieuwe teksten NL en EN in webscout-whmcs-mail (`5ab2ec2`), nog niet op webscout.eu. Let op: WHMCS stuurt Direct Debit Payment Confirmation ook als de klant zelf anders betaalde, daarom een neutrale tekst.
- [x] **9. Hookbestand.** `includes/hooks/mollie_subscribe.php` laadt. InvoicePaid annuleerde de lopende domiciliëring van factuur 4 bij Mollie toen die per overschrijving betaald werd. DailyCronJob draait mee met `cron.php all` ("nacontrole 3 betalingen").
- [x] **10. `tests/recurring/mollie_testmode.php`** met de testsleutel: OK (customer, eerste betaling, mandaat, recurring pending, annuleren, intrekken, 410).

Nog open, niet blokkerend:

- In het gatewaylog tonen de regels van de dagelijkse nacontrole het factuur- en klant-id van de laatste cronfactuur in plaats van die van de betaling. Cosmetisch.
- Op de pagina "Add New Bank Account" staat de kaart-gateway standaard aangevinkt (WHMCS kiest de eerste). Kan met een kleine hook.
- Een echte terugboeking via Mollie (live) is niet te testen in testmodus.
