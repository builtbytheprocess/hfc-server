<?php
namespace HFC\Api;

/**
 * Chat-originated SMS requests. User enters phone + message; we forward to FCO as a lead with
 * source "HFC Chat" and the message in notes. FCO's Quo integration handles outbound SMS from there.
 */
class SmsEndpoint
{
    public function __construct(
        private FcoClient $fco,
        private RateLimiter $limiter
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hfc/v1', '/sms-request', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $request)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!$this->limiter->allow($ip)) {
            return new \WP_REST_Response(['error' => 'Too many requests'], 429);
        }

        $body = $request->get_json_params() ?? [];
        if (!empty($body['website'])) {
            return new \WP_REST_Response(['success' => true], 200);
        }

        $phone = trim((string)($body['phone'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));

        if ($phone === '' || !preg_match('/\d{10,}/', preg_replace('/\D/', '', $phone))) {
            return new \WP_REST_Response(['error' => 'Valid phone required'], 422);
        }

        $result = $this->fco->createLead([
            'firstName' => 'Chat',
            'lastName'  => 'Visitor',
            'phone'     => $phone,
            'notes'     => $message !== '' ? "Chat message: $message" : 'Requested SMS contact from homepage chat widget',
            'source'    => 'HFC Chat',
        ]);

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return new \WP_REST_Response(['success' => true], 200);
        }

        return new \WP_REST_Response(['error' => 'Upstream error'], 502);
    }
}
