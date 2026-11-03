<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * One SOAP 1.1 POST with HTTP Basic authentication (stateless, no cookies).
 * An interface so unit tests can replay recorded / handwritten responses; production uses
 * {@see GuzzleIsdsTransport}. Implementations throw {@see IsdsException} (reason 'network') when no
 * HTTP response was received at all; HTTP error statuses are returned, not thrown.
 */
interface IsdsTransport
{
    public function post(string $url, IsdsCredentials $credentials, string $soapEnvelope): IsdsTransportResponse;
}
