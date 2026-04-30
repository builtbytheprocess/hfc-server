<?php
namespace HFC\Api;

class FcoClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {}

    /**
     * POST a lead payload to FCO. Returns ['status' => int, 'data' => array, 'error' => string|null].
     */
    public function createLead(array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/leads';

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'data' => [], 'error' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true) ?? [];

        return ['status' => $status, 'data' => $data, 'error' => null];
    }
}
