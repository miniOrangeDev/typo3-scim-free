<?php

declare(strict_types=1);

namespace Miniorange\Scim\Helper;

class CustomerMo
{
    public function submit_contact(string $email, string $phone, string $query): string|false
    {
        $url = Constants::HOSTNAME . "/moas/rest/customer/contact-us";
        $query = '[' . Constants::AREA_OF_INTEREST . ']: ' . $query;
        $customerKey = Constants::CUSTOMER_KEY;
        $apiKey = Constants::CUSTOMER_API_KEY;

        
        $fields = array(
            'company' => $_SERVER['SERVER_NAME'],
            'email' => $email,
            'phone' => $phone,
            'query' => $query,
            'ccEmail' => 'magentosupport@xecurify.com'
        );

        $authHeader = $this->createAuthHeader($customerKey, $apiKey);
        $response = $this->callAPI($url, $fields, $authHeader);

        return $response;
    }

    /**
     * @return list<string>
     */
    public function createAuthHeader(string $customerKey, string $apiKey): array
    {
        $currentTimestampInMillis = number_format(round(microtime(true) * 1000), 0, '', '');
        $authHeader = hash('sha512', $customerKey . $currentTimestampInMillis . $apiKey);

        return [
            'Content-Type: application/json',
            "Customer-Key: $customerKey",
            "Timestamp: $currentTimestampInMillis",
            "Authorization: $authHeader",
        ];
    }

    /**
     * @param array<string, mixed> $jsonData
     * @param list<string> $headers
     */
    public function callAPI(string $url, array $jsonData = [], array $headers = ['Content-Type: application/json']): string|false
    {
        if ($url === '') {
            throw new \InvalidArgumentException('URL must not be empty.');
        }

        $data = in_array('Content-Type: application/x-www-form-urlencoded', $headers, true)
            ? (!empty($jsonData) ? http_build_query($jsonData) : '')
            : (!empty($jsonData) ? json_encode($jsonData) : '');

        $method = !empty($data) ? 'POST' : 'GET';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);
        curl_close($ch);

        // PHPStan cannot infer CURLOPT_RETURNTRANSFER, so curl_exec() is typed as bool|string.
        return is_string($response) ? $response : false;
    }
}
