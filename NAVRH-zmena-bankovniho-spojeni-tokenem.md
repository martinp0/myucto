# Návrh k diskusi: token smí změnu bankovního spojení jen navrhnout

**Stav: podklad k diskusi. Není to implementace, není to smlouva API a není to žádost o merge.**

Tento text navazuje na uzavřený a nesloučený
[PR #297](https://github.com/radekhulan/myucto/pull/297)
a na komentář z 5. 11. 2026
([#issuecomment-6661374159](https://github.com/radekhulan/myucto/pull/297#issuecomment-6661374159)).
PR #297 se neotevírá. Issue k tématu se nepodařilo založit (GitHub vrátil
`403 Resource not accessible by integration`), proto je podklad v tomto
samostatném návrhu. Dokud se na podobě nedohodneme, do kódu, schématu,
OpenAPI, testů ani manuálu nic z toho nepatří.

Manuál popisuje jen současné chování produktu, proto tohle není kapitola
v `manual/`. Text leží vedle `CONTRIBUTING.md` a `SECURITY.md`, kde jsou
podklady pro přispěvatele.

## Přijetí původního zamítnutí

Zamítnutí bereme. Bearer token, a to ani volitelně, bankovní spojení firmy
nezapíše a platby nepřesměruje.

Důvody z komentáře platí i pro tento podklad:

- token žije dlouho ve skriptech, CI a konfiguraci a step-up při jeho vzniku
  chrání jen okamžik udělení,
- volitelný příznak, který zápis otevře, by správci časem zaškrtávali „pro
  jistotu“,
- upozornění až po provedené změně přichází pozdě,
- stejný token používá MCP server, tedy i AI agent, a podvržená instrukce
  nesmí účet příjemce změnit,
- ruční úprava ve webu je výjimečná a krátká, takže přímý zápis tokenem
  neodpovídá riziku.

Závěr z `2f9cb204a` (`fix(api): token nemůže měnit bankovní účty firmy`)
zůstává: cesta `/api/settings/currencies` je v
`ApiScopeMiddleware::BEARER_READ_ONLY`. Token ji smí jen číst. `PUT`, `POST`
ani `DELETE` této cesty se pro token neotevírají. Veřejné API dál mutaci
měnového účtu nepopisuje.

## K čemu by návrh byl

Jde o hromadnou přípravu dat ve více instalacích. Skript nebo agent údaje
sestaví a nechá je čekat. Účet, ze kterého se tiskne platební instrukce a QR,
se změní až ve chvíli, kdy to správce firmy vědomě potvrdí v přihlášeném
webovém rozhraní.

## Srovnání

| | Zamítnutý model (PR #297) | Tento podklad |
|---|---|---|
| Co token udělá | Zapíše číslo účtu, kód banky, název banky, IBAN a BIC | Jen uloží čekající návrh |
| Kdy se změní příjemce | Ihned | Až po schválení správcem firmy ve webu |
| Ověření člověka | Step-up při vzniku tokenu | Nový step-up až při schválení, v platné session |
| Koho systém vyrozumí | Vlastníka tokenu, a až po změně | Všechny správce firmy při podání návrhu i při každém vyřízení |
| Faktury, platby, QR | Mohou nést nový účet dřív, než si někdo e-mail přečte | Zůstanou na dnešních hodnotách, dokud není schválení |
| MCP a AI agent | Mohl by účet změnit | Smí nanejvýš založit návrh a vyvolat upozornění |

## Co se do schválení nesmí pohnout

Dokud návrh není schválený, platí dnešní řádek v `currencies`:

- sloupce `account_number`, `bank_code`, `bank_name`, `iban` a `bic` se
  nemění,
- nespustí se `InvoicePdfRenderer::invalidateByCurrency`,
- nespustí se `OwnBankAccountRegistrar::syncFromCurrency`,
- platební instrukce, QR a e-mailové proměnné faktury dál čtou současný účet.

Schválení je jediný přechod, který tyto sloupce zapíše. Má projít stejnými
pojistkami jako dnešní webové uložení (vlastnictví účtu, cizí nárok na účet,
návaznost na registr vlastních účtů). Samo o sobě nepřepisuje už vystavené
doklady, které mají vlastní snímek účtu. Dnešní webové uložení po změně
bankovních polí invaliduje PDF u konceptů a faktur bez snímku. Stejný důsledek
má nastat až v okamžiku schválení, ne dřív.

Dnešní ruční uložení přihlášeným uživatelem ve stávajícím formuláři tento
podklad neruší. Step-up popsaný níže patří ke schválení návrhu, který přinesl
token.

## Průběh

1. Token podá návrh na existující měnový účet své firmy. Návrh obsahuje jen
   bankovní pole, která se mají změnit, a původ, odkud přišel.
2. Systém ověří pole, uloží čekající návrh a vyrozumí všechny správce firmy.
   Účet příjemce zůstane.
3. Správce ve webu vidí srovnání původních a navrhovaných hodnot a původ
   návrhu. Schválit nebo zamítnout může jen odtud.
4. Schválení spotřebuje čerstvý step-up a teprve potom zapíše účet.
   Zamítnutí účet nezapíše. Obě vyřízení vyrozumí znovu všechny správce firmy.
5. Návrh, který mezitím přestal sedět na skutečný účet, nebo kterému vypršela
   lhůta, už schválit nejde. I takové vyřízení se správcům oznámí jednou.

Token schválení, zamítnutí ani obnovení vypršelého návrhu nezavolá.

### Srovnání a původ

Obrazovka i zpráva správcům ukazují obě strany, po polích:

- účet, kód banky, název banky, IBAN, BIC, jak jsou uložené teď,
- hodnotu, kterou návrh chce,
- která pole návrh nenesl (ta se nemění).

Původ, bez kterého správce návrh neposoudí:

- firma a id měnového účtu,
- id a už uložený prefix tokenu, nikdy jeho tajemství,
- uživatel, kterému token patří,
- čas podání a IP klienta, ve stejném smyslu, v jakém ji dnes bere audit.

Veřejný dokument žádné skutečné číslo účtu neobsahuje a obsahovat nemá.

### Schválení

Schválit smí správce firmy v přihlášené session webového rozhraní.
`EffectiveRole::isCompanyAdmin()` dnes znamená superadmina instalace, nebo
roli `admin` / `admin_plus`.

Step-up má být nový, účelově vázaný a jednorázový, ve stejném mechanismu jako
dnešní citlivé operace v `MfaStepUpService`: proof patří konkrétnímu uživateli
a session a spotřebuje se v téže databázové transakci jako zápis účtu. Záložní
kód, který dnes nesmí potvrdit vydání API tokenu, nemá stačit ani tady. Bearer
token proof nevystaví ani nespotřebuje.

Přesná konstanta operace, a zda se kromě passkey nebo TOTP znovu opisuje heslo,
je otevřená otázka níže. Dnešní vydání tokenu step-upuje passkey nebo TOTP.
Komentář k PR #297 mluví o přihlášení a step-up. Podklad proto trvá na čerstvém
step-up v session a tvar hesla nechává na dohodě.

### Zamítnutí

Správce firmy návrh ve stejném webovém místě zamítne. Zamítnutí:

- nezmění `currencies` ani registr vlastních účtů,
- ukončí návrh, aby nešel později schválit omylem,
- může nést krátký důvod pro ostatní správce,
- vyrozumí všechny správce firmy.

Zda zamítnutí vyžaduje stejný step-up jako schválení, je otevřená otázka.
Tiché shození návrhu ukradenou session by bylo stejně špatně viditelné jako
tiché schválení, proto podklad ke step-up u zamítnutí přiklání, ale
nepovažuje ho za uzavřený.

### Vyrozumění všech správců firmy

Adresáti jsou správci dané firmy, ne jen vlastník tokenu a ne jen ten, kdo
klikne. Stejný okruh dostane zprávu při podání návrhu i při vyřízení
(schválení, zamítnutí, vypršení, zastarání).

Zpráva má nést srovnání polí a původ, protože bez toho z ní nejde poznat, co
se po správci chce. Tajemství tokenu, heslo ani step-up proof do ní nepatří.

Jak přesně okruh sestavit z `user_suppliers` a rolí, je otevřená otázka.
Patří do něj členové firmy s rolí správce. Jestli má dopis dostat i superadmin
instalace, který v té firmě členem není, necháváme na vás.

## Validace polí

Návrh smí nést jen `account_number`, `bank_code`, `bank_name`, `iban` a `bic`.
`label`, `symbol`, `decimals`, `is_active`, `is_default` a jakékoli jiné pole
jsou neplatný vstup a návrh se neuloží. Prázdný návrh, který žádné bankovní
pole nemění, také ne.

Před uložením návrhu i znovu těsně před zápisem při schválení mají platit
stejná pravidla jako dnešní webové uložení:

- `CurrencyBankAccountFields::validationErrors` (délky sloupců a povolený tvar
  kódu banky),
- normalizace přes `CurrencyBankAccountFields::normalized`,
- odmítnutí účtu, který `updateCurrency` už dnes odmítá jako cizí
  (`rejectForeignBankAccount`).

`IbanValidator` (mod-97) se na této cestě dnes nevolá. Používá se u jiných
účtů, například u klientských a mzdových. Zda ho má návrh vyžadovat už při
podání, nebo se má držet přesně dnešního webového uložení, je otevřená otázka.
Návrh, který by webový formulář stejně odmítl, by správce neměl dostat k
potvrzení.

## Nejmenší oprávnění

- Žádný scope tokenu, včetně `read_write`, nezíská zápis na
  `/api/settings/currencies`. Díra v `BEARER_READ_ONLY` pro stávající `PUT`
  není součástí návrhu.
- Schválení a zamítnutí jsou session a role správce firmy. Token je nezavolá.
- Návrh nezakládá příznak, který by později zápis zapnul. Takový příznak
  odmítnutý PR #297 měl a zůstává odmítnutý.
- Čtení cizích návrhů a jejich hodnot jiným tokenem by rozšiřovalo plochu
  citlivých údajů. Kdo návrh podal, hodnoty zná. Ostatní tokeny je kvůli tomu
  číst nemusí.

Dnešní mutace měny je na mapě rout pod `settings.bank_accounts` (zápis) a v
`SettingsAction::guard` ještě pod `settings.company.write`. Schválení návrhu
má být užší: správce firmy, ne každá delegovaná role, která smí účty upravovat.
Jestli má být průnik těchto dvou kontrol, nebo výhradně `isCompanyAdmin()`,
je otevřená otázka.

## Zastaralý návrh

Návrh si při podání zapamatuje normalizované bankovní pole účtu, proti kterému
vznikl. Při schválení se porovnají s živým řádkem. Schválení selže a nic
nezapíše, když:

- se některé z pěti bankovních polí mezitím změnilo (třeba ručně ve webu),
- řádek měny už neexistuje nebo patří jiné firmě,
- je návrh zamítnutý, vypršelý nebo už vyřízený.

Správce uvidí, že návrh míří na účet, který už neplatí, a že ho nelze potvrdit
proti novému stavu. Nová podoba se podává jako nový návrh. Tím se nestane, že
starý návrh přepíše ruční opravu, která přišla mezitím.

## Expirace

Čekající návrh má lhůtu. Po ní nejde schválit a systém jednou vyrozumí
správce firmy. Délku lhůty necháváme otevřenou. Změna účtu je výjimečná, takže
dává smysl mluvit o dnech, ne o měsících. Číslo sem záměrně nepíšeme.

## Omezení četnosti a spam vyrozumění

Samotný token účet nezmění, ale bez stropu by mohl správce zavalit zprávami.
Podklad proto počítá s odděleným limitem podání návrhu, ne jen s obecným
limitem API:

- strop na token a na firmu za časové okno,
- stejný čekající návrh na stejný účet a stejné hodnoty druhou zprávu nepošle
  a vrátí už existující návrh,
- každé vyřízení (schválení, zamítnutí, vypršení, zastarání) vyrozumí správce
  jednou.

Konkrétní počty a okna jsou otevřená otázka. Obecný rate limit API a brzda
odchozí pošty samy o sobě nestačí: brzda pošty chrání doručování, ne klid
správců před sérií různých návrhů.

## Audit a redakce citlivých údajů

Zápis jde přes stávající `ActivityLogger`. Vlastní logger vedle něj nezakládáme.
`account_number` a `iban` jsou v `REDACT_KEYS` a do `activity_log` padnou jako
`[REDACTED]`. To platí i pro payload návrhu, schválení a zamítnutí.

Redakce je podle názvu klíče. Stejné hodnoty pod jiným klíčem by v logu
zůstaly. Proto má audit u této události nést názvy polí, id návrhu, id měny,
identifikaci tokenu (id a prefix) a výsledek, a ne surové číslo účtu ani IBAN
v klíči, který seznam nezná.

`bank_code`, `bank_name` a `bic` dnes v `REDACT_KEYS` nejsou. Zda mají v auditu
tohoto toku být také vynechané, nebo zda stačí stávající seznam, je otevřená
otázka. Do logu nepatří tajemství tokenu, heslo, step-up proof ani celý
e-mail.

Srovnání plných hodnot patří na obrazovku přihlášeného správce a do zprávy
správcům. Do auditního JSON ne.

Návrh událostí, názvy nejsou smlouva: podání, schválení, zamítnutí, vypršení,
zastarání. Id firmy se má ukládat explicitně. `ActivityLogger` ho umí doplnit
jen u některých `entity_type` a měnový účet mezi nimi dnes není.

## Otevřené otázky

Nic v tomto seznamu není rozhodnuté API, oprávnění ani schéma. Jde o body,
bez kterých implementaci začít nechceme.

1. Má být podání návrhu vůbec na bearer tokenu, nebo má zůstat u ručního
   formuláře a token se tohoto tématu dál nedotkne? Podklad stojí o první
   možnost jen proto, že ji komentář k PR #297 připustil.
2. Kdo návrh podat smí: každý token se scope `read_write` vázaný na firmu,
   nebo užší a ve výchozím stavu vypnutá schopnost? Užší schopnost znovu
   zavádí zaškrtávátko, byť s účinkem „jen návrh“. Širší schopnost znamená, že
   uniklý `read_write` token umí správce obesílat. Které zlo je menší?
3. Jaká je cesta a tvar těla? Stávající `PUT /api/settings/currencies/{id}`
   má zůstat zápisem pro session a pro token zavřený. Oddělená operace by
   starý `PUT` neoslabila. Její URL, sloveso a JSON necháváme nedohodnuté.
   Veřejný `/api/v1` je kurátorovaný read-only subset. Jestli podání návrhu
   vůbec patří do veřejného API, je součást této otázky.
4. Smí token, který návrh podal, číst jeho stav, aby skript poznal
   schválení, zamítnutí nebo vypršení? Čtení by nemělo vracet víc, než token
   sám poslal, a nemělo by sahat na cizí návrhy.
5. Je schválení výhradně `isCompanyAdmin()`, nebo průnik se
   `settings.bank_accounts` a `settings.company.write`?
6. Koho přesně znamená „všichni správci firmy“ v instalaci s více firmami:
   členové `user_suppliers` s rolí `admin` / `admin_plus`, a patří sem
   superadmin instalace mimo toto členství?
7. Má zamítnutí stejný step-up jako schválení? Má schválení kromě passkey
   nebo TOTP vyžadovat i znovu zadané heslo?
8. Má návrh při podání vyžadovat mod-97 IBAN (a případně kontrolu českého
   čísla účtu), nebo přesně dnešní `CurrencyBankAccountFields`?
9. Jaká je lhůta expirace a jaké jsou stropy podání na token a na firmu?
10. Mají se v auditu tohoto toku skrýt i `bank_code`, `bank_name` a `bic`?
11. Smí token vlastní čekající návrh stáhnout, aniž by ho správce musel
    zamítat? Stažení není schválení a účet by dál neměnilo. I tak by mělo
    jednou vyrozumět správce, pokud už jim návrh odešel.
12. Má ruční úprava účtu ve stávajícím formuláři dostat stejný step-up, nebo
    zůstane, jak je dnes, a step-up platí jen pro vyřízení návrhu z tokenu?

## Mimo tento podklad

Žádná migrace, změna OpenAPI, úprava middleware, obrazovka, test, šablona
e-mailu ani manuálová kapitola. Až po dohodě na otázkách výše, a jen pokud
o ni budete stát.
