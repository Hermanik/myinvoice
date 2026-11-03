# 25. Upomínky po splatnosti

Když klient nezaplatil včas, můžeš mu poslat **upomínku** — speciální e-mail
s textem typu „Vaše faktura č. XXX byla splatná YY dní zpět, prosíme o úhradu".

Upomínky lze posílat **3 způsoby**:

1. **Manuálně** z detailu jedné faktury (tlačítko)
2. **Hromadně** z [Seznamu faktur](09_Faktury.md) (bulk action)
3. **Automaticky** z cronu (`cron-send-reminders.php`)

## 25.1 Předpoklady

Aby šla upomínka odeslat, faktura musí:

- Být typu `Faktura` (ne proforma, dobropis ani storno)
- Být ve stavu `issued`, `sent` nebo `reminded`
- Být **po splatnosti** (`due_date < dnes`)
- Mít k dispozici klientův e-mail (hlavní + případné fakturační)
- Mít zapnutý přepínač **Posílat automatické upomínky** (viz § 25.7) — týká se
  jen automatického cronu; ruční i hromadné odeslání funguje vždy.

## 25.2 Manuální upomínka

Otevři [Detail faktury](11_Faktura_PDF.md) → tlačítko **Upomínka**.

![Tlačítko upomínka](img/12_upominka_btn.webp)

Po kliknutí:

- E-mail jde na: kontakty klienta s účelem **Upomínky** *(viz
  [§ 13.2.2](13_Klienti.md))*; bez nich kontakty **Doklady**; bez kontaktů
  na `klient.hlavni_email + zakazka.fakturacni_emaily[]`. Příjemce
  vidíš v potvrzovacím dialogu vč. zdroje.
- Šablona: `invoice_reminder` (CZ / EN podle jazyka klienta)
- Status faktury → `reminded`
- `last_reminder_at` = teď
- `reminder_count` += 1

Activity log: `invoice.reminded` s počtem dní po splatnosti.

### 25.2.1 Test upomínky

Vedle **Upomínka** je tlačítko **Test upomínky** — pošle stejný e-mail jen na
**tvůj** e-mail (admina, kterého jsi přihlášen). Užitečné pro:

- Vyzkoušení šablony před odesláním klientovi
- Ověření, že SMTP funguje
- Náhled, jak vypadá HTML verze e-mailu v tvém klientu

## 25.3 Hromadná upomínka

Z **Faktury → filtr „Po splatnosti"** zaškrtni více faktur → bulk action
**Upomínka (N)**.

![Hromadná upomínka](img/12_upominka_bulk.webp)

Server:

1. Pro každou fakturu zkontroluje, že splňuje předpoklady (§ 25.1)
2. Cooldown — pokud byla upomínka poslána před **<14 dny**, faktura se
   přeskočí
3. Pošle e-mail
4. Update statusu

Hláška o výsledku: `Odesláno: 8, přeskočeno (cooldown): 2, chyb: 0`.

## 25.4 Cron — automatické upomínky

Pro pravidelné upomínání nastav cron:

```bash
cmd/cron-send-reminders.sh    # 1× denně, doporučeně 09:00 Po–Pá
```

Skript `php api/bin/cron-send-reminders.php` má parametry:

| Parametr | Default | Význam |
|---|---|---|
| `--days=N` | (per dodavatel) | Faktura musí být po splatnosti alespoň N dní. Bez parametru se čte **práh nastavený u dodavatele** (§ 25.7, default 3); `--days` ho pro daný běh přebije. |
| `--cooldown=N` | `7` | Min. počet dní mezi dvěma upomínkami stejné faktury |
| `--dry-run` | — | Jen vypíše, co by udělal, **bez odeslání** |

### 25.4.1 Doporučené nastavení

```cron
# Po-Pá v 9:00 — upomínat faktury 5+ dní po splatnosti, max 1× za 14 dní
0 9 * * 1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh --days=5 --cooldown=14
```

> 💡 `--days=5` je rozumný „grace period" — klient mohl mít dovolenou,
> bankovní poplatek, nebo sis ty zapomněl naimportovat výpis.

### 25.4.2 Dry-run pro test

Před produkčním nasazením:

```bash
php api/bin/cron-send-reminders.php --days=5 --dry-run
```

Vypíše:

```
[dry-run] Faktura #2604012 (ACME s.r.o., 12 dní po splatnosti) — by se odeslala na 3 adresy
[dry-run] Faktura #2604015 (Studio Fialka, 7 dní po splatnosti) — by se odeslala na 1 adresu
[dry-run] Faktura #2604008 — přeskočena (poslední upomínka před 4 dny < cooldown 14)
[dry-run] CELKEM: 2 by se odeslaly, 1 přeskočena.
```

## 25.5 Šablona upomínky

Šablona je v **Systém → E-mail šablony → invoice_reminder**.

![Editor šablony upomínky](img/12_sablona.webp)

Můžeš editovat:

- **Předmět** — `{{ varsymbol }}` placeholder pro VS faktury
- **HTML tělo** — Twig template
- **Plain text tělo** — fallback pro klienty bez HTML

### 25.5.1 Dostupné placeholders

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol faktury |
| `{{ amount }}` | Částka k úhradě, formátovaná |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Datum splatnosti |
| `{{ days_overdue }}` | Počet dní po splatnosti |
| `{{ client_name }}` | Jméno klienta |
| `{{ supplier_name }}` | Jméno dodavatele |
| `{{ payment_link }}` | (volitelné) odkaz na platební bránu |
| `{{ reminder_count }}` | Počet již odeslaných upomínek (1 = první, 2 = druhá, …) |
| `{{ late_interest }}` | Naběhlý zákonný úrok z prodlení (viz [§ 25.9](#259-zakonny-urok-z-prodleni)); prázdné, dokud je funkce vypnutá. Položky: `interest`, `as_of`, `delay_from`, `annual_rate_percent`, `repo.rate_percent`, `repo.half_year_start`, `fixed_cost.claimable`, `fixed_cost.amount_czk` |

### 25.5.2 Multi-jazyčnost

Pro každou šablonu jsou **4 varianty**:

- `cs.html` (CZ HTML)
- `cs.txt` (CZ plain)
- `en.html` (EN HTML)
- `en.txt` (EN plain)

Vybere se podle `klient.language`.

## 25.6 Tipy

- **Cooldown 14 dní** je rozumný — kratší by byl agresivní, delší se obchází.
- **Eskalace tónu** — pomocí `{{ reminder_count }}` můžeš v šabloně použít
  Twig logiku: `{% if reminder_count >= 3 %}poslední výzva{% endif %}`.
- **Cron nepouštěj v sobotu/neděli** — klient nečte e-maily, vyřeší to až
  v pondělí, ale na statistikách to vypadá divně. Cron expression `1-5`
  (Po–Pá) je standard.
- **Po druhé upomínce zvaž osobní telefonát** — automatika neřeší vztahy.
  E-mailová upomínka je jen formalita.
- **Test upomínky** = vždy před produkčním cronem. Nešťastné je posílat
  klientovi rozbitý HTML.

## 25.7 Vypnutí upomínek u konkrétní faktury a práh dní

Automatické upomínky lze řídit na třech úrovních; cron pošle upomínku, jen když
**všechny tři** dovolí (zapnuto u dodavatele **i** u klienta **i** u faktury):

| Úroveň | Kde | Význam |
|---|---|---|
| Dodavatel | Nastavení → dodavatel | Globální vypnutí pro celého dodavatele |
| Klient | Detail klienta | Vypnutí pro všechny faktury daného klienta |
| **Faktura** | Editor faktury | Vypnutí pro jedinou konkrétní fakturu |

### 25.7.1 Přepínač na faktuře

V [editoru faktury](10_Faktura_editor.md) je v pravém boxu **Datumy**, hned pod
polem *Splatnost*, přepínač **Posílat automatické upomínky** (výchozí: zapnuto).
Když ho vypneš, cron tuto fakturu přeskočí, i kdyby měl dodavatel i klient
upomínky zapnuté. **Ruční i hromadné** odeslání upomínky funguje vždy. U dobropisů
se přepínač nezobrazuje (dobropisy se neupomínají).

### 25.7.2 Práh „po kolika dnech po splatnosti"

V **Nastavení → dodavatel** nastavíš, **po kolika dnech po splatnosti** se má
poslat první automatická upomínka. Na výběr jsou předvolby **3 dny / týden /
měsíc** nebo **vlastní** počet dní (1–365). Hodnota je per dodavatel; cron ji čte
automaticky. Parametr `--days=N` (§ 25.4) ji pro daný běh přebije — hodí se pro
mimořádný / ruční běh.

## 25.8 Kontrola, co se opravdu odeslalo (a co ne)

V **Systém → Odeslané e-maily** je přehled **všech** e-mailů, které aplikace
rozeslala — odeslání faktur, upomínky, schvalovací upomínky, poděkování za
úhradu, připomínky konceptů i testovací odeslání. Automatické (cron) odeslání
jsou připsána „Systému".

Přehled ukazuje **i neúspěšná odeslání**: když odeslání selže (nedostupný SMTP,
odmítnutý příjemce, chyba při generování PDF), zapíše se červený řádek se stavem
**Neodesláno** a textem chyby. Filtr **stavu** (Vše / Odesláno / Neodesláno) a
zkratka **Neodesláno: N** umožní rychle najít, co je potřeba poslat znovu.

> ⚠️ „Odesláno" znamená, že e-mail **převzal SMTP server** — nezaručuje doručení
> do schránky (odmítnutí mailserverem příjemce / spam filtr aplikace netrackuje).
> Pokud cron upomínku **přeskočí** kvůli předpokladům z § 25.1 (např. klient nemá
> e-mail), nejde o „selhání odeslání" a v přehledu se jako chyba neobjeví.

## 25.9 Zákonný úrok z prodlení

Aplikace umí spočítat zákonný úrok z prodlení u faktur po splatnosti, uvést ho
v upomínce a vyúčtovat ho **samostatným dokladem**. Funkce je **ve výchozím
stavu vypnutá** — musíš ji zapnout sám.

### 25.9.1 Zapnutí a přepsání

Platí první nastavená hodnota v pořadí **faktura → klient → dodavatel**:

| Úroveň | Kde | Hodnoty |
|---|---|---|
| Dodavatel | **Nastavení → Dodavatel → Počítat úrok z prodlení** (vedle automatických upomínek) | zapnuto / vypnuto (výchozí) |
| Klient | Úprava klienta, volba **Úrok z prodlení** | podle dodavatele / zapnuto / vypnuto |
| Faktura | Detail faktury po splatnosti, karta **Úrok z prodlení**, volba **Úrok z prodlení u této faktury** | podle klienta a dodavatele / zapnout / vypnout |

Díky tomu můžeš úrok zapnout jen pro jednoho klienta nebo jedinou fakturu, i když
je globálně vypnutý, a naopak.

Po zapnutí u dodavatele nabídne nastavení ještě dvě volby:

- **Uvádět naběhlý úrok v upomínkách** (výchozí zapnuto),
- **Uplatnit i paušální náklady spojené s uplatněním pohledávky** (výchozí vypnuto,
  viz [§ 25.9.5](#2595-pausalni-naklady-spojene-s-uplatnenim-pohledavky)).

Úrok se počítá jen u běžné faktury (ne u zálohové faktury, dobropisu ani stornovacího
dokladu) ve stavu vystavená, odeslaná, upomenutá nebo zaplacená. **Z dokladu na úrok
z prodlení se další úrok nepočítá** — úrok z úroků lze požadovat jen tehdy, když to bylo
ujednáno (§ 1806 občanského zákoníku).

### 25.9.2 Jak se úrok počítá

Podkladem je § 1970 občanského zákoníku a § 2 nařízení vlády č. 351/2013 Sb.

- **Roční sazba** = dvoutýdenní repo sazba ČNB platná **první den kalendářního
  pololetí, ve kterém prodlení vzniklo**, zvýšená o **8 procentních bodů**.
- Prodlení vzniká **dnem následujícím po splatnosti**. Sazba se určí jednou a platí
  **po celou dobu prodlení** — nemění se ani s další změnou sazby ČNB, ani přechodem do
  dalšího pololetí.
- Úrok se počítá **z dosud neuhrazené částky** za skutečný počet dní. Každý den se
  počítá z částky, která byla nezaplacená na jeho začátku: den platby se ještě počítá
  ze zůstatku před platbou, od dalšího dne se úrok počítá z nižšího zůstatku. Nezaplacená
  faktura tak narůstá o stejný počet dní, jaký ukazuje upomínka jako „po splatnosti“.
- Roční sazba se dělí **skutečným počtem dní kalendářního roku (365, v přestupném roce
  366)**. Prodlení přes Nový rok se na hranici roku rozdělí. Nařízení vlády způsob přepočtu
  na dny nestanoví; jde o obvyklou praxi.
- Úrok se počítá **v měně faktury**, bez přepočtu. Každý úsek (stejný zůstatek v jednom
  roce) se zaokrouhlí na haléře a celkový úrok je součet úseků.
- Výpočet funguje pro prodlení vzniklé od 1. 1. 2014 (od tohoto dne platí nařízení vlády
  č. 351/2013 Sb.).

Příklad: faktura 10 000 Kč, splatná 15. 9. 2026. Prodlení vzniklo 16. 9. 2026, v pololetí
od 1. 7. 2026 platí repo sazba 3,75 %, roční sazba je tedy 11,75 %. Ke 3. 11. 2026 (49 dní)
je úrok 10 000 × 11,75 % × 49 / 365 = **157,74 Kč**.

Naběhlý úrok vidíš na kartě **Úrok z prodlení** v detailu faktury po splatnosti: částku,
dny prodlení, složení sazby, rozpis po úsecích a už vyúčtované období.

### 25.9.3 Upomínky s úrokem

Je-li úrok zapnutý a volba **Uvádět naběhlý úrok v upomínkách** také, upomínka (e-mail
v češtině i angličtině) uvede:

- naběhlý úrok ke dni odeslání,
- roční sazbu, repo sazbu ČNB, den, ke kterému platí, a právní základ,
- případně paušální náklady (viz § 25.9.5).

Částka **K úhradě** a QR platba zůstávají beze změny — úrok se uvádí zvlášť. Příloha
upomínky je původní faktura v PDF, ta se nemění.

Je-li funkce vypnutá (u dodavatele, klienta nebo faktury), je upomínka **přesně stejná
jako dřív**. Upravil-li jsi šablonu upomínky v **Systém → E-mail šablony**, úrok se v ní
neobjeví, dokud do ní nepřidáš blok s proměnnou `late_interest` (viz § 25.5.1).

Pokud číselník repo sazeb není aktuální (viz § 25.9.6), upomínka úrok neuvede.

### 25.9.4 Vyúčtování úroku z prodlení

1. Otevři detail faktury po splatnosti (nebo faktury zaplacené po splatnosti).
2. Na kartě **Úrok z prodlení** zkontroluj částku a rozpis.
3. Klikni na **Vyúčtovat úrok z prodlení** a potvrď.

**Jak poznáš, že je hotovo:** otevře se **koncept** nové faktury stejnému klientovi.
Má řádek (u více úseků více řádků) s úrokem a případně samostatný řádek s paušálními
náklady. Doklad **se sám nikdy nevystaví ani neodešle** — zkontroluj ho, vystav a odešli
jako každou jinou fakturu.

- V poznámkách je číslo původní faktury, období, roční sazba a právní základ.
- Opakované vyúčtování počítá jen **dny po dříve vyúčtovaném období**; paušální náklady se
  účtují jen jednou. Smažeš-li koncept nebo doklad stornuješ, období se uvolní.
- U faktury v cizí měně je doklad ve stejné měně a paušální náklady (1 200 Kč) se
  přepočtou kurzem ČNB platným v den vytvoření.

### 25.9.5 Paušální náklady spojené s uplatněním pohledávky

Mezi podnikateli má věřitel podle § 3 nařízení vlády č. 351/2013 Sb. a § 1972 občanského
zákoníku vedle úroku nárok na **minimálně 1 200 Kč za každou pohledávku**. Aplikace je
účtuje, jen když:

- je zapnuto **Uplatnit i paušální náklady** v nastavení,
- má klient vyplněné **IČO, DIČ nebo daňové číslo** (soukromá osoba je nemá a paušál se jí
  neúčtuje),
- nebyly pro danou fakturu už vyúčtovány.

Jde o minimální částku; vyšší prokázané náklady aplikace neřeší.

### 25.9.6 Číselník repo sazby ČNB

Sazby jsou v **Systém → Číselníky → Repo sazba ČNB**. Číselník obsahuje historii od
roku 2012 a je **jen k přidávání**: existující řádky se nikdy nepřepisují, aby bylo možné
dřívější výpočty zopakovat.

- **Načíst z ČNB** (jen administrátor) stáhne oficiální soubor ČNB a doplní nové sazby.
  Rozcházející se starší řádky nepřepíše, jen na ně upozorní.
- Novou sazbu lze zadat i ručně — musí platit od data pozdějšího než poslední řádek. Smazat
  lze jen ručně zadané řádky.
- Číselník si pamatuje, **k jakému dni je ověřený**. Začalo-li prodlení v pololetí, které
  začalo až po tomto dni, nelze sazbě věřit: upomínky úrok neuvedou a vyúčtování se
  zablokuje, dokud číselník nenačteš z ČNB.

Po každém zasedání bankovní rady ČNB, které změní sazbu, číselník aktualizuj.

### 25.9.7 DPH a daně

Úrok z prodlení a náhrada nákladů spojených s uplatněním pohledávky nejsou úplatou za
zdanitelné plnění, proto jsou **mimo předmět DPH** (§ 2 zákona o DPH). Doklad na úrok
proto:

- má všechny řádky se sazbou 0 % a klasifikací **Mimo předmět daně**, takže se nedostane
  do Knihy DPH, přiznání ani kontrolního hlášení a nevyvolá upozornění na neklasifikovaný
  řádek ani na nulovou sazbu u plátce,
- obsahuje v poznámce pod položkami větu, že jde o plnění mimo předmět DPH a že doklad není
  daňovým dokladem,
- je takový, i když je dodavatel plátcem DPH.

Zdanění přijatého úroku v daních z příjmů aplikace neřeší.

### 25.9.8 Co funkce nedělá

- Nepočítá smluvní úrok z prodlení ani smluvní pokuty — jen zákonný úrok.
- Nehlídá zánik práva na další úrok, když úroky dosáhnou výše jistiny a věřitel bez
  rozumného důvodu otálel s uplatněním pohledávky (§ 1805 odst. 2 občanského zákoníku).
- Nepočítá prodlení vzniklé před 1. 1. 2014.
