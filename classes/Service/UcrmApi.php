<?php

namespace App\Service;

use RuntimeException;

class UcrmApi
{

    /**
     * @param string $url
     * @param string $method
     * @param array $postData
     *
     * @return array|null
     *
     * @throws RuntimeException when cURL error occurred or API returned an error status code
     */

    public static function doRequest(string $url, string $method = 'GET', array $postData = []): ?array
    {
        $method = strtoupper($method);

        $ch = curl_init();

        curl_setopt(
            $ch,
            CURLOPT_URL,
            sprintf('%s/%s', $_ENV['API_URL'], $url)
        );

        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $headers = [
            'Content-Type: application/json',
            sprintf('X-Auth-App-Key: %s', $_ENV['API_KEY']),
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }

        $response = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new RuntimeException(sprintf('cURL error: %s', curl_error($ch)));
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode >= 400) {
            $errorMessage = 'API error';
            if ($response !== false) {
                $errorData = json_decode($response, true);
                if (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                }
            }
            throw new RuntimeException(sprintf('%s (status code %d)', $errorMessage, $statusCode));
        }


        curl_close($ch);

        return $response !== false ? json_decode($response, true) : null;
    }
}

// Setting unlimited time limit (updating lots of clients can take a long time).
set_time_limit(0);
