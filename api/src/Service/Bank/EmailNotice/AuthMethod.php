<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * How the sender of a bank e-mail notice was authenticated. The value is stored in
 * `bank_email_processed_messages.auth_method`.
 */
enum AuthMethod: string
{
    /** The verdict of a trusted receiving server (configured authserv-id). */
    case AuthenticationResults = 'authentication_results';
    /** Our own DKIM verification of the raw message. */
    case DkimLocal = 'dkim_local';
}
