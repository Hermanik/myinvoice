<?php

declare(strict_types=1);

namespace MyInvoice\I18n;

/**
 * Bilingual katalog backend chybových hlášek.
 *
 * Klíč = literální CZ text (přesně jak ho píše Json::error). Hodnota = EN ekvivalent.
 *
 * Chybový text s PHP proměnnými ($var, {$var}) sem nepatří — zůstává v CZ
 * (na seznamu výjimek, ~10 ks; lze upgradovat na placeholder formát kdykoli).
 *
 * Json::error() projde každý message přes lookup() — pokud nenajde, vrátí původní text.
 */
final class ErrorCatalog
{
    /** @var array<string,string> CZ → EN */
    private const MAP = [
        'ARES je dočasně nedostupný.' => 'ARES is temporarily unavailable.',
        'Aktuální heslo není správné.' => 'Current password is incorrect.',
        'Aplikace ještě není inicializovaná. Otevřete /setup pro vytvoření admin účtu.' => 'Application is not initialized yet. Open /setup to create the admin account.',
        'Chybí invoice_id.' => 'Missing invoice_id.',
        'Chybí project_id.' => 'Missing project_id.',
        'Chybí title.' => 'Missing title.',
        'Chyba konfigurace serveru.' => 'Server configuration error.',
        'DIČ musí mít prefix země a 2-12 znaků (např. CZ12345678, NL123456789B01).' => 'VAT ID must have a country prefix and 2–12 characters (e.g. CZ12345678, NL123456789B01).',
        'Dobropis ani storno nelze stornovat.' => 'Credit note and cancellation cannot be cancelled.',
        'Email je už registrovaný.' => 'Email is already registered.',
        'Email se nepodařilo odeslat: ' => 'Failed to send email: ',
        'Faktura byla mezitím změněna.' => 'Invoice has been modified in the meantime.',
        'Faktura musí obsahovat alespoň jednu položku.' => 'Invoice must contain at least one item.',
        'Fakturu s částkou k úhradě 0 nebo méně nelze označit jako zaplacenou.' => 'An invoice with amount due 0 or less cannot be marked as paid.',
        'Hesla se neshodují.' => 'Passwords do not match.',
        'Heslo musí mít alespoň 12 znaků.' => 'Password must be at least 12 characters.',
        'Interní storno se klientovi neposílá.' => 'Internal cancellation is not sent to the client.',
        'IČO musí mít 8 číslic.' => 'Reg. No. must have 8 digits.',
        'Jméno je povinné.' => 'Name is required.',
        'Lze označit jako zaplacené jen vystavenou nebo odeslanou fakturu.' => 'Only an issued or sent invoice can be marked as paid.',
        'Lze poslat jen vystavenou fakturu.' => 'Only an issued invoice can be sent.',
        'Lze pouze ze zálohové faktury (proforma).' => 'Only allowed from a proforma invoice.',
        'Lze smazat jen draft fakturu (vystavenou jen storno/dobropis).' => 'Only a draft invoice can be deleted (issued ones only via cancel/credit note).',
        'Lze vystavit jen draft fakturu.' => 'Only a draft invoice can be issued.',
        'Lze zrušit jen vystavenou/odeslanou/zaplacenou fakturu.' => 'Only an issued/sent/paid invoice can be cancelled.',
        'Měna nenalezena.' => 'Currency not found.',
        'Množství nesmí být 0.' => 'Quantity must not be 0.',
        'Měna s tímto kódem už existuje.' => 'A currency with this code already exists.',
        'Nelze deaktivovat posledního aktivního admina.' => 'Cannot deactivate the last active admin.',
        'Nelze odebrat admin roli ani deaktivovat posledního aktivního admina.' => 'Cannot remove the admin role or deactivate the last active admin.',
        'Nelze parsovat: ' => 'Cannot parse: ',
        'Nelze smazat vlastní účet.' => 'Cannot delete your own account.',
        'Nelze vytvořit ZIP.' => 'Cannot create ZIP.',
        'Není vybrána žádná faktura.' => 'No invoice selected.',
        'Neplatná role.' => 'Invalid role.',
        'Neplatné datum.' => 'Invalid date.',
        'Neplatné přihlašovací údaje.' => 'Invalid credentials.',
        'Neplatný email.' => 'Invalid email.',
        'Neplatný kód měny.' => 'Invalid currency code.',
        'Neplatný nebo chybějící CSRF token.' => 'Invalid or missing CSRF token.',
        'Neplatný token.' => 'Invalid token.',
        'Nepodařilo se vygenerovat PDF: ' => 'Failed to generate PDF: ',
        'Nepřihlášený uživatel.' => 'Not authenticated.',
        'Nová hesla se neshodují.' => 'New passwords do not match.',
        'Origin nesedí s app URL.' => 'Origin does not match the app URL.',
        'Parametr month musí být YYYY-MM.' => 'Parameter "month" must be YYYY-MM.',
        'Platnost tokenu vypršela.' => 'Token has expired.',
        'Pouze admin nebo účetní.' => 'Admin or accountant only.',
        'Pro pokračování je nutné aktivovat dvoufaktorové ověření.' => 'You must activate two-factor authentication to continue.',
        'Proforma musí být označená jako zaplacená.' => 'Proforma must be marked as paid.',
        'Příliš mnoho pokusů. Zkus to později.' => 'Too many attempts. Try again later.',
        'Setup již proběhl.' => 'Setup has already been completed.',
        'Soubor chybí.' => 'File missing.',
        'Soubor je prázdný.' => 'File is empty.',
        'Storno doklad nelze editovat.' => 'A cancellation document cannot be edited.',
        'Storno nedostává varsymbol.' => 'A cancellation document does not get a variable symbol.',
        'Supplier nevyplněn (spusť setup).' => 'Supplier not configured (run setup).',
        'Tato IP adresa nemá přístup k aplikaci.' => 'This IP address is not allowed to access the application.',
        'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.' => 'TOTP is already enabled. To reset it, use: php api/bin/reset-2fa.php <email>.',
        'Token nebo heslo chybí.' => 'Token or password missing.',
        'Token už byl použit.' => 'Token has already been used.',
        'Upomínat lze jen faktury s kladnou částkou k úhradě.' => 'Only invoices with a positive amount due can be reminded.',
        'Uživatel nenalezen.' => 'User not found.',
        'Vystavenou fakturu nelze editovat.' => 'An issued invoice cannot be edited.',
        'Vystavenou fakturu nelze upravit. Administrátor ji může odemknout k editaci přímo v editoru dokladu (tlačítko „Odemknout k editaci").' => 'An issued invoice cannot be edited. An administrator can unlock it for editing directly in the document editor (the "Unlock for editing" button).',
        'Odemknout a upravit vystavenou fakturu smí jen administrátor.' => 'Only an administrator can unlock and edit an issued invoice.',
        'Odemknout a upravit fakturu mimo stav draft smí jen administrátor.' => 'Only an administrator can unlock and edit a non-draft invoice.',
        'Stornovanou fakturu nelze upravit (auditní stopa).' => 'A cancelled invoice cannot be edited (audit trail).',
        'Stornovaný doklad nelze měnit (auditní stopa).' => 'A cancelled document cannot be modified (audit trail).',
        'Obnovit údaje klienta na dokladu smí jen administrátor.' => 'Only an administrator can refresh client data on a document.',
        'Koncept snapshoty nepoužívá — údaje klienta se doplní živé při vystavení.' => 'Drafts do not use snapshots — client data is taken live and captured at issue time.',
        'Nelze vygenerovat XML — chybí povinné údaje pro EPO podání. Doplň je v Nastavení → Daňové nastavení.' => 'Cannot generate the XML — required EPO identification fields are missing. Fill them in Settings → Tax settings.',
        'Vyžaduje se CAPTCHA.' => 'CAPTCHA required.',
        'Výkaz lze smazat pouze v draftu (admin: ?force=1).' => 'Work report can only be deleted on a draft (admin: ?force=1).',
        'Výkaz lze upravit pouze v draftu (admin: ?force=1).' => 'Work report can only be edited on a draft (admin: ?force=1).',
        'Výpis nenalezen.' => 'Bank statement not found.',
        'Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.' => 'Amount due must be greater than 0. Use a credit note for a zero or negative document.',
        'Zakázka nenalezena.' => 'Project not found.',
        'Záporné množství i záporná cena zároveň nejsou povolené.' => 'Negative quantity and negative unit price at the same time are not allowed.',
        'Země s tímto iso2 už existuje.' => 'A country with this iso2 already exists.',
        'Záloha nesmí být záporná.' => 'Advance payment must not be negative.',
        'cfg.bank_import.scan_root není nastaveno nebo adresář neexistuje.' => 'cfg.bank_import.scan_root is not set or the directory does not exist.',
        'cfg.smtp.from_email není nastaveno.' => 'cfg.smtp.from_email is not set.',
        'code a rate_percent jsou povinné.' => '"code" and "rate_percent" are required.',
        'code musí být 3 znaky.' => '"code" must be 3 characters.',
        'iso2 musí být 2 znaky.' => '"iso2" must be 2 characters.',
        'mode musí být "internal" nebo "credit_note".' => '"mode" must be "internal" or "credit_note".',
        'Žádný platný příjemce (chybí email klienta).' => 'No valid recipient (client email missing).',
        // Časté hlášky používané v jednom callsite, ale stejný text se opakuje (např. not_found):
        'Klient nenalezen.' => 'Client not found.',
        'Faktura nenalezena.' => 'Invoice not found.',
        'Validace selhala' => 'Validation failed',
        // VAT status history (Settings → "Plátcovství DPH")
        'Nejstarší záznam nelze smazat — historie musí vždy začínat nějakým stavem.' => 'The earliest entry cannot be deleted — the history must always start with some status.',
        'Pro toto datum už záznam existuje. Smažte ho a vytvořte znovu.' => 'An entry for this date already exists. Delete it and create it again.',
        'Záznam nenalezen.' => 'Entry not found.',
        'V zvoleném období nebyl dodavatel plátcem DPH ani identifikovanou osobou — výkaz nelze vygenerovat. Plátcovství DPH se nastavuje v Nastavení → Plátcovství DPH.' => 'The supplier was neither a VAT payer nor a VAT-identified person in the selected period — the statement cannot be generated. The VAT status is set in Settings → VAT status.',
        // User invitations + per-user second-factor mode
        'Odkaz už neplatí, požádejte správce o nový.' => 'This link is no longer valid, ask the administrator for a new one.',
        'Neplatný režim druhého ověření.' => 'Invalid second-factor mode.',
        'Správce nemůže používat druhé ověření kódem e-mailem.' => 'An administrator cannot use the emailed-code second factor.',
        'Neaktivního uživatele nelze pozvat.' => 'An inactive user cannot be invited.',
        'Pozvánku se nepodařilo odeslat e-mailem. Zkontroluj nastavení SMTP nebo použij odkaz.' => 'The invitation could not be e-mailed. Check the SMTP settings or use the link instead.',
        'Ověření kódem z e-mailu není pro tento účet dostupné.' => 'Verification by an emailed code is not available for this account.',
        'E-mailový kód tuhle operaci potvrdit nemůže.' => 'An emailed code cannot confirm this operation.',
        'Neplatný nebo expirovaný kód.' => 'Invalid or expired code.',
    ];

    /**
     * Vrátí EN překlad pokud existuje v katalogu, jinak původní text.
     * Pro $locale = 'cs' (default) vrací vždy původní.
     *
     * Ošetřuje i prefix-match: pokud je v katalogu klíč "Email se nepodařilo odeslat: "
     * a vstup je "Email se nepodařilo odeslat: connection timeout", přeloží prefix.
     */
    public static function lookup(string $cs, string $locale): string
    {
        if ($locale === 'cs' || $cs === '') return $cs;
        if (isset(self::MAP[$cs])) return self::MAP[$cs];
        // Prefix-match pro hlášky končící zprávou výjimky
        foreach (self::MAP as $key => $en) {
            if (str_ends_with($key, ': ') && str_starts_with($cs, $key)) {
                return $en . substr($cs, strlen($key));
            }
        }
        return $cs;
    }
}
