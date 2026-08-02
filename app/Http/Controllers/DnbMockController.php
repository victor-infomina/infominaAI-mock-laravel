<?php

namespace App\Http\Controllers;

use App\Services\DnbCaseRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DnbMockController extends Controller
{
    public function __construct(private readonly DnbCaseRepository $cases)
    {
    }

    public function handle(Request $request): Response
    {
        $xml = $request->getContent();
        $product = $this->extractXmlValue($xml, 'PRODUCT');

        return match ($product) {
            'XCNS' => $this->searchSingapore($xml),
            'XICNS' => $this->searchIndonesia($xml),
            default => $this->xmlResponse('<REPORT></REPORT>'),
        };
    }

    private function searchSingapore(string $xml): Response
    {
        $type = $this->extractXmlValue($xml, 'COMPANY_SEARCH_TYPE');
        $value = $this->extractXmlValue($xml, 'COMPANY_SEARCH_VALUE');

        if ($type === null || $value === null) {
            return $this->xmlResponse('<REPORT><List-CompanyB2b></List-CompanyB2b></REPORT>');
        }

        $matches = $type === 'REG'
            ? array_filter([$this->cases->findByRegNo('singapore', $value)])
            : $this->cases->searchByName('singapore', $value);

        $fragments = array_map(fn (array $case) => sprintf(
            '<CompanyB2b><Status>Live</Status><CompanyName>%s</CompanyName><CompanyType>LOCAL COMPANY</CompanyType><RegNo>%s</RegNo><NameStatus>Registered</NameStatus></CompanyB2b>',
            $this->escapeXml($case['meta']['companyName']),
            $this->escapeXml($case['meta']['regNo']),
        ), $matches);

        return $this->xmlResponse('<REPORT><List-CompanyB2b>'.implode('', $fragments).'</List-CompanyB2b></REPORT>');
    }

    private function searchIndonesia(string $xml): Response
    {
        $value = $this->extractXmlValue($xml, 'COMPANY_NAME');
        $matches = $value !== null ? $this->cases->searchByName('indonesia', $value) : [];

        $fragments = array_map(fn (array $case) => sprintf(
            '<COMPANY><COMPANY_ID>%s</COMPANY_ID><COMPANY_NAME>%s</COMPANY_NAME><COMPANY_LOB></COMPANY_LOB><FULL_ADDRESSS></FULL_ADDRESSS><REVISION_TYPE_NAME></REVISION_TYPE_NAME><REPORT_STATUS>Completed</REPORT_STATUS><REPORT_DATE></REPORT_DATE></COMPANY>',
            $this->escapeXml($case['meta']['companyId']),
            $this->escapeXml($case['meta']['companyName']),
        ), $matches);

        return $this->xmlResponse('<REPORT><LIST-COMPANY>'.implode('', $fragments).'</LIST-COMPANY></REPORT>');
    }

    private function xmlResponse(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    private function extractXmlValue(string $xml, string $tag): ?string
    {
        return preg_match("#<{$tag}>([^<]*)</{$tag}>#", $xml, $matches) ? trim($matches[1]) : null;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
