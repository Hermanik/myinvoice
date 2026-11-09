# 43. Harmonogram

**Harmonogram** je časová osa všeho, co musíš (nebo bys měl) udělat pro daně, pojištění
a fakturaci: termíny, co je už hotové a co ještě ne. Je psaný tak, aby ses podle něj
mohl řídit **sám bez účetní** — a stejně tak ho můžeš použít jako kontrolní seznam,
když účetní máš: uvidíš, co by mělo být podané a zaplacené, a v jakém termínu.

Stránku najdeš v menu **Daně → Harmonogram** (hvězdičkou ji můžeš přidat do
oblíbených). Vidí ji **administrátor a účetní**; role jen pro čtení ji nemá.

Harmonogram se **nepíše ručně** — počítá se z nastavení a dat firmy: z historie
plátcovství DPH (Nastavení → Plátcovství DPH), z daňového profilu roku (Daně →
Daňový optimalizátor), z vystavených faktur a z archivu podání. Pro každého dodavatele
tedy platí stejná pravidla; jiná firma uvidí jiné položky.

Nahoře jsou dvě tlačítka: **Pravidelné platby** (trvalé příkazy a nastavení harmonogramu,
viz níže) a **Přidat vlastní úkol**. Pokud máš rok bez paušálního režimu, nad osou je
blok **Odhad za rok …** s doplatky, které bude potřeba zaplatit.

## Období a filtry

Nahoře zvolíš období (**Od – Do**) nebo jednu z předvoleb:

| Předvolba | Období |
|---|---|
| **Zbytek roku + příští rok** (výchozí) | od 30 dní zpět do 31. 12. příštího roku |
| **Příštích 90 dní** | od dneška |
| **Tento rok** | celý letošní kalendářní rok |
| **Příští rok** | celý příští kalendářní rok |

Výchozí období začíná 30 dní zpět záměrně — hned uvidíš, co se právě nestihlo.
Změna období načte data jedním dotazem. Období může mít nejvýše 5 let.

Filtry **Stav** (Vše / K udělání / Hotové / Po termínu) a **Kategorie** (DPH, Daň
z příjmů, Pojištění, Paušální daň, Registrace, Fakturace, Vlastní) pracují nad
načtenými položkami, takže přepínání je okamžité. U každého filtru vidíš počet položek.

## Jak číst osu

Položky jsou seřazené podle termínu a seskupené po měsících. Červená čára **Dnes**
ukazuje, kde na ose právě jsi. Každá karta má:

- datum s dnem v týdnu a počtem dní do termínu (nebo po termínu),
- název, stav a kategorii,
- **Co udělat** — krátký popis a odkaz **do aplikace**, kde to uděláš,
- případnou **částku** a u odhadů slovo „odhad",
- **právní základ** malým písmem,
- poznámky s upozorněním „ověřit", kde aplikace nemá jistá data.

### Stavy

| Stav | Význam |
|---|---|
| **Hotovo** | Je hotové — buď to aplikace sama zjistila (*automaticky*), zaplatil to **trvalý příkaz** (viz Pravidelné platby), nebo jsi to označil ručně. |
| **Po termínu** | Termín uplynul a aplikace vidí, že hotovo není. |
| **Do 7 dnů** | Termín se blíží. |
| **Nadcházející** | Termín je později. Takto se ukazuje i položka, kterou teprve zaplatí trvalý příkaz — i když je termín za pár dní. |
| **Informace** | Jen upozornění (např. že paušální režim neplatí); nikdy není „po termínu". |
| **Nepotvrzeno** | Termín je víc než 30 dní pryč a aplikace nemá jak zjistit, zda to bylo hotové. Netvrdí, že se to nestihlo — jen tě žádá o potvrzení. |

## Co je hotové automaticky a co ručně

Automaticky aplikace pozná:

- **podané přiznání, kontrolní a souhrnné hlášení** — z **Archivu podání** (položka je hotová,
  jakmile výkaz označíš jako podaný),
- **zaplacené DPH** — když je přiznání podané a platba zaznamenaná (ručně v Archivu podání
  nebo rozpoznaná z bankovního výpisu),
- **vystavení faktur za měsíc** — hotovo, jakmile existuje vystavená faktura s datem
  zdanitelného plnění v daném měsíci.

Zálohy placené **trvalým příkazem** pozná aplikace podle toho, že trvalý příkaz zadáš
(viz Pravidelné platby). Všechno ostatní (zálohy na pojistné a na paušální daň, které
platíš ručně, přehledy pro ČSSZ a pojišťovnu, přihláška k registraci k DPH, zálohy na
daň…) aplikace **nesleduje**. Tyhle položky odškrtneš políčkem **Hotovo**. Políčko jde použít i u automatických položek —
například když jsi přiznání podal mimo aplikaci. U položky pak stojí, jestli je hotová
*automaticky* (aplikace to ví), *trvalým příkazem* (placeno v den platby příkazu) nebo
*ručně* (kdo to označil a kdy). Automatický důkaz má přednost před trvalým příkazem
a ten před ruční značkou.

K libovolné položce můžeš přidat **Poznámku** (např. z jakého účtu a kdy jsi platil).
Poznámka sama o sobě položku nedokončí.

## Co v harmonogramu najdeš

| Položka | Termín |
|---|---|
| **Přiznání k DPH, kontrolní hlášení, souhrnné hlášení** | 25. den po období, posunutý na pracovní den. Jen za období, kdy jsi byl plátcem (nebo identifikovanou osobou). |
| **Platba DPH** | Stejný den jako přiznání. Částka se ukáže po podání přiznání. |
| **Přihláška k registraci k DPH** | 10 pracovních dnů po dni, kdy obrat za kalendářní rok přesáhl 2 000 000 Kč (podle data zdanitelného plnění). Plátcem se pak staneš od 1. ledna dalšího roku. Viz níže. |
| **Odhad registrace** | Pokud už máš v historii plátcovství DPH plátce od 1. 1. příštího roku, ale limit ještě nepadl: ukáže se, kolik do limitu zbývá, a odhad termínu podle tempa letošního roku. |
| **Upozornění na obrat nad 2 536 500 Kč** | Plátcem jsi už od následujícího dne (§ 6 odst. 2 zákona o DPH). |
| **Přiznání k dani z příjmů** | Elektronicky 1. 5. následujícího roku (posunuté na pracovní den); papírově 1. 4.; s daňovým poradcem 1. 7. |
| **Paušální režim za rok neplatí** | 31. 12. roku, ve kterém příjmy zaplacené v roce přesáhly limit, nebo ses stal plátcem DPH. Ukazuje odhad daně a pojistného (viz níže). |
| **Záloha na paušální daň** | 20. den v měsíci, posunutý na pracovní den. Jen v roce s paušálním pásmem. |
| **Zálohy na daň z příjmů** (§ 38a ZDP) | Podle daně za předchozí rok: nad 150 000 Kč čtvrtletně po 25 % (15. 3., 15. 6., 15. 9., 15. 12.), nad 30 000 Kč dvakrát ročně po 40 % (15. 6., 15. 12.). Období záloh začíná dnem po termínu přiznání. Daň je skutečná (uzavřený rok s daňovým profilem), nebo odhad z letošní projekce; jinak jen připomínka. |
| **Podklady k odpočtům a slevám** | Doporučení k 15. únoru: sesbírat potvrzení (hypotéka, penzijko, životko, dary, děti) — jen když to daňový profil roku uvádí. |
| **Přehled pro ČSSZ a pro zdravotní pojišťovnu** | Měsíc po termínu přiznání k dani z příjmů, posunutý na pracovní den. Jen za roky bez paušálního režimu. U roku s odhadem je částkou očekávaný doplatek. |
| **Doplatek pojistného** | 8 dnů po podání přehledu (v harmonogramu počítáno od posledního dne lhůty). Jen informace; u roku s odhadem s očekávaným doplatkem. |
| **Zálohy na sociální a zdravotní pojištění** | Za každý měsíc roku bez paušálního režimu: sociální do 20., zdravotní do 8. dne **následujícího** měsíce. Minimum roku; po termínu přehledů za předchozí rok (s odhadem) jedna dvanáctina pojistného za ten rok. |
| **Vystavit faktury za měsíc** | Doporučení. Výchozí termín je **poslední den měsíce**; v nastavení jde změnit na N. pracovní den následujícího měsíce. Jen pro měsíce od první faktury. Za měsíce, kdy jsi plátce DPH, upozornění na lhůtu 15 dnů na daňový doklad. |
| **Vlastní úkoly** | Termín si určíš sám. |

### Když paušální režim neplatí

Jestli jsou ve zvoleném roce **příjmy zaplacené v roce** (stejný základ, jaký hlídá
dashboard) nad limitem pásma, nebo ses v průběhu roku stal plátcem DPH, paušální režim
za **celý** daný rok neplatí. Harmonogram to ukáže jako upozornění a pro ten rok začne
počítat s **řádným přiznáním k dani z příjmů** (výdaje paušálem, typicky 60 % se
stropem 1 200 000 Kč, nebo skutečné) a s **přehledy** pro ČSSZ a zdravotní pojišťovnu.

- **Zálohy na paušální daň plať dál až do konce roku.** U zálohy za takový rok stojí:
  „Paušální režim za {rok} neplatí – zálohy plaťte dál, v přiznání a přehledech se
  započtou."
- Zaplacené paušální zálohy se **započtou**: **daňová část** (100 Kč měsíčně) na daň
  z příjmů, **sociální část** na sociální pojistné a **zdravotní část** na zdravotní
  pojistné. Co se nezapočte, je doplatek (viz Odhad), případný přeplatek se vrací.
- Měsíční zálohy na pojistné na **další rok** se po podání přehledů řídí jejich výsledkem
  (viz Odhad).

### Odhad za rok bez paušálního režimu

Pro rok, který není v paušálním režimu (ztratil jsi ho, nebo je to řádný rok),
harmonogram odhadne daň z příjmů, sociální a zdravotní pojistné a doplatky. Všechny
částky jsou **odhad v celých Kč** a jsou tak označené.

**Příjem** je:

- u **uzavřeného roku** příjem skutečně zaplacený v roce (jen s uloženým daňovým profilem
  roku, jinak by aplikace jen hádala výdaje a odpočty),
- u **letošního roku** to, co už bylo zaplaceno, **plus** vystavené nezaplacené faktury
  (stav vystavená / odeslaná / upomínaná), jejichž **splatnost spadá do stejného roku** —
  u nich se předpokládá úhrada letos. Faktura splatná příští rok se nepočítá, stejně jako
  koncept nebo stornovaná.

**Daň a pojistné** vypočítá daňový optimalizátor (řádný režim) z daňového profilu roku. Když
profil roku není uložený (jen u letošního roku), počítá se s 60% výdajovým paušálem a bez
odpočtů a odhad to říká.

**Započtené zálohy**: u roku, který ztratil paušální režim, součet měsíčních paušálních
záloh roku podle pásma a podle sazby platné v daném měsíci (u 1. pásma 2026 je to 9 984 Kč
do června a 9 162 Kč od 1. 7.). Záloha se rozloží na tři části:

| Část | Výpočet |
|---|---|
| **Daň** | 100 Kč |
| **Zdravotní** | větší z minimální zálohy roku (minimální vyměřovací základ / 12 × sazba, nahoru) a podílu zbytku, který připadá na zdravotní pojištění (sazba × 50 % základu proti sazbě sociálního × 55 %, tedy asi 29,6 %) |
| **Sociální** | zbytek |

Konstanty roku neobsahují přesné rozdělení, které zveřejňuje finanční správa, proto jde
o odvozený odhad (u 1. pásma je zdravotní část přesně minimum). U řádného roku se počítá
s dvanácti minimálními zálohami na pojistné a bez záloh na daň — skutečně zaplacené
zálohy si ověř.

**Doplatek** = očekávaná daň (pojistné) − započtené zálohy. Záporný doplatek je přeplatek.
Termíny: daň do termínu přiznání, pojistné 8 dnů po termínu přehledů.

Odhad najdeš na třech místech:

- **blok „Odhad za rok …"** nad osou — tři doplatky s termíny a součet „k uhrazení celkem",
  tedy kolik si dát stranou; zmizí, jakmile jsou všechny jeho termíny pryč,
- **karta „Paušální režim za rok neplatí"** — celý rozpis: příjem, očekáváno, započteno,
  doplatek a termín,
- **částky na položkách** — u přiznání k dani z příjmů očekávaný doplatek daně, u přehledů
  pro ČSSZ a pojišťovnu a u jejich doplatků očekávaný doplatek pojistného (přeplatek se
  píše poznámkou, částka je pak nulová).

**Navazující platby se odvozují z odhadu:**

- **Zálohy na pojistné** na další rok jsou do měsíce termínu přehledů minimum, od
  **následujícího měsíce** jedna dvanáctina (nahoru na celé Kč) odhadnutého sociálního
  a zdravotního pojistného za rok (poznámka „odhad").
- **Zálohy na daň z příjmů** se počítají z odhadnuté daně roku: nad 150 000 Kč čtvrtletně
  po 25 % (15. 3., 15. 6., 15. 9., 15. 12.), nad 30 000 Kč dvakrát ročně po 40 % (15. 6.,
  15. 12.), posunuté na pracovní den. Období záloh začíná dnem po termínu přiznání za rok.

### Přihláška k registraci k DPH

U neplátce aplikace sleduje obrat za kalendářní rok podle data zdanitelného plnění
vystavených faktur. Po překročení 2 000 000 Kč zobrazí položku s termínem *10 pracovních
dnů od překročení*. Přihlášku podej přes **Online finanční úřad (DIS+)** nebo EPO. V poli 06
zvol **§ 6 odst. 1** (plátcem od 1. ledna příštího roku), **ne** § 6 odst. 2 písm. a), které
by tě udělalo plátcem už od dalšího dne. Uveď číslo podnikatelského bankovního účtu.
Nezapomeň, že plátcovství od 1. 1. musí být i v **Nastavení → Plátcovství DPH** — jinak ti
položka na to upozorní.

## Pravidelné platby (trvalé příkazy)

Aplikace nevidí tvůj bankovní účet. Platíš-li zálohy **trvalým příkazem** (např. paušální
daň každého 12. v měsíci), bez další informace by se platby v harmonogramu jevily jako
po termínu. Tlačítkem **Pravidelné platby** otevřeš okno, kde trvalé příkazy zadáš:

| Pole | Význam |
|---|---|
| **Druh platby** | Paušální daň · Sociální pojištění – záloha · Zdravotní pojištění – záloha · Záloha na daň z příjmů |
| **Částka** | kolik příkaz platí |
| **Den v měsíci** | 1–31; v kratším měsíci se platí poslední den měsíce (31. v únoru je 28. nebo 29.) |
| **Platí od / do** | doba platnosti příkazu; „do" je volitelné |
| **Poznámka** | např. z jakého účtu |

**Jak se příkaz přiřadí k položce.** Platba příkazu je den v měsíci příkazu v nejpozdějším
měsíci, ve kterém tento den **nepřipadá po termínu položky** a je nejvýše 31 dnů před ním
(záloha na paušální daň je do 20., příkaz na 12. ji platí 12. téhož měsíce; zdravotní
záloha je do 8., příkaz na 12. ji zaplatil 12. předchozího měsíce). Příkaz musí v den
platby platit (v rozmezí od–do). Je-li takových příkazů víc, vyhrává ten s pozdějším
začátkem platnosti.

- Den platby **už uplynul** → položka je **Hotovo**, zdroj „trvalým příkazem", a karta
  místo políčka ukáže „Placeno trvalým příkazem 12. 10.".
- Den platby **teprve přijde** → položka je **Nadcházející** s poznámkou „Zaplatí trvalý
  příkaz …".
- **Částka příkazu je vyšší** než splatná záloha → poznámka s **přeplatkem** za měsíc
  (započte se, nebo si ho lze nechat vrátit). Například příkaz 9 984 Kč na paušální daň od
  července 2026, kdy je 1. pásmo 9 162 Kč, dává přeplatek 822 Kč měsíčně.
- **Částka příkazu je nižší** než splatná záloha → upozornění na **nedoplatek** za měsíc.

U roku, který ztratil paušální režim, blok odhadu navíc ukáže, kolik jsi trvalým příkazem
zaplatil a kolik z toho je nad splatnou zálohu.

### Nastavení harmonogramu

Ve stejném okně je sekce **Nastavení harmonogramu**. **Termín vystavení faktur za měsíc**
je buď **poslední den měsíce** (výchozí — faktury se vystavují poslední den měsíce a datum
zdanitelného plnění je poslední den), nebo **N. pracovní den následujícího měsíce**
(N od 1 do 20, výchozí 5). Nastavení platí pro dodavatele a mění termín položek
„Vystavit faktury za měsíc".

## Vlastní úkoly

Tlačítkem **Přidat vlastní úkol** založíš jednorázovou věc, kterou aplikace nezná (např.
„Oznámit klientům DPH a nový účet"). Zadáš název, termín, volitelně začátek okna,
kategorii, poznámku a odkaz (cestu v aplikaci, např. `/clients`, nebo adresu http/https).
U vlastního úkolu je políčko **Hotovo**, **Upravit** a **Smazat**.

## Na co si dát pozor

- Částky označené **odhad** nejsou závazné. Záloha na pojistné je **zákonné minimum**
  daného roku z daňových konstant (pro rok, který v konstantách ještě není, se použije
  poslední známý a položka to říká); skutečná záloha vychází z posledního přehledu. Záloha
  na daň z příjmů a doplatky za rok bez paušálního režimu jsou odhad z daňového
  optimalizátoru (viz Odhad za rok bez paušálního režimu).
- Za minulé roky bez uloženého **daňového profilu** aplikace nepozná, že jsi byl v paušálním
  režimu, a počítá je jako běžné roky. Chceš-li přesný harmonogram i pro minulost, ulož pro
  daný rok profil v Daňovém optimalizátoru.
- Data splatnosti pojistného se neposouvají (nepracovní den jen upozorní): platba musí být na účtu včas.
- Harmonogram je pomůcka, ne daňové poradenství. Pravidla se mění; u poznámek „ověřit"
  se spoleh na úřad, pojišťovnu nebo účetní.

## Přístup přes API

Harmonogram je dostupný jen z přihlášené relace (admin, účetní); API tokeny k němu přístup
nemají. Kromě čtení osy a značek jsou tu endpointy trvalých příkazů
(`/api/timeline/standing-orders`) a nastavení (`/api/timeline/settings`). Popis endpointů
najdeš v [REST API](41_API.md) / `openapi.yaml` pod štítkem *Timeline*.
