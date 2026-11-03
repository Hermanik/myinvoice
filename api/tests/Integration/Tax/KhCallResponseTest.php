<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Report\KontrolniHlaseniAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\KhFiling;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Kontrolní hlášení as an answer to a call of the tax office (§ 101g ZDPH): the follow-up KH carrying the reference
 * number of the call (`c_jed_vyzvy`) and the quick answers (`vyzva_odp` B / P) without the sections A, B and C.
 *
 * Isolated period: June 2050; everything is removed in tearDown.
 */
#[Group('integration')]
final class KhCallResponseTest extends TestCase
{
    private const REFERENCE = '1234567/50/2100-23542-501001';

    private ?FilingFixture $fx = null;
    private KontrolniHlaseniBuilder $kh;
    private TaxSubmissionArchiver $archiver;
    private TaxSubmissionFilingService $filing;
    private int $filedSeq = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $c = $this->fx->container;
        $this->kh = $c->get(KontrolniHlaseniBuilder::class);
        $this->archiver = $c->get(TaxSubmissionArchiver::class);
        $this->filing = $c->get(TaxSubmissionFilingService::class);
        $this->fx->payer();
        $this->fx->configureSupplier(['dic' => 'CZ12345678', 'financial_office_code' => '451', 'taxpayer_type' => 'fo', 'vat_period' => 'monthly']);
        $customer = $this->fx->client('Odběratel s DIČ', null, 'CZ11111118');
        $vendor = $this->fx->client('Dodavatel s DIČ', null, 'CZ22222220');
        $this->fx->sale('2050060001', $customer, '2050-06-10', 20_000, 4_200, 21);                 // A.4
        $this->fx->purchase('P-2050-0601', $vendor, '2050-06-12', 10_000, 2_100, 21, '40');         // B.2
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    private function sid(): int
    {
        return $this->fx->supplierId;
    }

    /** @return array{id:int, xml:string, summary:array<string,mixed>, warnings:list<string>, validation:string} */
    private function generate(string $form = 'B', ?string $dZjist = null, ?string $ref = null, ?string $answer = null): array
    {
        $result = $this->kh->build($this->sid(), 2050, 6, 'monthly', new KhFiling($form, $dZjist, $ref, $answer));
        $archived = $this->archiver->archive($this->sid(), 'dphkh1', 2050, 6, null, $result['xml'], $result['summary'], $this->fx->userId);
        $this->fx->trackSubmission($archived['submission_id']);
        self::assertSame('passed', $archived['validation_status'], implode('; ', $archived['validation_errors']));
        return ['id' => $archived['submission_id'], 'validation' => $archived['validation_status']] + $result;
    }

    private function file(array $generated): void
    {
        $this->filing->markFiled($this->sid(), $generated['id'], sprintf('2026-10-%02d 10:00', 1 + ++$this->filedSeq), 'epo', null, $this->fx->userId);
    }

    /** @return array<string,string> */
    private function vetaD(array $result): array
    {
        return array_map('strval', iterator_to_array((new \SimpleXMLElement($result['xml']))->DPHKH1->VetaD->attributes()));
    }

    public function testFollowUpKhAnsweringACallCarriesTheReferenceInsteadOfTheDiscoveryDate(): void
    {
        $result = $this->generate('N', null, self::REFERENCE);

        $vetaD = $this->vetaD($result);
        self::assertSame('N', $vetaD['khdph_forma']);
        self::assertSame(self::REFERENCE, $vetaD['c_jed_vyzvy']);
        self::assertArrayNotHasKey('d_zjist', $vetaD, 'the reference replaces the date (XSD: either one)');
        self::assertArrayNotHasKey('vyzva_odp', $vetaD);
        $xml = (new \SimpleXMLElement($result['xml']))->DPHKH1;
        self::assertCount(1, $xml->VetaA4, 'a follow-up KH is complete: all sections');
        self::assertCount(1, $xml->VetaB2);
        self::assertCount(1, $xml->VetaC);
        self::assertSame(['reference' => self::REFERENCE, 'answer' => null], $result['summary']['call']);
        self::assertSame('N', $result['summary']['form']);
    }

    public function testFollowUpKhMayCarryBothTheDateAndTheReference(): void
    {
        $vetaD = $this->vetaD($this->generate('E', '05.10.2026', self::REFERENCE));

        self::assertSame('E', $vetaD['khdph_forma']);
        self::assertSame('05.10.2026', $vetaD['d_zjist']);
        self::assertSame(self::REFERENCE, $vetaD['c_jed_vyzvy']);
    }

    public function testQuickAnswerConfirmingTheLastKhHasNoSections(): void
    {
        $result = $this->generate('B', null, self::REFERENCE, 'P');

        $vetaD = $this->vetaD($result);
        self::assertSame('P', $vetaD['vyzva_odp']);
        self::assertSame(self::REFERENCE, $vetaD['c_jed_vyzvy']);
        self::assertSame('B', $vetaD['khdph_forma']);
        $kh = (new \SimpleXMLElement($result['xml']))->DPHKH1;
        self::assertSame(['VetaD', 'VetaP'], array_map(static fn ($n): string => $n->getName(), iterator_to_array($kh->children(), false)), 'rows in A, B or C would make the filing ineffective');
        self::assertSame('B', $result['summary']['form'], 'the XML form');
        self::assertSame('V', $result['summary']['archive_form']);
        self::assertSame(['reference' => self::REFERENCE, 'answer' => 'P'], $result['summary']['call']);
        self::assertSame(0, $result['summary']['a4_count'], 'the summary keeps the shape of a full KH');
        $stored = $this->fx->pdo->query("SELECT submission_form FROM tax_submissions WHERE id = {$result['id']}")->fetchColumn();
        self::assertSame('V', $stored, 'archived as its own form, not as the regular KH of the period');
    }

    public function testQuickAnswerStatingNoObligationIsAnEmptyKhToo(): void
    {
        $result = $this->generate('B', null, self::REFERENCE, 'B');

        self::assertSame('B', $this->vetaD($result)['vyzva_odp']);
        self::assertCount(0, (new \SimpleXMLElement($result['xml']))->DPHKH1->VetaC);
        self::assertCount(0, (new \SimpleXMLElement($result['xml']))->DPHKH1->VetaA4);
    }

    public function testInvalidCombinationsAreRefused(): void
    {
        $bad = [
            'N without date and reference'     => ['N', null, null, null],
            'malformed reference'              => ['B', null, '123/45', null],
            'quick answer without reference'   => ['B', null, null, 'P'],
            'unknown quick answer'             => ['B', null, self::REFERENCE, 'X'],
            'quick answer on a follow-up KH'   => ['N', '05.10.2026', self::REFERENCE, 'P'],
        ];
        foreach ($bad as $label => [$form, $dZjist, $ref, $answer]) {
            try {
                new KhFiling($form, $dZjist, $ref, $answer);
                self::fail("{$label} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSeveralFollowUpAndQuickAnswersCanBeFiledForOnePeriodButOnlyOneRegularKh(): void
    {
        $b = $this->generate('B');
        $this->file($b);
        foreach ([$this->generate('N', null, self::REFERENCE), $this->generate('N', '05.10.2026'), $this->generate('B', null, self::REFERENCE, 'P'), $this->generate('B', null, self::REFERENCE, 'P')] as $answer) {
            $this->file($answer);
        }

        try {
            $this->file($this->generate('B'));
            self::fail('a second regular KH of the period is a duplicate');
        } catch (TaxFilingException $e) {
            self::assertSame('duplicate_filing', $e->errorCode);
        }
    }

    public function testReportEndpointPassesTheCallParameters(): void
    {
        $action = $this->fx->container->get(KontrolniHlaseniAction::class);
        $query = ['year' => '2050', 'month' => '6', 'form' => 'B', 'c_jed_vyzvy' => self::REFERENCE, 'vyzva_odp' => 'P'];

        $preview = $action->preview($this->request($query), new Response());
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('V', json_decode((string) $preview->getBody(), true)['summary']['archive_form']);

        foreach ([['c_jed_vyzvy' => 'x'], ['vyzva_odp' => 'P'], ['form' => 'N'], ['vyzva_odp' => 'Q'], ['form' => 'N', 'vyzva_odp' => 'P']] as $badQuery) {
            $bad = $action->download($this->request($badQuery + $query, $badQuery === ['vyzva_odp' => 'P'] ? ['c_jed_vyzvy'] : []), new Response());
            self::assertSame(400, $bad->getStatusCode(), json_encode($badQuery));
        }

        $download = $action->download($this->request($query), new Response());
        self::assertSame(200, $download->getStatusCode());
        self::assertStringContainsString('-odpoved.xml', $download->getHeaderLine('Content-Disposition'));
        $rows = $this->fx->pdo->query("SELECT id, submission_form FROM tax_submissions WHERE supplier_id = {$this->sid()} AND form_code = 'dphkh1' AND period_year = 2050")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['V'], array_column($rows, 'submission_form'));
        $this->fx->trackSubmission((int) $rows[0]['id']);
    }

    /**
     * @param array<string,string> $query
     * @param list<string> $without parameters to drop
     */
    private function request(array $query, array $without = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/dphkh1')
            ->withQueryParams(array_diff_key($query, array_flip($without)))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => 'admin']);
    }
}
