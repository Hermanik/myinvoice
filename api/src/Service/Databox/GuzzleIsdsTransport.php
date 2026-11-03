<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Guzzle implementation of {@see IsdsTransport}. TLS 1.2+ is required by ISDS and is the default of
 * the system curl; certificate verification stays on.
 */
final class GuzzleIsdsTransport implements IsdsTransport
{
    private readonly ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 90,          // downloading a ~26 MB signed message can take a while
            'connect_timeout' => 15,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function post(string $url, IsdsCredentials $credentials, string $soapEnvelope): IsdsTransportResponse
    {
        try {
            $response = $this->http->request('POST', $url, [
                'auth' => [$credentials->username, $credentials->password()],
                'headers' => [
                    'Content-Type' => 'text/xml; charset=UTF-8',
                    'SOAPAction' => '""',
                    'Accept' => 'text/xml',
                    // ISDS asks every application to identify itself in User-Agent (OTP_autentizace.pdf 2.0).
                    'User-Agent' => 'MyInvoice-Databox/1.0',
                ],
                'body' => $soapEnvelope,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // The exception message may embed the request URL but never the Basic header, still keep it generic.
            throw new IsdsException('network', 'Datovou schránku se nepodařilo kontaktovat (síťová chyba). Zkuste to později.', previous: $e);
        }
        return new IsdsTransportResponse($response->getStatusCode(), (string) $response->getBody());
    }
}
