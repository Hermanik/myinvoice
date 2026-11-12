# 34. Hromadný export (ZIP)

### Cesta: `Daně → Hromadný export`

Stáhne **jeden ZIP** za zvolené období se vším, co účetní pro dané období potřebuje,
roztříděné do pojmenovaných složek. Období může být **jeden měsíc**, **celé čtvrtletí**
(`Q1`–`Q4`), nebo **celý rok** — přepínač **Měsíc / Čtvrtletí / Rok** je nahoře vedle
výběru období. Zaškrtnutím vyberete, co se zabalí:

- **Vystavené faktury** — PDF a/nebo ISDOC
- **Přijaté faktury** — PDF a/nebo ISDOC (u PDF má přednost originál od dodavatele;
  pokud chybí, vloží se naše rekonstrukce s příponou `-rekonstrukce`)
- **Výpisy z účtu** — PDF a/nebo GPC (originální soubory)
- **Kniha DPH** — měsíční PDF žurnál, **jedno PDF za každý měsíc období**, ve kterém
  jste byli plátcem DPH (u čtvrtletí až tři, u roku až dvanáct; za měsíce před
  registrací k DPH nebo po jejím ukončení se Kniha DPH nevytváří)
- **Souhrnné přehledy faktur (CSV)** — dva soubory `Prehled-vydanych-faktur.csv` a
  `Prehled-prijatych-faktur.csv` pro Excel (viz níže); jsou zaškrtnuté vždy

U každé části se hned ukáže počet dostupných dokladů; prázdné části nejdou zaškrtnout.

### Zařadit doklady podle

Vedle období je volba, **podle čeho se doklady do období zařazují**. Pod volbou se
vždy zobrazí krátké vysvětlení zvoleného způsobu a počty v nabídce se podle něj
přepočítají.

| Volba | Kdy ji použít | Co do období patří |
|---|---|---|
| **DUZP – pro DPH** (výchozí pro měsíc a čtvrtletí) | podklady pro DPH, kontrolní hlášení | Zařazení shodné s výkazy DPH (přiznání, kontrolní hlášení, Kniha DPH): vystavené dle DUZP, přijaté tuzemské dle pozdějšího z dat DUZP / vystavení, přijaté zahraniční reverse charge dle DUZP. |
| **Data úhrady – pro daň z příjmů** (výchozí pro rok) | roční daň z příjmů OSVČ | Vystavené i přijaté doklady **zaplacené v plné výši** v období podle data úhrady. |

U OSVČ se pro **daň z příjmů** počítají příjmy a výdaje **ke dni zaplacení**, ne podle
DUZP — prosincová faktura uhrazená v lednu patří do příjmů následujícího roku. Proto je
u celého roku výchozí zařazení podle data úhrady. Při tomto zařazení platí:

- **Neuhrazené doklady** a doklady uhrazené **mimo období** v exportu nejsou. Doklady
  s DUZP v období, které v něm nejsou uhrazené, najdete ve **druhé části souhrnných
  přehledů CSV** (stav *Neuhrazeno*, *Uhrazeno po období*, *Uhrazeno před obdobím*), ať
  je vidět, co chybí. Počet takových dokladů ukáže i stránka.
- **Proformy** se do příjmů nepočítají; záloha se eviduje daňovým dokladem k přijaté
  platbě. **Dobropisy** se zahrnou se záporným znaménkem.
- **Kniha DPH je vždy podle DUZP**, bez ohledu na zvolené zařazení.
- **Výpisy z účtu** jsou vždy podle data výpisu.

Zvolené zařazení je vysvětlené také v souboru `README.txt` uvnitř ZIPu. Název ZIPu se
od výchozího liší příponou `-podle-uhrady` (nebo `-podle-duzp`), když zvolíte jiné
zařazení, než je pro dané období výchozí.

### Souhrnné přehledy faktur (CSV)

Soubory `Prehled-vydanych-faktur.csv` a `Prehled-prijatych-faktur.csv` leží v kořeni
ZIPu. Jsou ve formátu, který se v českém Excelu otevře rovnou správně: UTF-8 s BOM,
oddělovač `;`, desetinná čárka, české záhlaví. Na každém řádku je jeden doklad:

`Číslo dokladu; Typ; Partner; IČO; DIČ; Datum vystavení; DUZP; Splatnost; Datum úhrady;
Základ bez DPH; DPH; Celkem s DPH; Měna; Celkem v CZK; Stav`

- Poslední řádek je **součet v CZK**; doklady v cizí měně se přepočítají kurzem
  dokladu (sloupec *Celkem v CZK* ukazuje přepočet každého z nich). Doklad v cizí
  měně bez uloženého kurzu se vypíše, ale do součtu se nezapočítá — export na to
  upozorní v `README.txt`.
- **Proformy** a **zálohy přijatých faktur vyúčtované konečnou fakturou** se vypíšou,
  ale do součtu se nepočítají (stav to říká).
- Při zařazení podle data úhrady následuje pod součtem druhá část s doklady, které
  v období nejsou uhrazené (viz výše), s vlastním součtem.

#### Běh na pozadí

Protože u většího počtu faktur může příprava PDF chvíli trvat, export běží jako
**úloha na pozadí** — po spuštění vidíte průběh (stav, postup, krok) a po dokončení
tlačítko **Stáhnout ZIP**. Hotové exporty zůstávají v seznamu **Poslední exporty** a
jdou stáhnout opakovaně; soubor se stažením nemaže. Úklid proběhne automaticky po
7 dnech (nebo ručně tlačítkem koš). Souběžně běží vždy jen jeden export.

> [!TIP]
> Pro jednoúčelové formáty (jen ISDOC vydaných, jen Pohoda XML) použij
> [Exporty](15_Exporty.md) v sekci Prodej. Hromadný export je komplexní balíček
> „všechno za období pro účetní".
