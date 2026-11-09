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
| **Hotovo** | Je hotové — buď to aplikace sama zjistila (*automaticky*), nebo jsi to označil ručně. |
| **Po termínu** | Termín uplynul a aplikace vidí, že hotovo není. |
| **Do 7 dnů** | Termín se blíží. |
| **Nadcházející** | Termín je později. |
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

Všechno ostatní (zálohy na pojistné, přehledy pro ČSSZ a pojišťovnu, přihláška
k registraci k DPH, zálohy na daň, zálohy na paušální daň…) aplikace **nesleduje**. Tyhle
položky odškrtneš políčkem **Hotovo**. Políčko jde použít i u automatických položek —
například když jsi přiznání podal mimo aplikaci. U položky pak stojí, jestli je hotová
*automaticky* (aplikace to ví), nebo *ručně* (kdo to označil a kdy). Automatický důkaz
má vždy přednost.

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
| **Paušální režim za rok neplatí** | 31. 12. roku, ve kterém příjmy zaplacené v roce přesáhly limit, nebo ses stal plátcem DPH. |
| **Záloha na paušální daň** | 20. den v měsíci, posunutý na pracovní den. Jen v roce s paušálním pásmem. |
| **Zálohy na daň z příjmů** (§ 38a ZDP) | Podle poslední známé daně: nad 150 000 Kč čtvrtletně po 25 % (15. 3., 15. 6., 15. 9., 15. 12.), nad 30 000 Kč dvakrát ročně po 40 % (15. 6., 15. 12.). Bez daňového profilu uzavřeného roku jen připomínka. |
| **Podklady k odpočtům a slevám** | Doporučení k 15. únoru: sesbírat potvrzení (hypotéka, penzijko, životko, dary, děti) — jen když to daňový profil roku uvádí. |
| **Přehled pro ČSSZ a pro zdravotní pojišťovnu** | Měsíc po termínu přiznání k dani z příjmů, posunutý na pracovní den. Jen za roky bez paušálního režimu. |
| **Doplatek pojistného** | 8 dnů po podání přehledu (v harmonogramu počítáno od posledního dne lhůty). Jen informace. |
| **Zálohy na sociální a zdravotní pojištění** | Za každý měsíc roku bez paušálního režimu: sociální do 20., zdravotní do 8. dne **následujícího** měsíce. |
| **Vystavit faktury za měsíc** | Doporučení: 5. pracovní den dalšího měsíce. Jen pro měsíce od první faktury. |
| **Vlastní úkoly** | Termín si určíš sám. |

### Když paušální režim neplatí

Jestli jsou ve zvoleném roce **příjmy zaplacené v roce** (stejný základ, jaký hlídá
dashboard) nad limitem, nebo ses v průběhu roku stal plátcem DPH, paušální režim za
daný rok neplatí. Harmonogram to ukáže jako upozornění a pro ten rok začne počítat
s **řádným přiznáním k dani z příjmů** a **přehledy**. Zaplacené paušální zálohy se
započítají. Zálohy na paušální daň po překročení limitu zůstávají na ose s poznámkou
„ověřit, zda pokračovat v platbě" — aplikace tady nic nevymýšlí. Měsíční zálohy na
pojistné se počítají od dalšího roku; za měsíce po vystoupení z režimu v témže roce si
výši záloh ověř u ČSSZ a pojišťovny.

### Přihláška k registraci k DPH

U neplátce aplikace sleduje obrat za kalendářní rok podle data zdanitelného plnění
vystavených faktur. Po překročení 2 000 000 Kč zobrazí položku s termínem *10 pracovních
dnů od překročení*. Přihlášku podej přes **Online finanční úřad (DIS+)** nebo EPO. V poli 06
zvol **§ 6 odst. 1** (plátcem od 1. ledna příštího roku), **ne** § 6 odst. 2 písm. a), které
by tě udělalo plátcem už od dalšího dne. Uveď číslo podnikatelského bankovního účtu.
Nezapomeň, že plátcovství od 1. 1. musí být i v **Nastavení → Plátcovství DPH** — jinak ti
položka na to upozorní.

## Vlastní úkoly

Tlačítkem **Přidat vlastní úkol** založíš jednorázovou věc, kterou aplikace nezná (např.
„Oznámit klientům DPH a nový účet"). Zadáš název, termín, volitelně začátek okna,
kategorii, poznámku a odkaz (cestu v aplikaci, např. `/clients`, nebo adresu http/https).
U vlastního úkolu je políčko **Hotovo**, **Upravit** a **Smazat**.

## Na co si dát pozor

- Částky označené **odhad** nejsou závazné. Záloha na pojistné je **zákonné minimum**
  daného roku z daňových konstant (pro rok, který v konstantách ještě není, se použije
  poslední známý a položka to říká); skutečná záloha vychází z posledního přehledu. Záloha
  na daň z příjmů je odhad z daňového optimalizátoru.
- Za minulé roky bez uloženého **daňového profilu** aplikace nepozná, že jsi byl v paušálním
  režimu, a počítá je jako běžné roky. Chceš-li přesný harmonogram i pro minulost, ulož pro
  daný rok profil v Daňovém optimalizátoru.
- Data splatnosti pojistného se neposouvají (nepracovní den jen upozorní): platba musí být na účtu včas.
- Harmonogram je pomůcka, ne daňové poradenství. Pravidla se mění; u poznámek „ověřit"
  se spoleh na úřad, pojišťovnu nebo účetní.

## Přístup přes API

Harmonogram je dostupný jen z přihlášené relace (admin, účetní); API tokeny k němu přístup
nemají. Popis endpointů najdeš v [REST API](41_API.md) / `openapi.yaml` pod štítkem *Timeline*.
