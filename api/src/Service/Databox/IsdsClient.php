<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * SOAP client of the ISDS web services (stateless, HTTP Basic). Every public method corresponds to ONE
 * documented operation; see {@see DataboxPrivileges} for who may call what and which calls deliver messages.
 *
 * Calls that LEGALLY DELIVER received messages (start deadlines) when the user may read them:
 *   - {@see listReceived()}  (the only such operation, WS spec 1.8.1)
 * Everything else here never delivers.
 *
 * Specification: WS_manipulace_s_datovymi_zpravami.pdf 3.8.1, WS_souvisejici_s_pristupem_do_ISDS.pdf 3.8.1,
 * WS_vyhledavani_datovych_schranek.pdf 3.8 (https://datovka.gov.cz/info/files/2256_Provozni_rad_ISDS_26_06_2026.zip, pril_2).
 */
final class IsdsClient
{
    public function __construct(private readonly IsdsTransport $transport) {}

    // ---- login information (read-only, no privilege needed) ----

    /** GetOwnerInfoFromLogin2: the box the login belongs to. @return array{db_id:?string, db_type:?string, ic:?string, name:?string, state:?int} */
    public function ownerInfo(IsdsAccess $access): array
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_ACCESS, IsdsRequestBuilder::getOwnerInfo(), 'GetOwnerInfoFromLogin2Response');
        return IsdsResponseParser::ownerInfo($r);
    }

    /** GetUserInfoFromLogin2: who is logged in and with which privileges. @return array{user_type:?string, privileges:int, isds_id:?string, name:?string} */
    public function userInfo(IsdsAccess $access): array
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_ACCESS, IsdsRequestBuilder::getUserInfo(), 'GetUserInfoFromLogin2Response');
        return IsdsResponseParser::userInfo($r);
    }

    /** GetPasswordInfo: when the password expires (null = never). */
    public function passwordExpiry(IsdsAccess $access): ?\DateTimeImmutable
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_ACCESS, IsdsRequestBuilder::getPasswordInfo(), 'GetPasswordInfoResponse');
        return IsdsResponseParser::passwordExpiry($r);
    }

    // ---- lists ----

    /**
     * GetListOfReceivedMessages. WARNING: with a user that may READ messages (PRIVIL_READ_*) this call
     * delivers every listed undelivered message (state 4/5 -> 6). Callers must check
     * {@see DataboxPrivileges::deliversOnList()} first.
     *
     * @return list<array<string,mixed>> envelopes
     */
    public function listReceived(IsdsAccess $access, \DateTimeInterface $from, \DateTimeInterface $to, int $statusFilter = -1, int $offset = 1, int $limit = 1000): array
    {
        $body = IsdsRequestBuilder::listOfMessages('GetListOfReceivedMessages', $from, $to, $statusFilter, $offset, $limit);
        $r = $this->call($access, IsdsEnvironment::SERVICE_INFO, $body, 'GetListOfReceivedMessagesResponse');
        return IsdsResponseParser::messageRecords($r);
    }

    /** GetListOfSentMessages (never delivers anything). @return list<array<string,mixed>> */
    public function listSent(IsdsAccess $access, \DateTimeInterface $from, \DateTimeInterface $to, int $statusFilter = -1, int $offset = 1, int $limit = 1000): array
    {
        $body = IsdsRequestBuilder::listOfMessages('GetListOfSentMessages', $from, $to, $statusFilter, $offset, $limit);
        $r = $this->call($access, IsdsEnvironment::SERVICE_INFO, $body, 'GetListOfSentMessagesResponse');
        return IsdsResponseParser::messageRecords($r);
    }

    // ---- delivery information ----

    /** GetDeliveryInfo (needs PRIVIL_VIEW_INFO). @return array{xml:string, status:?int, delivery_time:?\DateTimeImmutable, acceptance_time:?\DateTimeImmutable, events:list<array{time:?\DateTimeImmutable, text:string}>} */
    public function deliveryInfo(IsdsAccess $access, string $dmId): array
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_INFO, IsdsRequestBuilder::byMessageId('GetDeliveryInfo', $dmId), 'GetDeliveryInfoResponse');
        return IsdsResponseParser::deliveryInfo($r);
    }

    /** GetSignedDeliveryInfo: the delivery receipt sealed by ISDS (ZFO bytes), needs PRIVIL_VIEW_INFO. */
    public function signedDeliveryInfo(IsdsAccess $access, string $dmId): string
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_INFO, IsdsRequestBuilder::byMessageId('GetSignedDeliveryInfo', $dmId), 'GetSignedDeliveryInfoResponse');
        return IsdsResponseParser::signature($r);
    }

    // ---- downloads (never deliver; received ones fail with 1222 until the message is delivered) ----

    /** SignedMessageDownload: the sealed received message (ZFO bytes). Needs PRIVIL_READ_*. */
    public function downloadReceived(IsdsAccess $access, string $dmId): string
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_MESSAGES, IsdsRequestBuilder::byMessageId('SignedMessageDownload', $dmId), 'SignedMessageDownloadResponse');
        return IsdsResponseParser::signature($r);
    }

    /** SignedSentMessageDownload: the sealed sent message (ZFO bytes). Needs PRIVIL_CREATE_DM. */
    public function downloadSent(IsdsAccess $access, string $dmId): string
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_MESSAGES, IsdsRequestBuilder::byMessageId('SignedSentMessageDownload', $dmId), 'SignedSentMessageDownloadResponse');
        return IsdsResponseParser::signature($r);
    }

    /**
     * downloadSent() for a message that was just sent: it is not downloadable for a moment (error 1229, WS spec 1.11.3),
     * so the call is repeated after a short pause.
     */
    public function downloadSentAfterSend(IsdsAccess $access, string $dmId, int $attempts = 2, int $pauseSeconds = 2): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->downloadSent($access, $dmId);
            } catch (IsdsException $e) {
                if ($e->reason !== 'message_not_ready' || $attempt >= $attempts) {
                    throw $e;
                }
                sleep($pauseSeconds);
            }
        }
    }

    // ---- sending ----

    /**
     * CreateMessage (needs PRIVIL_CREATE_DM). Does not deliver received messages of the sender box.
     *
     * @param array<string,mixed> $envelope see {@see IsdsRequestBuilder::createMessage()}
     * @param list<array{name:string, mime:string, meta_type:string, bytes:string}> $files
     * @return string the new message number (dmID)
     */
    public function createMessage(IsdsAccess $access, array $envelope, array $files): string
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_MESSAGES, IsdsRequestBuilder::createMessage($envelope, $files), 'CreateMessageResponse');
        return IsdsResponseParser::createdMessageId($r);
    }

    // ---- search ----

    /**
     * FindDataBox2 (needs PRIVIL_SEARCH_DB).
     *
     * @param array{db_id?:?string, ic?:?string, name?:?string} $criteria
     * @return array{status_code:string, boxes:list<array{db_id:?string, db_type:?string, ic:?string, name:?string, state:?int}>}
     */
    public function findDataBox(IsdsAccess $access, array $criteria): array
    {
        $r = $this->call($access, IsdsEnvironment::SERVICE_SEARCH, IsdsRequestBuilder::findDataBox($criteria), 'FindDataBox2Response');
        return [
            'status_code' => IsdsResponseParser::status($r)['code'],
            'boxes' => IsdsResponseParser::foundBoxes($r),
        ];
    }

    // ---- plumbing ----

    /**
     * One round trip. Maps HTTP / SOAP / ISDS status failures to {@see IsdsException}. Information statuses
     * (00xx, e.g. 0002 "nothing found") are not failures.
     */
    private function call(IsdsAccess $access, string $service, string $envelope, string $responseElement): \DOMElement
    {
        try {
            $url = IsdsEnvironment::endpoint($access->environment, $service);
            $response = $this->transport->post($url, $access->credentials, $envelope);

            if ($response->status !== 200) {
                // SOAP faults come with HTTP 500; prefer their text, otherwise map the HTTP status.
                if ($response->status >= 500 && $response->status !== 503 && stripos($response->body, 'Fault') !== false) {
                    IsdsResponseParser::operationElement($response->body, $responseElement); // throws the fault
                }
                throw IsdsException::fromHttp($response->status, $response->body);
            }

            $element = IsdsResponseParser::operationElement($response->body, $responseElement);
            IsdsResponseParser::assertSuccess($element);
            return $element;
        } catch (IsdsException $e) {
            // The single choke point of every ISDS call: a refused login is recorded before anything else can retry it.
            if ($e->isAuthFailure() && $access->onAuthFailure !== null) {
                ($access->onAuthFailure)($e);
            }
            throw $e;
        }
    }
}
