# 8. Přehled (dashboard)

Přehled je úvodní obrazovka po přihlášení — okamžitý report, kolik jsi vystavil,
co je po splatnosti, jaký je obrat za letošní a loňský rok, a kdo jsou tví
top klienti.

![Přehled (dashboard)](img/05_dashboard.webp)

## 8.1 KPI dlaždice (horní řada)

Šířka řady se přizpůsobí počtu aktivních měn (4–6 dlaždic):

| Dlaždice | Význam |
|---|---|
| **Obrat YYYY (CZK)** | Součet všech vystavených (i nezaplacených) faktur v CZK za aktuální rok. Pod číslem je pro porovnání obrat minulého roku ve stejném období. |
| **Obrat YYYY (EUR)** | Totéž pro EUR (jen pokud máš EUR měnu aktivní v Číselnících). |
| **Vystaveno YYYY** | Počet faktur za rok (všechny stavy kromě konceptů). |
| **Po splatnosti** | Suma neuhrazených faktur, které jsou po splatnosti. Zobrazená v CZK + EUR součtu, červené barvy. Klik proklikne na filtrovaný seznam. |
| **Ø doba úhrady** | Průměrný počet dní mezi vystavením a zaplacením (jen pro letošní zaplacené faktury). |

## 8.2 Top klienti — koláč

Levý koláč ukazuje **3 největší klienty letos**, pravý **3 největší loni**.
Hover nad výsečí ukáže jméno klienta + obrat. Klik na legendu odfiltruje.

> 💡 Pokud máš multi-supplier (více dodavatelů), koláč ukazuje data jen pro
> aktuálně vybraného dodavatele (přepínač v horní liště).

## 8.3 Stav faktur — koláč

Pravý koláč rozdělí letošní faktury podle stavu:

- 🟢 **Zaplaceno** — `paid`
- 🟣 **Odesláno** — `sent` (klientovi šel e-mail s PDF, čekáme na platbu)
- 🟡 **Vystaveno (neodesláno)** — `issued` (vystaveno, ale ještě jsme neposlali)
- 🟠 **Upomínka** — `reminded` (po splatnosti, byla odeslána upomínka)
- ⚫ **Storno / dobropis** — `cancellation` / `credit_note`

## 8.4 Obrat po měsících (line / bar chart)

Spodní dva grafy ukazují měsíční obrat (CZK a EUR samostatně) — letošní rok
plnou barvou, minulý rok prázdnou pro porovnání. Hover nad sloupcem ukáže
přesnou částku.

## 8.5 Po splatnosti + nezaplacené faktury

Pod grafy je tabulka:

- **Po splatnosti** (červené) — faktury, které jsou v stavu `sent` / `issued` /
  `reminded` a překročily splatnost. Tlačítko **Upomínka** odešle upomínací
  e-mail.
- **Nezaplacené** — faktury v stavu `sent` / `issued` / `reminded`, ještě před
  splatností.

Klik na číslo faktury otevře [Detail faktury](11_Faktura_PDF.md).

## 8.6 Rychlé vytváření (horní lišta)

Nové doklady a záznamy zakládáš z tlačítka **+ Vytvořit** v horní liště
(vpravo, vedle jména uživatele). Rozbalovací menu nabízí:

- **Vydaná faktura** — otevře [Editor faktury](10_Faktura_editor.md), prázdný koncept
- **Zálohová faktura** — editor rovnou v režimu proforma
- **Pravidelná fakturace** — nová [šablona](12_Pravidelne_fakturace.md)
- **Klient** — modal pro založení klienta (s ARES lookupem)
- **Dodavatel** — nový dodavatel (firma)
- **Přijatá faktura** — nová [přijatá faktura](17_Prijate_faktury.md)

Stejné zkratky najdeš i v postranním menu jako nenápadné **„+"** u příslušné
položky (objeví se po najetí myší). Rychlé vytváření je dostupné jen pro
uživatele s právem zápisu.

## 8.7 Aktualizace dat

Statistiky se nepočítají v reálném čase — používají agregační cache
(`project_revenue_cache`, `client_revenue_cache`), která se přepočítá pokaždé,
když vystavíš / zrušíš / označíš zaplacenou fakturu. Pokud někdy zjistíš, že
čísla nesedí (např. po manuální úpravě v DB), spusť z CLI:

```bash
php api/bin/recompute-stats.php
```

> 🛈 Sample data (vygenerovaná během setup wizardu) automaticky přepočítají
> stats hned po dokončení — nemusíš nic dělat.

## 8.8 Vzhled — světlý a tmavý režim

V horní liště (vpravo, vedle přepínače jazyka) je přepínač barevného motivu se
třemi stavy:

- **Systém** — řídí se nastavením operačního systému / prohlížeče
  (`prefers-color-scheme`). Výchozí volba.
- **Světlý** — vždy světlé téma.
- **Tmavý** — vždy tmavé téma.

Volba se ukládá do prohlížeče (per zařízení) a platí napříč celou aplikací
včetně grafů. Na mobilu je přepínač v rozbalovacím menu (☰) dole, vedle
přepínače jazyka.

## 8.9 Oblíbené položky menu

Položky, se kterými pracuješ nejčastěji, si můžeš připnout do sekce
**Oblíbené** v postranním menu, hned pod **Přehled**:

- Najeď myší na položku menu a klikni na **☆** u jejího pravého okraje.
  Položka se přidá do sekce Oblíbené a hvězdička se vybarví.
- Opětovným klikem na **★** ji z oblíbených odebereš.
- Na mobilu (bez myši) je hvězdička vidět u stránky, na které právě jsi,
  a u položek, které už v oblíbených máš. Stránku tedy otevři, rozbal menu (☰)
  a ťukni na hvězdičku u zvýrazněné položky.

Oblíbené se řadí ve stejném pořadí jako v menu a položka zůstává i ve své
původní sekci. Ukládají se k tvému uživatelskému účtu na server, takže je
uvidíš na všech zařízeních, kde se přihlásíš. Každý uživatel má vlastní výběr;
položka, ke které nemáš oprávnění (nebo se k firmě nehodí, např. Daňový
optimalizátor u s.r.o.), se v Oblíbených nezobrazí. Oblíbené si může nastavit
i uživatel s rolí *jen pro čtení*.

## 8.10 Daňový kalendář a daňové akce

Pod seznamem **Akce pro tebe** je **Daňový kalendář** — nejbližší daňové termíny
dodavatele (dalších 60 dní a nedávno zmeškané). Každá položka má datum, počet dní do
termínu, odkaz na příslušnou stránku a u výkazů štítek **Nepodáno / Podáno / Potvrzeno**
podle [Archivu podání](29_Vykazy_DPH.md#evidence-podání-platba-dph-a-kontroly-před-podáním).
Podaný výkaz po termínu z kalendáře zmizí.

Termíny se odvozují z nastavení dodavatele:

| Termín | Kdy se zobrazuje |
|---|---|
| **Přiznání k DPH** | měsíčně, nebo čtvrtletně podle periody plátce (identifikovaná osoba vždy měsíčně); 25. dne po skončení období |
| **Kontrolní hlášení** | jen plátce; právnická osoba vždy měsíčně, fyzická osoba ve lhůtě přiznání (tedy podle periody DPH) |
| **Souhrnné hlášení** | jen za období s EU plněními; dodání zboží měsíčně, kvartální plátce jen se službami čtvrtletně |
| **Přiznání k dani z příjmů** | 1. dubna následujícího roku (DPFO / DPPO podle typu poplatníka) |
| **Záloha na paušální daň** | 20. dne každého měsíce, **jen v roce, kdy máš pásmo paušální daně** (rok bez dnů plátce DPH) |

Položky DPH se zobrazují **jen za období, kdy jsi měl povinnosti k DPH** (byl jsi plátce
nebo identifikovaná osoba podle [historie plátcovství](35_Multi_supplier.md#3557-platcovstvi-dph-historie-podle-data)) — nový plátce
od 1. 1. 2027 tedy první termín uvidí na přiznání za leden 2027.

Stejné termíny (v okně −3 až +7 dní) se objevují i v **Akcích pro tebe** — až do chvíle,
kdy je výkaz podaný. Podané přiznání k DPH s daňovou povinností tam navíc drží položku
**Zaplaťte DPH**, dokud není zaplacené.
