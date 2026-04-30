<?php
namespace HFC\Api;

class LeadsEndpoint
{
    public function __construct(
        private FcoClient $fco,
        private RateLimiter $limiter
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hfc/v1', '/lead', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $request)
    {
        // 1. Rate limit
        $ip = $this->clientIp();
        if (!$this->limiter->allow($ip)) {
            return new \WP_REST_Response(['error' => 'Too many requests. Try again later.'], 429);
        }

        // 2. Pull fields from EITHER JSON body OR multipart form-data
        $contentType = $request->get_content_type()['value'] ?? '';
        if (str_contains($contentType, 'multipart/form-data')) {
            $body  = $request->get_body_params() ?? [];
            $files = $request->get_file_params() ?? [];
        } else {
            $body  = $request->get_json_params() ?? [];
            $files = [];
        }

        // 3. Honeypot — silent success if tripped
        if (!empty($body['website'])) {
            return new \WP_REST_Response(['success' => true], 200);
        }

        // 4. Normalize name (forms may send fullName OR firstName + lastName)
        $firstName = trim((string)($body['firstName'] ?? ''));
        $lastName  = trim((string)($body['lastName']  ?? ''));
        $fullName  = trim((string)($body['fullName']  ?? ''));
        if ($fullName !== '' && ($firstName === '' || $lastName === '')) {
            $parts = preg_split('/\s+/', $fullName, 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }

        $phone = trim((string)($body['phone'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $leadType = trim((string)($body['leadType'] ?? ''));

        // The free online estimator submits with only an email (no name, no phone).
        // Backfill a usable contact name from the email local-part so the lead lands
        // in FCO with a reasonable label instead of an empty contact record.
        $isFreeEstimate = strcasecmp($leadType, 'Free Online Estimate') === 0;

        // 5. Validate required fields
        $errors = [];
        if ($isFreeEstimate) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Valid email required';
            }
            if ($firstName === '' && $lastName === '' && $email !== '') {
                $localPart = (string) explode('@', $email)[0];
                $cleaned   = preg_replace('/[^a-zA-Z]/', '', $localPart);
                $firstName = $cleaned !== '' ? ucfirst(strtolower($cleaned)) : 'Online';
                $lastName  = '(Online Estimate)';
            }
        } else {
            if ($firstName === '') $errors['firstName'] = 'Required';
            if ($lastName  === '') $errors['lastName']  = 'Required';
            if ($phone === '' || !preg_match('/\d{10,}/', preg_replace('/\D/', '', $phone))) {
                $errors['phone'] = 'Valid phone required';
            }
        }
        if (!empty($errors)) {
            return new \WP_REST_Response(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        // 6. Save photo if present (Free Mockup Request flow)
        $photoUrl = '';
        if (!empty($files['photo']) && empty($files['photo']['error'])) {
            $saved = $this->savePhoto($files['photo']);
            if (!empty($saved['url'])) {
                $photoUrl = $saved['url'];
            } else {
                error_log('HFC API: photo save failed — ' . ($saved['error'] ?? 'unknown'));
            }
        }

        // 7. Build notes — preserve existing + append leadType + photo URL
        $notesParts = [];
        $existingNotes = trim((string)($body['notes'] ?? ''));
        if ($existingNotes !== '') $notesParts[] = $existingNotes;
        if ($leadType    !== '')   $notesParts[] = "Lead Type: {$leadType}";
        if ($photoUrl    !== '')   $notesParts[] = "Customer photo: {$photoUrl}";

        // 8. Map to FCO schema
        $payload = [
            'firstName'     => $firstName,
            'lastName'      => $lastName,
            'phone'         => $phone,
            'email'         => $email,
            'address'       => trim((string)($body['address']       ?? '')),
            'projectType'   => trim((string)($body['service']       ?? '')),
            'squareFootage' => trim((string)($body['squareFootage'] ?? '')),
            'notes'         => implode("\n\n", $notesParts),
            'source'        => $leadType !== '' ? "HFC Website ({$leadType})" : 'HFC Website',
        ];

        // 9. Forward to FCO
        $result = $this->fco->createLead($payload);

        if ($result['status'] >= 200 && $result['status'] < 300) {
            return new \WP_REST_Response(['success' => true], 200);
        }

        $msg = $result['error'] ?? ($result['data']['error'] ?? 'Upstream error');
        return new \WP_REST_Response(['error' => $msg], 502);
    }

    /**
     * Save uploaded photo to /wp-content/uploads/hfc-mockups/YYYY-MM/.
     * Returns ['url' => string, 'path' => string] on success or ['error' => string] on failure.
     */
    private function savePhoto(array $file): array
    {
        // Validate MIME
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        $mime    = strtolower((string)($file['type'] ?? ''));
        if (!in_array($mime, $allowed, true)) {
            return ['error' => "unsupported MIME: {$mime}"];
        }

        // Validate size (10 MB)
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            return ['error' => 'invalid file size'];
        }

        // Resolve target directory
        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            return ['error' => 'wp_upload_dir failed: ' . $uploadDir['error']];
        }
        $subdir    = '/hfc-mockups/' . date('Y-m');
        $targetDir = $uploadDir['basedir'] . $subdir;
        if (!file_exists($targetDir) && !wp_mkdir_p($targetDir)) {
            return ['error' => 'failed to create target dir'];
        }

        // Sanitize extension + build unguessable filename
        $rawExt = strtolower(pathinfo((string)($file['name'] ?? 'photo.jpg'), PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'heic' => 'heic', 'heif' => 'heif'];
        $ext    = $extMap[$rawExt] ?? 'jpg';
        $filename  = 'mockup-' . date('Ymd-His') . '-' . wp_generate_password(8, false, false) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        // Move uploaded file safely
        if (!is_uploaded_file($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['error' => 'move_uploaded_file failed'];
        }

        @chmod($targetPath, 0644);

        return [
            'path' => $targetPath,
            'url'  => $uploadDir['baseurl'] . $subdir . '/' . $filename,
        ];
    }

    private function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return explode(',', $_SERVER[$h])[0];
            }
        }
        return '0.0.0.0';
    }
}
