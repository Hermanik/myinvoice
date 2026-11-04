# 37. Bankovní účty a e-mailová avíza (IMAP)

**Cesta: `Finance → Bankovní účty`**

Stránka sdružuje čtyři záložky: **Bankovní výpisy** (import GPC a párování
plateb — viz [24. Banka](24_Banka.md)), **Měny a účty**, **Stavy na účtech**
a **Bankovní avíza z e-mailu**. Záložky kromě výpisů vidí jen administrátor.

Tato kapitola popisuje správu **bankovních účtů dodavatele** (pro PDF faktury,
QR platby a GPC výpisy) a **bankovních e-mailových avíz přes IMAP**. Bankovní avízo je
e-mail od banky s údaji o platbě — MyInvoice ho umí pravidelně načítat, vytěžit
z něj VS, částku, měnu, datum a vlastní účet a vytvořit z něj bankovní transakci
stejně jako z [výpisu](24_Banka.md).

## 37.1 Bankovní účty

Sekce **Měny + bankovní účty** je čistý seznam účtů dodavatele. Účet zde
nastavuješ stejně jako pro PDF faktury, QR platby a GPC výpisy:

- měna a označení účtu,
- české číslo účtu + kód banky,
- případně IBAN/BIC,
- výchozí účet pro danou měnu,
- aktivní/neaktivní stav.

Nastavení bankovních avíz je oddělené níže, aby se běžné bankovní údaje
nemíchaly s parsery a IMAP účty.

### Stavy na účtech

Záložka **Stavy na účtech** zobrazuje každý bankovní účet samostatně podle
čísla účtu, kódu banky a měny. Aktuální stav vychází z posledního oficiálního
GPC nebo PDF výpisu; pokud je dostupné novější e-mailové avízo s disponibilním
zůstatkem, použije se novější údaj.

Pod tabulkou je pro každý účet samostatný graf měsíčních konečných zůstatků
v jeho vlastní měně. Graf **Celkový vývoj v CZK** zobrazuje jednotlivé účty
i řadu **Celkem**. Cizoměnové účty se pro tento graf přepočítávají kurzem ČNB
ke konci příslušného měsíce.

Pokud mají dva účty stejné číslo před lomítkem, rozlišují se kódem banky.
Starý výpis bez uloženého kódu banky se při více možných bankách do zůstatku
nezapočte, protože jej nelze bezpečně přiřadit.

## 37.2 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na
konkrétní bankovní účet dodavatele.

| Sloupec | Význam |
|---|---|
| Bankovní účet | Účet z měn dodavatele, proti kterému se porovnává cílový účet v e-mailu |
| IMAP účet | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet" = výchozí stav bez skenování, „Všechny IMAP účty" = neomezeno |
| Parser | Konkrétní parser provider; „Automatický výběr" = systém zkusí všechny aktivní providery |
| Tolerance | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| Aktivní | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde
z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno,
řádek se nepoužije.

Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek
se při scanování nepoužije, dokud nezvolíš konkrétní IMAP účet nebo vědomě
nepovolíš variantu **Všechny IMAP účty**.

## 37.3 IMAP účty pro bankovní avíza

Každý dodavatel může mít více IMAP účtů, typicky jeden pro každou banku.

| Pole | Význam |
|---|---|
| Název | Popisek v UI, např. „RB avíza" |
| Host / port / šifrování | Připojení k IMAP serveru |
| Uživatel / heslo | Přístup ke schránce; heslo se ukládá šifrovaně |
| Složka | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| Procházet | Ověří připojení a nabídne složky ze serveru |
| Max. zpráv na běh | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| Zpracovat od data | Starší e-maily se ignorují i když spadnou do limitu |
| Vyžadovat ověření autenticity (DKIM/DMARC) | Zpracují se jen e-maily, jejichž odesílatele se podařilo ověřit — viz [37.3.1](#3731-overeni-autenticity-e-mailu). Ostatní se zamítnou (`security_rejected`) |
| Důvěryhodný authserv-id | Volitelné, **doporučeno nechat prázdné**. Jen když znáš authserv-id svého přijímacího serveru — viz [37.3.1](#3731-overeni-autenticity-e-mailu) |
| Přijímat přeposlaná (FW) avíza | Rozpozná banku i z těla e-mailu, když avíza chodí do schránky přeposlaná (odesílatel je tvoje adresa, ne banka) |
| E-mail přeposílatele | Volitelné omezení, od koho smí přeposlaná avíza chodit — adresa (`jan@firma.cz`) nebo doména (`firma.cz`); prázdné = libovolný |
| Po úspěchu | Co udělat se zpracovanou zprávou |

Pokud do schránky chodí avíza **přeposlaná** (např. z firemní schránky na sběrnou
adresu), zapni **Přijímat přeposlaná (FW) avíza**. U přímého avíza poznává banku
podle odesílatele, ale přeposláním se odesílatelem stáváš ty — proto se pak banka
hledá i z těla e-mailu. Volitelně omez **E-mail přeposílatele**, ať se zpracují
jen avíza od tvé adresy. Přeposláním zaniká původní podpis banky (DKIM), takže
ověření autenticity se vztahuje na přeposílatele, ne na banku.

### 37.3.1 Ověření autenticity e-mailu

Hlavička `From` se dá podvrhnout. Padělané avízo by přitom mohlo označit fakturu
(nebo i závazek z DPH) za uhrazenou. Zapni proto u IMAP účtu **Vyžadovat ověření
autenticity (DKIM/DMARC)** — e-mail se zpracuje, jen když se ověří, že ho opravdu
odeslala doména banky. Kontrola se týká jen e-mailů, které by některý aktivní
provider avíz vůbec vzal (podle odesílatele, předmětu a těla); ostatní pošta ve
schránce se normálně označí `parse_failed` a nic se pro ni neověřuje ani
nestahuje z DNS.

**Ve výchozím nastavení se vždy ověřuje DKIM podpis (vlastní kontrola, RFC 6376).**
MyInvoice si v IMAP přečte zprávu přesně tak, jak dorazila, najde v ní podpis
`DKIM-Signature` banky, stáhne její veřejný klíč z DNS
(`<selector>._domainkey.<doména>`, např. `ab._domainkey.airbank.cz`) a podpis
zkontroluje. Funguje s jakýmkoli poštovním serverem, i s **Seznamem**
(`imap.seznam.cz`, Email Profi), který žádný výsledek ověření do zpráv nepřidává.

Hlavičku **`Authentication-Results`** MyInvoice přijme jen tehdy, když u účtu
vyplníš **Důvěryhodný authserv-id** a zpráva obsahuje hlavičku přesně s tímto
identifikátorem — pak se převezme verdikt serveru (`dmarc=pass`, nebo `dkim=pass`
s doménou zarovnanou na odesílatele). Bez vyplněného authserv-id se všechny
hlavičky `Authentication-Results` **ignorují**, protože si je do e-mailu může
vložit i odesílatel (a u poštovního serveru, který vlastní hlavičku nepřidává,
jsou takové jediné, takže by padělek prošel). Pole proto nech prázdné, dokud
přesně nevíš, jak se tvůj přijímací server jmenuje a že hlavičku opravdu
přidává (např. `mx.google.com` u Gmailu). Když hlavička s tímto identifikátorem
ve zprávě chybí, ověří se DKIM podpis.

Zpráva projde vlastní kontrolou jen tehdy, když platí **všechno**:

- aspoň jeden podpis je platný (podepsané hlavičky i tělo e-mailu se po podpisu nezměnily),
- doména podpisu (`d=`) odpovídá doméně odesílatele z hlavičky `From`
  (stejná doména nebo její subdoména, např. `airbank.cz` ↔ `info@airbank.cz`;
  podpis cizí domény se nikdy nepřijme),
- odesílatel v podepsané hlavičce `From` je týž, jehož adresu vidí aplikace
  (podle ní provider avíz pozná banku),
- podpis používá `rsa-sha256` nebo `ed25519-sha256` (starý `rsa-sha1` se odmítá,
  stejně jako RSA klíče pod 1024 bitů),
- podpis nemá omezenou délku těla (`l=`), neexpiroval (`x=`) a klíč v DNS není
  zrušený (prázdné `p=`) ani ve zkušebním režimu (`t=y`),
- hlavička `From` je v e-mailu právě jedna a je podepsaná,
- zpráva je rozumně velká (hlavičky do 256 KiB, tělo do 2 MiB; bankovní avíza
  jsou o řády menší).

Vlastní kontrola neposílá nic poštovnímu serveru; jedinou síťovou operací je
DNS dotaz na klíč banky. Odpověď se pamatuje po dobu jednoho scanu, takže padesát
zpráv od jedné banky znamená jediný dotaz. Doba čekání na nedostupný DNS server
se řídí nastavením systémového překladače (`options timeout:` v `resolv.conf`).

Způsob a výsledek ověření je u každého zpracovaného e-mailu vidět v seznamu
**Zpracované e-maily** (řádek „Ověření: …“); ukládá se do sloupců `auth_method`
(`dkim_local` / `authentication_results`) a `auth_detail`:

| Kód | Význam |
|---|---|
| `dkim_local_pass:airbank.cz` | Vlastní kontrola prošla, podpis je od uvedené domény |
| `dkim_pass_aligned`, `dmarc_pass` | Ověřil důvěryhodný přijímací server (`Authentication-Results`) |
| `dkim_no_signature` | Zpráva nemá žádný DKIM podpis — typicky padělek |
| `dkim_body_hash_mismatch` | Obsah e-mailu se po podepsání změnil |
| `dkim_signature_invalid` | Podepsané hlavičky se změnily, nebo podpis nepatří k veřejnému klíči |
| `dkim_domain_not_aligned` | Podpis je od jiné domény, než je odesílatel e-mailu |
| `dkim_from_domain_mismatch` | Odesílatel v hlavičce `From` nesedí na odesílatele, kterého vidí aplikace |
| `dkim_from_missing`, `dkim_from_ambiguous`, `dkim_from_invalid` | Hlavička `From` chybí, je jich víc, nebo ji nelze jednoznačně přečíst |
| `dkim_key_not_found` | V DNS není veřejný klíč pro daný selector |
| `dkim_key_revoked`, `dkim_key_testing`, `dkim_key_too_weak`, `dkim_key_invalid` | Klíč banky je zrušený, zkušební, příliš slabý nebo nepoužitelný |
| `dkim_algorithm_insecure`, `dkim_algorithm_unsupported` | Podpis používá `rsa-sha1`, resp. nepodporovaný algoritmus |
| `dkim_signature_expired`, `dkim_timestamp_invalid` | Podpis expiroval, resp. má nesmyslný čas |
| `dkim_body_length_limit` | Podpis pokrývá jen část těla (`l=`) — zbytek by mohl být připsaný |
| `dkim_malformed_signature`, `dkim_from_not_signed` | Podpis je poškozený, resp. nepokrývá odesílatele |
| `dkim_header_too_large`, `dkim_message_too_large`, `dkim_too_many_signatures` | Zpráva překračuje bezpečnostní limity kontroly |
| `dkim_no_raw_message` | Zpráva se ze schránky nepodařilo načíst v původní podobě |
| `auth_failed`, `dkim_domain_mismatch` | Zamítnuto podle hlavičky `Authentication-Results` důvěryhodného serveru (hlásí selhání, resp. špatnou doménu) |

Dočasná chyba DNS (`dkim_dns_error`) zprávu **nezamítne**: nezapíše se žádný záznam
a zpráva v mailboxu zůstane nedotčená, takže ji další scan zkusí znovu. V souhrnu
scanu se počítá jako „odloženo (DNS)“.

> 🛈 Vlastní kontrola ověřuje, že zprávu podepsala doména odesílatele, ne že se
> bance dá věřit. Odesílatel se proto stále musí shodovat s odesílatelem, kterého
> očekává provider avíz (u Air Bank `info@airbank.cz`).

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně
zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback
hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo
označovat. Pokud má účet zápis povolený, můžeš zvolit doplňkovou akci po
úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit
jako přečtené.

## 37.4 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** — dodaný aplikací, např. Raiffeisenbank, UniCredit Bank, ČSOB, Česká spořitelna, Fio banka, Banka CREDITAS, MONETA Money Bank nebo Air Bank.
- **Regex provider** — vlastní provider dodavatele, konfigurovaný v UI.

Systémový provider se přímo needituje (je společný pro všechny). Když ho chceš
upravit, použij u něj tlačítko **Duplikovat** — vytvoří se editovatelná kopie,
ve které si dolaď vzory a otestuj ji přes **Test parseru**. V mapování účtu pak
přepneš účet z původního providera na svou kopii. Duplikovat lze i vlastní regex
provider.

Systémový provider Raiffeisenbank rozlišuje směr převodu podle úvodního textu
o příchozí nebo odchozí platbě; u starší či odlišné šablony použije jako záložní
údaj znaménko částky. U odchozí úhrady je vlastním účtem pole **Z účtu** a
protiúčtem pole **Na účet**; u příchozí úhrady je to opačně. Díky tomu se odchozí
avízo mapuje na účet, ze kterého byla platba skutečně odepsána. U karetní
transakce se vlastní účet načte z pole **Účet** a obchodník z pole **Detaily**;
chybějící variabilní symbol ani bankovní protiúčet importu nebrání.

U Air Bank se avíza zapínají v internetovém bankovnictví pod **Účty a karty →
Možnosti → Info o dění na účtu** (odesílatel `info@airbank.cz`, předměty
„Zvýšení/Snížení zůstatku“). Nastavení v IB není úplně intuitivní — praktický
postup je např. v návodu FAPI
[Nastavení zasílání e-mailů o příchozích platbách z Air Bank](https://napoveda.fapi.cz/article/40-nastaveni-zasilani-e-mailu-o-prichozich-platbach-z-air-bank)
(místo FAPI adresy uveď mailbox napojený v MyInvoice).

Detekce e-mailu i vytěžení polí pracují **tolerantně k diakritice**: pokud avízo
dorazí v jiném kódování nebo s rozbitou diakritikou (typicky u přeposlaných
zpráv), vzory `Směr platby` a `Smer platby` se vyhodnotí stejně. Když přesto
nějaký provider zlobí, můžeš si vzory napsat rovnou bez diakritiky.

U regex provideru nastavuješ:

| Pole | Význam |
|---|---|
| Název / kód | Interní identifikace provideru |
| Odesílatel | Whitelist e-mailů, např. `info@rb.cz` |
| Regex předmětu | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| Regex těla | Volitelný pattern, který musí být v těle e-mailu |
| Vytěžená pole | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

Povinná vytěžená pole:

- `variable_symbol`
- `amount`
- `currency`
- `posted_at`
- `recipient_account`

Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`
- `balance` (disponibilní zůstatek účtu z avíza — zobrazí se v detailu
  měsíčního avízo-výpisu a promítne se do přehledu **Stavy na účtech**)

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se
stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum např.
`01. 06. 2026 10:15`.

## 37.5 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název
protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávej reálné e-maily
z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat např. takto:

```text
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
```

Základní nastavení provideru:

| Pole | Hodnota |
|---|---|
| Název | `Raiffeisenbank regex test` |
| Kód | `raiffeisenbank_regex` |
| Aktivní provider | Ano |
| Odesílatel | `info@rb.cz` |
| Regex předmětu | viz níže |
| Regex těla | `Variabilní\s+symbol` |
| Normalizer config | `{}` |

Regex předmětu:

```text
Pohyb\s+na\s+účtě|Pohyb\s+na\s+ucte
```

Regexy pro vytěžená pole:

| Pole | Regex |
|---|---|
| Datum platby | `Datum\s+a\s+čas\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})` |
| Cílový účet | `Na\s+účet\s*([0-9-]+/[0-9]{4})` |
| Částka | `Částka\s+v\s+měně\s+účtu\s*([+\-]?[0-9 .]+,[0-9]{2})\s*[A-Z]{3}` |
| Měna | `Částka\s+v\s+měně\s+účtu\s*[+\-]?[0-9 .]+,[0-9]{2}\s*([A-Z]{3})` |
| Protiúčet | `Z\s+účtu\s*([0-9-]+/[0-9]{4})` |
| Název protistrany | `Z\s+účtu\s*[0-9-]+/[0-9]{4}\s*([^\n]+?)\s*Variabilní\s+symbol` |
| Variabilní symbol | `Variabilní\s+symbol\s*([0-9]+)` |
| Konstantní symbol | `Konstantní\s+symbol\s*([0-9]+)` |
| Zpráva | `Zpráva\s+pro\s+příjemce\s*(.*?)\s*Disponibilní\s+zůstatek` |
| Reference banky | prázdné |
| Disponibilní zůstatek | `Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*([+\-]?[0-9 .]+,[0-9]{2})` |

> 🛈 Do UI zadávej regex bez krajních oddělovačů (`/.../`). Parser je doplní
> sám.

## 37.6 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžeš vložit testovací e-mail, odesílatele a
předmět. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Smazání záznamu zde nemaže transakci ani fakturu. Maže jen deduplikační záznam,
takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používej to jen
jako emergency/debug akci.

### Znovu zpracovat zamítnuté avízo

Pravé avízo se stavem `security_rejected` (typicky potom, co banka klíč v DNS
opravila, nebo co jsi upravil **Důvěryhodný authserv-id**) znovu zpracuješ
bez zásahu do databáze:

1. U záznamu klikni na **Smazat záznam** (zamítnutý e-mail nemá žádnou
   transakci ani platbu, nic dalšího se nesmaže).
2. Klikni na **Spustit scan** (nebo počkej na cron). Scan načte zprávu ze
   schránky znovu a zpracuje ji včetně ověření autenticity.

Podmínky: zpráva musí být stále ve složce, kterou účet čte, a mezi posledními
**Max. zpráv na běh** (u staršího avíza limit dočasně zvyš, nejvýš na 500), a nesmí
být starší než **Zpracovat od data**. Pokud jsi
v účtu nastavil po neúspěchu **přesunout do složky**, vrať zprávu ručně zpět
do čtené složky. Opakované zpracování nevytvoří duplicitní platbu: transakce
z avíza se vždy hledá podle `Message-ID`.

## 37.7 Cron pro e-mailová avíza

Pro automatické zpracování nastav samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP
účty dodavatele, načte nejnovější zprávy podle limitu a zapíše heartbeat do
plánovaných úloh.

## 37.8 Návod krok za krokem: zapnutí párování plateb z e-mailových avíz

Stejný návod je v aplikaci na záložce **Bankovní avíza z e-mailu** (rozbalovací
blok „Návod: jak zapnout automatické párování plateb z e-mailových avíz").

**Jak to funguje:** banka pošle při každém pohybu na účtu krátký e-mail (avízo).
MyInvoice si pravidelně (každých 30 minut) přečte e-mailovou schránku, avíza
rozpozná, založí z nich platby a spáruje je s fakturami podle variabilního
symbolu a částky.

### Krok 1 – v bance: zapněte e-mailová avíza

Banka musí posílat e-mail o každé příchozí i odchozí platbě na adresu, kterou čte
MyInvoice.

**Air Bank:**

1. Přihlaste se do internetového bankovnictví Air Bank **na počítači** (toto
   nastavení se dělá v internetovém bankovnictví, v mobilní aplikaci nebývá).
2. V horním menu otevřete **Účty a karty** a vyberte účet, ze kterého chcete avíza
   dostávat (u více účtů nastavte každý zvlášť).
3. V levém menu klikněte na **Možnosti** → **Info o dění na účtu**.
4. Zaškrtněte **Jiný kontakt** a do pole **Mail** zadejte adresu, na kterou má
   banka avíza posílat (např. `banka@vasedomena.cz`, viz Krok 2).
5. U **Informace o změně zůstatku** zvolte posílat mailem a jako hranici zadejte
   nejnižší nabízenou hodnotu (0 nebo 1 Kč), jinak se malé platby nepošlou.
   Zapněte avíza o zvýšení zůstatku (příchozí platby) i o snížení zůstatku
   (odchozí platby), pokud je banka nabízí zvlášť.
6. Klikněte na **Pokračovat** a změnu potvrďte podle pokynů banky.

Názvy položek se v bankovnictví mohou mírně lišit. Postup vychází z veřejných
návodů ([FAPI](https://napoveda.fapi.cz/article/40-nastaveni-zasilani-e-mailu-o-prichozich-platbach-z-air-bank),
[Fakturoid](https://www.fakturoid.cz/podpora/parovani/air)). Avíza chodí od
`info@airbank.cz` s předmětem „Zvýšení zůstatku na účtu …" (příchozí platba) nebo
„Snížení zůstatku na účtu …" (odchozí platba); v textu je číslo vašeho účtu,
částka, variabilní symbol a protiúčet. Šablonu MyInvoice zná.

**Jiná banka:** podporované banky jsou uvedené v kapitole 37.4. V bankovnictví
vyhledejte nastavení e-mailových notifikací (často Infoservis, Notifikace, Info
e-maily) a zapněte e-mail o každé příchozí i odchozí platbě.

### Krok 2 – e-mailová schránka

MyInvoice čte schránku přes IMAP. Doporučená je samostatná schránka jen pro
avíza. Alternativou je **sběrná schránka (catch-all)**: má-li doména zapnutou
sběrnou schránku, doručí se e-mail na jakoukoli adresu `@vasedomena.cz` do jedné
hlavní schránky. Bance proto můžete zadat třeba `banka@vasedomena.cz` (aniž ji
zakládáte), ale MyInvoice se přihlašuje do hlavní schránky, např.
`info@vasedomena.cz`.

Co MyInvoice se schránkou dělá:

- čte nejnovější zprávy ve zvolené složce (výchozí `INBOX`) bez označení jako
  přečtené a každou zkusí rozpoznat jako avízo některé z podporovaných bank,
- e-maily od jiných odesílatelů žádnou platbu nevytvoří; v seznamu **Zpracované
  e-maily** se u nich ale objeví záznam se stavem `parse_failed` (jednou na
  zprávu, je to v pořádku),
- zprávy nemaže ani nepřesouvá, dokud u účtu nezvolíte jinou akci v poli **Po
  úspěchu**,
- v jednom běhu načte jen nejnovějších N zpráv (**Max. zpráv na běh**, výchozí 50,
  maximum 500). Ve rušné sběrné schránce hodnotu zvyšte a nastavte **Zpracovat od
  data** na dnešek,
- čte jen nastavenou složku. Spam se nečte; avízo, které skončí ve spamu, přesuňte
  do `INBOX` (nebo změňte pole **Složka**).

| Poskytovatel | Host | Port | Šifrování | Přihlášení / heslo |
|---|---|---|---|---|
| Seznam (Email.cz, Email pro firmy / Moje doména) | `imap.seznam.cz` | 993 | SSL | celá e-mailová adresa; při dvoufázovém ověření **heslo pro aplikace** |
| Gmail / Google Workspace | `imap.gmail.com` | 993 | SSL | celá adresa; **heslo pro aplikace** (vyžaduje dvoufázové ověření) |
| Microsoft 365 / Outlook | `outlook.office365.com` | 993 | SSL | jen jméno a heslo, OAuth se nepodporuje |
| Jiný | viz nápověda poskytovatele | obvykle 993 | SSL | celá adresa |

- **Seznam:** heslo pro aplikace vytvoříte na seznam.cz v Zabezpečení účtu →
  Dvoufázové ověření → Heslo pro aplikace; musí se lišit od přihlašovacího hesla
  ([nápověda Seznamu](https://o-seznam.cz/napoveda/ucet/en/dvoufazove-overeni/postovni-programy/)).
  IMAP je u schránek Seznamu trvale zapnutý a nelze ho vypnout
  ([nastavení pro aplikace](https://o-seznam.cz/napoveda/email/mohlo-by-se-hodit/postovni-programy-a-aplikace/)),
  takže nic zapínat nemusíte. Údaje pro schránku v Email pro firmy / Moje doména
  jsou stejné; pokud přihlášení selže, ověřte u správce domény, že schránka
  existuje a heslo platí.
- **Microsoft 365:** základní přihlášení heslem pro IMAP Microsoft u většiny účtů
  vypnul, takže to často nepůjde. Řešení: nechte avíza přeposílat do jiné schránky
  (**Přijímat přeposlaná (FW) avíza**, viz 37.3).

### Krok 3 – v MyInvoice

Vše se dělá na záložce **Bankovní avíza z e-mailu** (Finance → Bankovní účty).

1. V sekci **IMAP účty pro bankovní avíza** klikněte na **Nový IMAP účet**.
2. Vyplňte **Název** (např. „Air Bank avíza"), **Host**, **Port**, **Šifrování**,
   **Uživatel** a **Heslo** podle Kroku 2. **Složku** nechte `INBOX`; tlačítko
   **Procházet** ověří připojení a nabídne složky ze serveru.
3. Do **Zpracovat od data** zadejte dnešní datum. Ponechte zaškrtnuté **Povolit
   zpracování bankovních avíz z tohoto účtu** a **Ověřit certifikát serveru**.
4. Klikněte na **Uložit IMAP účet** a v tabulce u nového účtu na **Test**.
5. V sekci **Mapování bankovních avíz** najděte řádek svého bankovního účtu, ve
   sloupci **IMAP účet** změňte výchozí **Žádný IMAP účet** na účet z kroku 2, u
   **Parser** stačí **Automatický výběr** (u Air Bank lze zvolit Air Bank),
   zaškrtněte **Aktivní** a klikněte na **Uložit mapování**.
6. Mapování funguje podle čísla účtu: bankovní účet musí být evidován na záložce
   **Měny a účty** včetně čísla účtu a kódu banky (Air Bank má kód 3030).
7. Čtení schránky běží automaticky každých 30 minut přes plánovanou úlohu
   `cron-bank-email-notices` (viz 37.7). Hned po nastavení můžete použít tlačítko
   **Spustit scan** v sekci **Zpracované e-maily**.

### Krok 4 – ověření a řešení potíží

1. Pošlete si malou testovací platbu na svůj účet (ideálně s variabilním symbolem
   existující neuhrazené faktury).
2. Počkejte do 30 minut nebo klikněte na **Spustit scan**; pod tlačítkem uvidíte
   souhrn (zpracováno, spárováno, chyby).
3. V seznamu **Zpracované e-maily** má zpracované avízo stav `processed_success`,
   vidíte variabilní symbol a částku a u spárované platby zelený odkaz na fakturu.
   Platba se objeví také v Bance.
4. Každé avízo se zpracuje jen jednou; smazání záznamu v seznamu smaže jen záznam o
   zpracování, ne platbu ani fakturu.

Když něco nefunguje:

- **Test selže** – zkontrolujte host, port 993, šifrování SSL; uživatel je celá
  adresa. U Seznamu, Gmailu a dalších se dvoufázovým ověřením je nutné heslo pro
  aplikace.
- **Test projde, ale nic se neobjevuje** – e-mail mohl skončit ve spamu nebo jiné
  složce (viz Krok 2), banka avízo neposlala, nebo byla platba pod nastavenou
  hranicí.
- **`match_failed` – cílový účet není namapovaný** – zkontrolujte mapování (vybraný
  IMAP účet, **Aktivní**) a číslo účtu na záložce **Měny a účty**.
- **Platba se nespárovala s fakturou** – plátce nezadal variabilní symbol nebo
  nesedí částka; spárujte platbu ručně v Bance.
- **Zpráva ve schránce je, ale scan ji přeskočil** – je starší než **Zpracovat od
  data** nebo mimo nejnovějších N zpráv (**Max. zpráv na běh**).
