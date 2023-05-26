<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use DOMElement;
use DOMDocument;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;

class XmlGenerator
{
    /**
     * @var string[]
     */
    private $stateMap;

    /**
     * @var string[]
     */
    private $countryMap;

    /**
     * @var UcrmApi
     */
    private $ucrmApi;


    public function __construct(array $countries, array $states, UcrmApi $ucrmApi = null)
    {
        $this->countryMap = $this->mapCountries($countries);
        $this->stateMap = $this->mapStates($states);

        $this->ucrmApi = $ucrmApi ?: UcrmApi::create();
    }

    public function generateXml(array $invoices, int $chunkSize, bool $tva): array
    {
        try {
            // Split the array into chunks.
            $invoiceChunks = array_chunk($invoices, $chunkSize);

            // Create a temporary directory.
            $xmlStrings = array();

            // Generate XML for each chunk.
            foreach ($invoiceChunks as $invoiceChunk) {
                $xmlDoc = new DOMDocument('1.0', 'UTF-8');
                $xmlDoc->formatOutput = true;

                // Create the root element.
                $invoicesRoot = $xmlDoc->appendChild($xmlDoc->createElement('Facturi'));

                foreach ($invoiceChunk as $invoiceItem) {
                    $clientName = $invoiceItem['clientFirstName'] . ' ' . $invoiceItem['clientLastName'];
                    $companyName = $invoiceItem['clientCompanyName'] ?? '';
                    $client = $this->ucrmApi->get(sprintf('clients/%s', $invoiceItem['clientId']));
                    $clientType = $client['clientType'];
                    $fullName = $clientType === 2 ? $companyName : $clientName;
                    $clientCNP = $this->getClientCNP($invoiceItem);
                    $clientRegistrationNumber = $clientType === 2 ? ($client['companyRegistrationNumber'] ? $client['companyRegistrationNumber'] : 'NULL') : '';
                    $clientAddress = $this->formatClientAddress($invoiceItem);
                    $createdDate = (new \DateTimeImmutable($invoiceItem['createdDate']))->format('d.m.Y');
                    $dueDate = (new \DateTimeImmutable($invoiceItem['dueDate']))->format('d.m.Y');
                    $invoiceNumber = $invoiceItem['number'];
                    $invoiceAmount = $invoiceItem['total'];

                    $invoiceRoot = $invoicesRoot->appendChild($xmlDoc->createElement('Factura'));
                    $invoiceHead = $invoiceRoot->appendChild($xmlDoc->createElement('Antet'));

                    $this->createInvoiceHeader($xmlDoc, $invoiceHead, $fullName, $clientCNP, $clientRegistrationNumber, $clientAddress, $invoiceNumber, $createdDate, $dueDate);
                    $this->createInvoiceDetails($xmlDoc, $invoiceRoot, $invoiceNumber, $fullName, $invoiceAmount, $tva);
                    $this->createInvoiceSummary($xmlDoc, $invoiceRoot, $invoiceAmount, $tva);
                    $this->createInvoiceObservations($xmlDoc, $invoiceRoot);
                }

                $xmlStrings[] = $xmlDoc->saveXML();
            }

            return $xmlStrings ?? [];
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    private function createInvoiceHeader(DOMDocument $xmlDoc, DOMElement $invoiceHead, string $fullName, string $clientCNP, string $clientRegistrationNumber, string $clientAddress, string $invoiceNumber, string $createdDate, string $dueDate): void
    {
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorNume', "ZERO SAPTE SERVICES S.R.L"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorCIF', "RO45858226"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorRegCom', "J13/1003/2022"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorCapital', "200.00"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorAdresa', "Navodari str. Bv Mamaia Nord nr. 6 bl. Centrul eAfaceri ap. 01-05 jud. CONSTANTA"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorBanca', "Banca Comerciala Romana S.A."));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorBancaIBAN', "RO51 RNCB 0119 1723 6788 0001"));
        $invoiceHead->appendChild($xmlDoc->createElement('FurnizorInformatiiSuplimentare', "Tel. 0241700000 Email stefan@sel.ro"));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientNume', htmlentities($fullName)));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientInformatiiSuplimentare'));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientCIF', $clientCNP));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientNrRegCom', $clientRegistrationNumber));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientTara', 'RO'));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientAdresa', $clientAddress));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientBanca'));
        $invoiceHead->appendChild($xmlDoc->createElement('ClientIBAN'));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaNumar', $invoiceNumber));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaData', $createdDate));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaScadenta', $dueDate));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaTaxareInversa', 'NU'));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaTVAIncasare', 'NU'));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaInformatiiSuplimentare'));
        $invoiceHead->appendChild($xmlDoc->createElement('FacturaMoneda', 'RON'));
    }

    private function createInvoiceDetails(DOMDocument $xmlDoc, DOMElement $invoiceRoot, string $invoiceNumber,  string $fullName, float $invoiceAmount, bool $tva): void
    {
        $tvaAmount = $invoiceAmount - ($invoiceAmount / 1.19);

        $invoiceDetails = $invoiceRoot->appendChild($xmlDoc->createElement('Detalii'));
        $invoiceContent = $invoiceDetails->appendChild($xmlDoc->createElement('Continut'));
        $invoiceLine = $invoiceContent->appendChild($xmlDoc->createElement('Linie'));
        $invoiceLine->appendChild($xmlDoc->createElement('LiniNrCrt', $invoiceNumber));
        $invoiceLine->appendChild($xmlDoc->createElement('Descriere', htmlentities($fullName)));
        $invoiceLine->appendChild($xmlDoc->createElement('CodArticolFurnizor'));
        $invoiceLine->appendChild($xmlDoc->createElement('CodArticolClient'));
        $invoiceLine->appendChild($xmlDoc->createElement('InformatiiSuplimentare'));
        $invoiceLine->appendChild($xmlDoc->createElement('UM', 'buc'));
        $invoiceLine->appendChild($xmlDoc->createElement('Cantitate', '1'));
        $invoiceLine->appendChild($xmlDoc->createElement('Pret', sprintf('%.2f', $invoiceAmount)));
        $invoiceLine->appendChild($xmlDoc->createElement('Valoare', sprintf('%.2f', $invoiceAmount)));
        $invoiceLine->appendChild($xmlDoc->createElement('ProcTVA', $tva === true ? '19' : '0'));
        $invoiceLine->appendChild($xmlDoc->createElement('TVA', $tva === true ? sprintf('%.2f', $tvaAmount) : '0'));
    }

    private function createInvoiceSummary(DOMDocument $xmlDoc, DOMElement $invoiceRoot, float $invoiceAmount, bool $tva): void
    {
        $tvaAmount = $invoiceAmount - ($invoiceAmount / 1.19);

        $invoiceSummary = $invoiceRoot->appendChild($xmlDoc->createElement('Sumar'));
        $invoiceSummary->appendChild($xmlDoc->createElement('TotalValoare', sprintf('%.2f', $invoiceAmount)));
        $invoiceSummary->appendChild($xmlDoc->createElement('TotalTVA', $tva === true ? sprintf('%.2f', $tvaAmount) : '0'));
        $invoiceSummary->appendChild($xmlDoc->createElement('TotalFactura', sprintf('%.2f', $invoiceAmount)));
    }

    private function createInvoiceObservations(DOMDocument $xmlDoc, DOMElement $invoiceRoot): void
    {
        $invoiceObs = $invoiceRoot->appendChild($xmlDoc->createElement('Observatii'));
        $invoiceObs->appendChild($xmlDoc->createElement('txtObservatii'));
        $invoiceObs->appendChild($xmlDoc->createElement('SoldClient'));
    }

    public function createDownloadLinks(array $xmlStrings): array
    {
        $downloadLinks = array_map(function ($xmlString) {
            $randNo = md5(serialize($xmlString));
            $today = date('d-m-Y');
            $fileName = "F_45858226_$randNo" . "_$today.xml";
            $urlEncodedXmlString = rawurlencode($xmlString);
            $downloadLink = "<a href=\"data:application/xml;charset=utf-8,$urlEncodedXmlString\" download=\"$fileName\" class='btn btn-primary btn-sm pl-4 pr-4 mb-2'>Download $fileName</a>";

            return $downloadLink;
        }, $xmlStrings);

        return $downloadLinks ?? [];
    }


    private function formatClientAddress(array $invoice)
    {
        return sprintf(
            '%s, %s, %s, %s',
            $invoice['clientStreet1'] . ($invoice['clientStreet2'] ? ', ' . $invoice['clientStreet2'] : ''),
            $invoice['clientCity'],
            $invoice['clientZipCode'],
            $this->formatCountry($invoice['clientCountryId'], $invoice['clientStateId'])
        );
    }

    private function getClientCNP(array $invoice)
    {
        $client = $this->ucrmApi->get('clients/' . $invoice['clientId']);

        if ($client['clientType'] === 2) {
            return $client['companyTaxId'];
        } else {
            $customFields = $client['attributes'];


            // Get the CNP custom field.
            $cnpCustomField = array_filter($customFields, function ($customField) {
                return $customField['key'] === 'cnp';
            });

            // Return the CNP value.
            return array_values($cnpCustomField)[0]['value'] ?? '';
        }
    }

    private function formatCountry(?int $countryId, ?int $stateId): string
    {
        if ($countryId === null) {
            return '';
        }

        if ($stateId !== null) {
            return ($this->stateMap[$stateId] ?? '') . ', ' . ($this->countryMap[$countryId] ?? '');
        }

        return $this->countryMap[$countryId] ?? '';
    }

    private function mapCountries(array $countries): array
    {
        $map = [];

        foreach ($countries as $country) {
            $map[$country['id']] = $country['name'];
        }

        return $map;
    }

    private function mapStates(array $states): array
    {
        $map = [];

        foreach ($states as $state) {
            $map[$state['id']] = $state['name'];
        }

        return $map;
    }
}
