# 42. Datové schránky

Aplikace umí pracovat s tvými **datovými schránkami** (ISDS): hlídá nové zprávy
(hlavně od finančního úřadu), dovolí ti zprávu otevřít a stáhnout, poslat novou
zprávu a odeslat **daňové podání (XML z archivu výkazů) přímo do datové schránky
finančního úřadu**. Podporuje víc schránek najednou — typicky osobní (**FO**)
a podnikatelskou (**PFO**).

Stránku najdeš v menu **Dokumenty → Datové schránky**, přístupy se nastavují
v **Systém → Nastavení → Datové schránky**. Vidí a ovládá je jen **administrátor**.

> [!WARNING]
> **Doručení zprávy je právní úkon.** Datová zpráva se považuje za **doručenou**
> v okamžiku, kdy se do schránky přihlásí osoba, která ji smí číst — od té chvíle
> běží lhůty (např. na odpověď finančnímu úřadu). Nepřihlásí-li se nikdo, doručí se
> zpráva sama po **10 dnech** (doručení fikcí). Aplikace je navržená tak, aby
> **zprávu nikdy nedoručila sama**: průběžná synchronizace smí jen **číst seznamy**
> a každé otevření zprávy je tvoje výslovná akce s varováním.

## Jak to funguje (a proč dva typy přihlášení)

U externí aplikace platí podle Provozního řádu ISDS zvláštní pravidlo: **stažení
seznamu došlých zpráv se počítá jako přihlášení do schránky**. Uživatel, který smí
zprávy číst, by je tím při každé synchronizaci **doručil**. Uživatel, který smí jen
„Zobrazovat seznamy, historii a doručenky", je naopak vidí (odesílatel, věc,
datum, stav), ale **nedoručí** je — specifikace webových služeb to výslovně uvádí.

Proto aplikace pracuje s až **dvěma přihlášeními ke každé schránce**:

| Přihlášení | K čemu | Doporučená práva v ISDS |
|---|---|---|
| **Pro synchronizaci** (povinné) | Hodinová kontrola nových zpráv na pozadí. | **Jen** „Zobrazovat seznamy, historii a doručenky". |
| **Pro akce** (volitelné) | Odeslání zprávy, vyhledání příjemce, otevření zprávy po tvém kliknutí. | „Posílat zprávy a číst odeslané" + „Vyhledávat schránky" + „Zobrazovat seznamy…"; pro čtení v aplikaci navíc „Číst přijaté zprávy". |

Podle toho, jaká práva uživatelům v ISDS dáš, máš tři úrovně:

1. **Jen hlídání** — pouze přihlášení pro synchronizaci. Aplikace ukazuje, co přišlo
   a odešlo, a upozorní tě na dashboardu. Zprávy čteš v oficiálním portálu. *Nikdy nic nedoručí.*
2. **Hlídání + odesílání** — druhé přihlášení bez práva číst. Můžeš z aplikace odesílat
   zprávy a daňová podání. *Stále nic nedoručí.*
3. **Hlídání + odesílání + čtení** — druhé přihlášení smí i číst přijaté zprávy. Zprávu
   otevřeš v aplikaci, ale **jen po výslovném potvrzení**, že ji tím můžeš doručit.

Pokud k synchronizaci zadáš přihlášení, které zprávy číst **smí** (například přístup
vlastníka schránky), aplikace s ním **synchronizovat odmítne** — dokud to ve schránce
výslovně nepovolíš (volba *Povolit doručování synchronizací*, viz níže).

## Založení přístupu v portálu ISDS (krok za krokem)

Postup udělej **pro každou schránku zvlášť** (osobní i podnikatelskou). Do webových
služeb se **nejde přihlásit bankovní identitou (NIA)** ani mobilním klíčem, proto pro
aplikaci založíš vlastního uživatele se jménem a heslem. Založit ho smí vlastník
schránky nebo její administrátor.

1. Přihlas se do portálu datových schránek na **https://www.datovka.gov.cz** (jako
   obvykle bankovní identitou) a vstup do schránky, kterou chceš připojit.
2. Otevři **Nastavení → Uživatelé** a vlevo klikni na **Nový uživatel**.
3. Ve formuláři **Přidání uživatele** vyplň údaje osoby, která účet používá (jsi to ty),
   a zvol **Typ oprávnění: Pověřená osoba** (ne Administrátor).
4. V části **Adresa pro doručení přístupových údajů** zvol, kam mají přístupové údaje
   dorazit. Má-li osoba vlastní schránku fyzické osoby a je ztotožněná v registru
   obyvatel, přijdou údaje přímo do této schránky jako zpráva do vlastních rukou,
   jinak poštou jako dopis do vlastních rukou.
5. **Zaškrtni jen potřebná práva** (názvy odpovídají zaškrtávacím polím v portálu):
   - **uživatel pro synchronizaci:** *pouze* **Zobrazovat seznamy, historii a doručenky**.
     **Nezaškrtávej** „Číst přijaté zprávy" ani „Číst přijaté zprávy do vlastních rukou";
   - **uživatel pro akce (volitelný):** **Posílat zprávy a číst odeslané** + **Vyhledávat
     schránky** + **Zobrazovat seznamy, historii a doručenky**; chceš-li zprávy číst
     i v aplikaci, přidej **Číst přijaté zprávy** (a „…do vlastních rukou" jen pokud
     potřebuješ číst i takto označené zprávy — u schránky FO portál při tomto právu
     varuje, protože do vlastních rukou chodí i zprávy s přístupovými údaji).
6. Klikni na **Přidat uživatele**, zkontroluj souhrn a potvrď tlačítkem **Vytvořit**.
   Do schránky přijde informační zpráva o provedené akci.
7. Až dorazí přístupové údaje, **přihlas se tímto novým účtem jednou v portálu**
   (jménem a heslem) a **změň si heslo** — první heslo je platné jen pro první
   přihlášení a bez změny hesla nefunguje ani webová služba. Heslo musí mít 8 až 64
   znaků, aspoň jedno velké písmeno, jedno malé písmeno a jednu číslici.
8. U nového uživatele **nezapínej** přihlašování SMS kódem, Mobilním klíčem ani
   certifikátem — aplikace se přihlašuje jen jménem a heslem.
9. **Platnost hesla:** ve výchozím nastavení heslo po **90 dnech vyprší** a webové
   služby se pak nepřihlásí. Buď si hlídej termín (aplikace ho u přihlášení ukazuje
   a upozorní týden před koncem), nebo u tohoto omezeného uživatele nastav
   **Nastavení → Možnosti přihlášení → Platnost hesla → Neomezená** (u uživatele,
   který smí jen číst seznamy, je to přijatelné riziko).
10. Zapamatuj si **uživatelské jméno** a **heslo** — v aplikaci je zadáš v dalším kroku.

> [!NOTE]
> Pokud ti portál nedovolí založit uživatele s tvými vlastními údaji do vlastní
> schránky, použij jiný postup vydání přístupu (poštou, Czech POINT) nebo zavolej na
> infolinku datových schránek **954 200 200**.

> [!WARNING]
> **Nikdy nezadávej do aplikace přístupové údaje vlastníka schránky** a aplikaci
> provozuj jen na serveru, který máš plně pod kontrolou. Provozní řád ISDS říká, že
> předání přístupových údajů aplikaci, která běží mimo zařízení pod tvou plnou
> kontrolou (cizí cloud, sdílená instalace), porušuje zákon č. 300/2008 Sb., § 9
> odst. 2, a správce ISDS může takové údaje zneplatnit. Samostatný uživatel
> s omezenými právy toto riziko výrazně snižuje.

## Připojení schránky v aplikaci

1. Otevři **Systém → Nastavení → Datové schránky** a klikni na **Přidat schránku**.
2. Zadej **přihlášení pro synchronizaci** (jméno a heslo uživatele jen se seznamy)
   a volitelně **přihlášení pro akce**. Prostředí nech na **Ostrý provoz** (testovací
   prostředí slouží jen vývojářům a nelze z něj posílat reálným úřadům).
3. Aplikace se přihlásí, **sama zjistí ID, typ a název schránky** podle přihlášení
   (nezadáváš je) a zjistí, jaká práva uživatel má. Heslo uloží **šifrovaně**
   a nikdy ho nevrací zpět.
4. U přihlášení uvidíš **indikátor oprávnění**:
   - **zelený — „Bezpečné pro synchronizaci"**: smí vypisovat seznamy a nesmí číst,
     takže synchronizace nic nedoručí;
   - **oranžový — „Doručuje"**: smí číst, synchronizace je zablokovaná;
   - **červený — „Nelze vypsat seznam"**: chybí právo „Zobrazovat seznamy…";
   - u přihlášení pro akce vidíš, jestli smí **odesílat**, **hledat** a **číst**.
5. Tlačítko **Ověřit připojení** kdykoliv znovu otestuje přihlášení (jen čtecí volání),
   obnoví zjištěná práva a datum vypršení hesla. Po změně práv v ISDS ho spusť —
   synchronizace si ale práva přečte sama **před každým během**, takže práva přidaná
   v portálu ji zastaví dřív, než by cokoliv doručila.

**Odmítnuté přihlášení se nezkouší znovu.** Jakmile systém datových schránek odmítne
heslo (špatné heslo, zamčený účet), aplikace si to pamatuje u přihlášení — a to při
**jakékoliv** akci (synchronizace, otevření zprávy, odeslání, načtení doručenky) — a
dokud ho neověříš, nepoužije ho. Další ruční pokus (**Ověřit připojení**, uložení nového
hesla) je možný až po čekací době: 5 minut, po každém dalším neúspěchu dvakrát déle
(nejvýše 24 hodin), u zamčeného účtu nejméně do konce zámku. Důvod: pár chybných pokusů
za sebou účet v ISDS zamkne.

**Povolit doručování synchronizací** je výslovná výjimka pro uživatele, který čte:
synchronizace pak zprávy **doručí hned po jejich příchodu** a lhůty začnou běžet bez
tvého vědomí. Zapínej jen, když to opravdu chceš (například když je schránka stejně
vždy čtena automaticky). Ve výchozím stavu je vypnutá.

**Vypnout** schránku zastaví synchronizaci a odeslání, ale zachová uložené zprávy.
**Odebrat** schránku smaže i uložené zprávy a doručenky — aplikace se před tím
výslovně zeptá.

## Synchronizace na pozadí

Úloha `cron-databox-sync` (hodinově, viz [Po instalaci a CLI](05_Po_instalaci.md))
stahuje **seznamy obálek** přijatých a odeslaných zpráv a ukládá je do aplikace: kdo
zprávu poslal, věc, datum dodání, stav. **Obsah ani přílohy nestahuje.**

- Při prvním běhu načte posledních 100 dnů, pak průběžně posledních 35 dnů.
- U odeslaných zpráv si ukládá **doručenku** (dodejku / doručenku včetně podepsané
  kopie), jakmile existuje.
- **Nový příchozí dopis** se objeví na dashboardu. Zpráva od **finančního úřadu** je
  zvýrazněná červeně; u výzvy ke kontrolnímu hlášení aplikace ukáže orientační
  lhůty podle § 101g zákona o DPH (viz níže).
- Když se **přihlášení nepodaří** (špatné heslo, zamčený účet), aplikace schránku
  **pozastaví** a nezkouší hesla dál — opakované chybné pokusy účet v ISDS zamknou.
  Na dashboardu se objeví upozornění; po opravě hesla a **Ověřit připojení** se
  synchronizace obnoví.
- **Každý běh začíná čtecím dotazem na práva** přihlášení (nic nedoručuje). Pokud mezitím
  v ISDS přibylo právo číst zprávy a doručování synchronizací není povolené, schránka se
  **pozastaví dřív, než načte jakýkoliv seznam**, a na dashboardu se objeví upozornění.
- Doručenka, kterou se nedaří načíst (například zpráva už v ISDS neexistuje), se zkouší
  po 1, 2, 4 … hodinách (nejvýše jednou denně) a po 8 neúspěšných pokusech se přestane
  zkoušet — nezdržuje ostatní zprávy.

## Stránka Datové schránky

- **Přepínač schránek** nahoře, záložky **Přijaté** a **Odeslané**, filtr
  *Nepřečtené* a *Od finančního úřadu*, hledání podle věci / odesílatele / čísla zprávy.
- **Stav zprávy** v seznamu:

| Stav | Význam |
|---|---|
| **V schránce** | Zpráva dorazila do schránky, ale **není doručena** (nikdo ji dosud nečetl). Zobrazí se datum, kdy se doručí fikcí. |
| **Doručeno fikcí** | Uplynulo 10 dní (první pracovní den po nich) — právně doručeno. |
| **Doručeno** | Přihlásil se oprávněný uživatel — právně doručeno. |
| **Nedoručitelná** | Schránka adresáta byla znepřístupněna. |

### Otevření zprávy (varování)

Tlačítko **Otevřít a stáhnout** stáhne podepsanou zprávu (ZFO), uloží ji a zobrazí
přílohy ke stažení. U zprávy, která **ještě není doručená**, před tím aplikace
zobrazí varování a vyžádá **výslovné potvrzení**:

> Otevřením se zpráva **doručí** (začnou běžet lhůty) a mohou se doručit i další
> dosud nedoručené zprávy ve schránce. Pokračuj, jen pokud to chceš.

Důvod: systém datových schránek zprávu nevydá, dokud není doručená, a doručit ji
může jen přihlášení uživatele, který zprávu smí číst. Zprávy, které jsou už
doručené (včetně doručení fikcí), se stahují **bez jakéhokoli vedlejšího účinku**.
Uložené ZFO si můžeš kdykoliv stáhnout i s doručenkou.

Aplikace při doručení vypíše jen **dvousekundové okno** kolem času, kdy zpráva dorazila do
schránky, aby se nedoručily zbytečně i jiné zprávy. Pokud u zprávy čas dodání chybí,
aplikace ji **neotevře** (chyba 409 „U zprávy chybí čas dodání") — otevři ji ve
vlastní datovce nebo na portálu datových schránek.

Zpráva označená **do vlastních rukou** vyžaduje přihlášení s právem „Číst přijaté
zprávy do vlastních rukou"; jinak ji otevři v oficiálním portálu.

### Lhůty u zpráv od finančního úřadu

Aplikace rozpozná odesílatele podle schránky a názvu a zvýrazní zprávu. U **výzvy
týkající se kontrolního hlášení** ukáže dvě orientační lhůty (závazná je vždy ta
ve výzvě):

- výzva k **podání** kontrolního hlášení: náhradní lhůta **5 dnů od oznámení výzvy**
  (§ 101g odst. 1 zákona o DPH),
- výzva ke **změně, doplnění nebo potvrzení údajů**: **17 dnů ode dne dodání výzvy
  do datové schránky** (§ 101g odst. 3 zákona o DPH).

Konec lhůty připadající na sobotu, neděli nebo svátek se posouvá na nejbližší
pracovní den.

## Odeslání zprávy

1. Na stránce **Datové schránky** klikni na **Nová zpráva**.
2. Vyber **odesílající schránku**, zadej **ID datové schránky příjemce** (7 znaků)
   nebo ho vyhledej tlačítkem **Vyhledat** (podle ID, IČO nebo názvu — vyžaduje
   přihlášení s právem „Vyhledávat schránky"; ze schránky, která není orgán veřejné
   moci, najdeš úřady a jednu konkrétní schránku s povoleným příjmem poštovních
   zpráv, pokud ji zadáš přesně), vyplň **věc** (nejvýše 255 znaků)
   a přilož soubory.
3. Klikni na **Připravit k odeslání**. Aplikace zprávu zkontroluje a ukáže
   **potvrzovací okno** se schránkou odesílatele, příjemcem, věcí a soubory
   (název, velikost, otisk SHA-256). **Zatím se nic neodeslalo.**
4. Teprve tlačítko **Odeslat** v potvrzovacím okně zprávu odešle. Potvrzení platí
   jednou a 30 minut — bez něj aplikace **nikdy** nic neodešle (ani ze skriptu, ani
   přes API token, ani přes MCP).
5. Po odeslání aplikace uloží odeslanou zprávu, její **doručenku** a podepsanou
   kopii zprávy. Doručenka je důkaz doručení.

Omezení: celkem nejvýše **20 MB** příloh, nejvýše 100 souborů, jen přípony, které
datové schránky přijímají (např. pdf, xml, docx, xlsx, txt, zip, jpg, png). Větší
(velkoobjemové) zprávy aplikace neodesílá. Zprávu nelze poslat do vlastní schránky.

## Odeslání daňového podání finančnímu úřadu

V **Daně → Archiv podání** je u každého archivovaného výkazu (přiznání k DPH,
kontrolní hlášení, souhrnné hlášení, přiznání k dani z příjmů) tlačítko
**Odeslat datovou schránkou**.

- **Příjemce** se předvyplní podle kódu finančního úřadu (`c_ufo`), který je přímo
  v XML podání (a není-li, podle nastavení firmy). Aplikace zná datové schránky všech
  finančních úřadů a Specializovaného finančního úřadu; u neznámého úřadu zadáš ID ručně.
- **Příloha** je archivované XML beze změny, pojmenované například
  `dphdp3-2026-09.xml`. **Věc zprávy** je například „Přiznání k DPH za 09/2026, DIČ …"
  a můžeš ji upravit.
- **Podpis není potřeba:** podání odeslané ze **své datové schránky** má stejné účinky
  jako podepsané (§ 71 odst. 1 písm. b zákona č. 280/2009 Sb., daňový řád; pokyny
  Finanční správy). Podání proto posílej ze **schránky podnikatele (PFO / PO)**, ne
  z osobní schránky FO — aplikace u osobní schránky upozorní.
- **XML, které neprošlo kontrolou proti schématu EPO, se neodešle** — podání, které
  nemá předepsaný formát a strukturu, je podle § 101a odst. 4 zákona o DPH neúčinné.
  Pokud se kontrola při generování vůbec **neprovedla** (stav „přeskočeno"), jde podání
  připravit, ale odeslat až po **samostatném zaškrtnutí** potvrzení, že rozumíš riziku
  neúčinného podání.
- Před odesláním aplikace ověří, že archivované XML sedí s uloženým **kontrolním součtem
  (SHA-256)** a že to, co se odesílá, je přesně to, co ukazuje souhrn k potvrzení. Při
  neshodě se nic neodešle (hláška „neodpovídá uloženému kontrolnímu součtu").
- Je-li podání **už odeslané**, ukáže aplikace předchozí odeslání (číslo zprávy,
  stav, doručenku) a druhé odeslání vyžaduje **navíc potvrzení** („odeslat znovu"),
  aby nevznikly omylem duplicity.
- **Odesláním se podání označí jako podané** (stejná evidence jako v kapitole
  [Výkazy DPH](29_Vykazy_DPH.md#evidence-podání-platba-dph-a-kontroly-před-podáním)):
  způsob **datová schránka**, číslo podání = **číslo zprávy**, čas = okamžik odeslání.
  Jakmile ISDS vrátí doručenku, čas se upřesní na okamžik, kdy byla zpráva **dodána do
  datové schránky úřadu** (to je chvíle, kdy podání dojde správci daně), a podepsaná
  doručenka (ZFO) se uloží k podání jako potvrzení — podání přejde do stavu
  **Potvrzeno**. Zpráva, kterou ISDS odmítne nebo nedoručí, označení podání zase zruší.
  Dělá to i hodinová synchronizace, pokud doručenka není hned po odeslání k dispozici.
- Je-li výkaz **už označený jako podaný** (na EPO portálu nebo dřívějším odesláním),
  je odeslání „odeslat znovu": vyžaduje potvrzení a stav původního podání ani jeho
  potvrzení nezmění. Je-li za stejné období a formu podané **jiné** podání, aplikace
  odeslání odmítne ještě před odesláním — nešlo by ho zaznamenat; nejdřív u něj
  zruš označení podání.
- Číslo zprávy a **doručenka** se uloží k podání a zobrazí v seznamu archivu. Finanční
  správa uvádí, že stav doručení je jednoznačně patrný z doručenky v odesílající
  schránce; potvrzení doručení však **neříká**, že je podání věcně správné a účinné.

Lhůta pro podání se plní dnem, kdy zpráva **dojde** správci daně — odesílej s rezervou.

## Zabezpečení a soukromí

- Hesla se ukládají **šifrovaně** (stejný mechanismus jako u ostatních uložených
  přihlášení v aplikaci); API je nikdy nevrací a do logů se nezapisují.
- Celá sekce je dostupná **jen z webového rozhraní administrátorovi**. API tokeny
  a MCP (AI asistenti) k datovým schránkám **nemají přístup** — ani k upozorněním
  z datových schránek v seznamu úkolů na dashboardu (`/api/v1/crm/action-items`).
- Hesla jsou v aplikaci označená tak, aby se **nikdy neobjevila ve výpisu chyby** (stack trace).
- Zprávy a podepsané soubory jsou uložené v databázi aplikace, tedy i v **zálohách
  databáze**. Příloha se vždy nabízí ke stažení, nikdy se nezobrazuje přímo v prohlížeči.
- Server komunikuje s ISDS přes HTTPS (TLS 1.2+) s ověřením certifikátu.

## Příkazová řádka

```bash
php api/bin/databox-check.php --box=<id> [--purpose=sync|action] [--days=30] [--json]
```

Jen čtecí kontrola schránky: ověří přihlášení, vypíše zjištěná práva a seznam
obálek. Seznam **přijatých** zpráv načte jen s přihlášením, které nečte (jinak by
zprávy doručilo) — s přihlášením, které čte, vypíše jen odeslané; přijaté vypíše
až s `--allow-delivery`. Nikdy nestahuje obsah ani nic neodesílá.

## Řešení problémů

| Hláška / příznak | Příčina a řešení |
|---|---|
| *Přihlášení se nezdařilo: neplatné jméno nebo heslo* | Špatné jméno / heslo, vypršelé heslo, nebo má uživatel v ISDS zapnutý certifikát či jiný způsob přihlášení. Přihlas se jednou v portálu a ověř. |
| *Přihlášení je dočasně zablokované* | Příliš mnoho chybných pokusů. Počkej na uvedený čas, **nezkoušej hesla dál**. Synchronizace je pozastavená. |
| *Toto přihlášení systém datových schránek odmítl, proto ho aplikace nepoužije …* | Heslo bylo odmítnuto a aplikace ho nezkouší, aby účet nezamkla. Oprav heslo v nastavení (po uplynutí čekací doby) a spusť **Ověřit připojení**. |
| *Další pokus je možný po HH:MM* | Čekací doba po odmítnutém přihlášení (5 min, pak dvojnásobně). Do té doby aplikace ISDS nevolá. |
| *Systém datových schránek zablokoval přihlášení z adresy serveru* | Zavolej na infolinku 954 200 200. |
| *Synchronizace by zprávy doručovala* | Přihlášení pro synchronizaci smí číst zprávy. Odeber v ISDS práva „Číst přijaté zprávy…", nebo doručování výslovně povol. |
| *Zpráva dosud nebyla doručena* (kód 1222) | Zprávu lze stáhnout až po doručení. Otevři ji tlačítkem v aplikaci (s potvrzením), nebo v portálu. |
| *Určena do vlastních rukou* (kód 1178) | Přečti ji v oficiálním portálu, nebo přidej přihlášení s právem „…do vlastních rukou". |
| *Chybí právo …* | Přihlášení nemá potřebné právo v ISDS; uprav práva v portálu a spusť **Ověřit připojení**. |
| *Kód 3008 / 3009* | ISDS omezuje počet souběžných požadavků; zkus to za chvíli. |
| *Odstávka (HTTP 503)* | Plánovaná odstávka ISDS; synchronizace se opakuje v další hodině. |

## Zdroje

Postup a pravidla vychází z oficiálních dokumentů: Provozní řád ISDS a technické
přílohy (https://datovka.gov.cz/info/cs/80.html), Webové služby rozhraní ISDS
(verze 3.8.1), nápověda portálu (https://datovka.gov.cz/info/cs/help/), Příklady k
povinnosti činit podání elektronicky a Informační povinnost Finanční správy
(https://financnisprava.gov.cz).
