<?php

declare(strict_types=1);

use App\Http;
use Dotenv\Dotenv;
use Twig\Environment;
use App\Service\XmlGenerator;
use Twig\Loader\FilesystemLoader;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use Ubnt\UcrmPluginSdk\Security\PermissionNames;
use Ubnt\UcrmPluginSdk\Service\UcrmOptionsManager;

chdir(__DIR__);

require __DIR__ . '/vendor/autoload.php';

// Retrieve API connection.
$api = UcrmApi::create();

// Load .env file.
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Ensure that user is logged in and has permission to view invoices.
$security = UcrmSecurity::create();
$user = $security->getUser();
if (!$user || $user->isClient || !$user->hasViewPermission(PermissionNames::BILLING_INVOICES)) {
    Http::forbidden();
}

// Instantiate Twig template engine.
$twigLoader = new FilesystemLoader(__DIR__ . '/templates');
$twig = new Environment($twigLoader);

// Process submitted form.
if (array_key_exists('organization', $_GET) && array_key_exists('since', $_GET) && array_key_exists('until', $_GET)) {
    $parameters = [
        'organizationId' => $_GET['organization'],
        'createdDateFrom' => $_GET['since'],
        'createdDateTo' => $_GET['until'],
        'proforma' => false
    ];

    // make sure the dates are in YYYY-MM-DD format
    if ($parameters['createdDateFrom']) {
        $parameters['createdDateFrom'] = new \DateTimeImmutable($parameters['createdDateFrom']);
        $parameters['createdDateFrom'] = $parameters['createdDateFrom']->format('Y-m-d');
    }
    if ($parameters['createdDateTo']) {
        $parameters['createdDateTo'] = new \DateTimeImmutable($parameters['createdDateTo']);
        $parameters['createdDateTo'] = $parameters['createdDateTo']->format('Y-m-d');
    }

    $countries = $api->get('countries');
    $states = array_merge(
        // Canada
        $api->get('countries/states?countryId=54'),
        // USA
        $api->get('countries/states?countryId=249')
    );

    $xmlGenerator = new XmlGenerator($countries, $states);

    $invoices = $api->get('invoices', $parameters);


    if (array_key_exists('tva', $_GET)) {
        switch ($_GET['tva']) {
            case '0':
                $xmlStrings = $xmlGenerator->generateXml($invoices, 100, false);
                $downloadLinks = $xmlGenerator->createDownloadLinks($xmlStrings);

                break;
            case '1':
                $xmlStrings = $xmlGenerator->generateXml($invoices, 100, true);
                $downloadLinks = $xmlGenerator->createDownloadLinks($xmlStrings);

                break;
        }
    }
}

// Render form.
$organizations = $api->get('organizations');

$optionsManager = UcrmOptionsManager::create();

echo $twig->render(
    'form.twig.html',
    [
        'title' => 'Export facturi in XML',
        'organizations' => $organizations,
        'ucrmPublicUrl' => $optionsManager->loadOptions()->ucrmPublicUrl,
        'downloadLinks' => $downloadLinks ?? [],
    ]
);
