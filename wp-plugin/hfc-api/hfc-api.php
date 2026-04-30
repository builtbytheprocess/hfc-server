<?php
/**
 * Plugin Name: HFC API
 * Description: REST endpoints for HFC website lead intake and SMS requests (proxies to FCO). Supports multipart photo uploads for mockup requests.
 * Version: 1.2.0
 * Requires PHP: 8.0
 * Author: Houston Floor Coatings
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/RateLimiter.php';
require_once __DIR__ . '/includes/FcoClient.php';
require_once __DIR__ . '/includes/LeadsEndpoint.php';
require_once __DIR__ . '/includes/SmsEndpoint.php';

// Expose Bricks page data to the REST API so deploy scripts can read/write pages
add_action('init', function () {
    foreach (['post', 'page'] as $postType) {
        register_meta($postType, '_bricks_data', [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ]);
        register_meta($postType, '_bricks_page_settings', [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ]);
    }
});

add_action('plugins_loaded', function () {
    $apiKey = defined('HFC_FCO_API_KEY') ? HFC_FCO_API_KEY : '';
    $baseUrl = defined('HFC_FCO_BASE_URL') ? HFC_FCO_BASE_URL : 'https://app.floorcoatingops.com';

    if ($apiKey === '') {
        error_log('HFC API: HFC_FCO_API_KEY not defined in wp-config.php — endpoints disabled.');
        return;
    }

    $fco = new \HFC\Api\FcoClient($baseUrl, $apiKey);
    $leadLimiter = new \HFC\Api\RateLimiter('lead', 5, 3600);

    (new \HFC\Api\LeadsEndpoint($fco, $leadLimiter))->register();

    $smsLimiter = new \HFC\Api\RateLimiter('sms', 10, 3600);
    (new \HFC\Api\SmsEndpoint($fco, $smsLimiter))->register();
});

// ── Service + City schema injected into <head> ─────────────────────────────
// Outputs JSON-LD Service schema per page type. Runs at priority 5 so it
// lands before Rank Math (priority 10) — Google merges multiple blocks fine.
add_action('wp_head', function () {
    if (!is_page() && !is_singular()) return;

    $slug = get_post_field('post_name', get_the_ID());
    $base = 'https://houstonfloorcoatings.com';
    $url  = $base . '/' . $slug . '/';

    $biz = [
        '@type'      => 'LocalBusiness',
        '@id'        => $base . '/#business',
        'name'       => 'Houston Floor Coatings',
        'url'        => $base . '/',
        'telephone'  => '+17133096589',
        'email'      => 'info@houstonfloorcoatings.com',
        'priceRange' => '$$$',
        'address'    => ['@type' => 'PostalAddress', 'addressLocality' => 'Houston', 'addressRegion' => 'TX', 'addressCountry' => 'US'],
    ];

    $services = [
        'epoxy-garage-floor-coatings' => ['name' => 'Epoxy Garage Floor Coatings in Houston, TX', 'type' => 'Epoxy Garage Floor Coating', 'desc' => 'Hybrid epoxy + polyaspartic garage floor coatings in Houston. Diamond-ground prep, 15-year installation warranty. Starting at $5/sq ft.'],
        'polyaspartic-floor-coatings' => ['name' => 'Polyaspartic Floor Coatings in Houston, TX', 'type' => 'Polyaspartic Floor Coating', 'desc' => 'UV-stable polyaspartic floor coating systems in Houston. 15-year installation warranty.'],
        'metallic-epoxy-floors'       => ['name' => 'Metallic Epoxy Floors in Houston, TX', 'type' => 'Metallic Epoxy Floor Coating', 'desc' => 'Custom metallic epoxy floor coatings — marble, pearl, molten-metal, 3D-depth. Starting at $9/sq ft. 15-year warranty.'],
        'decorative-flake-floors'     => ['name' => 'Decorative Flake Floors in Houston, TX', 'type' => 'Decorative Flake Epoxy Floor', 'desc' => 'Full-broadcast decorative vinyl flake epoxy floors in Houston. 16 color blends. 15-year installation warranty.'],
        'commercial-epoxy-flooring'   => ['name' => 'Commercial Epoxy Flooring in Houston, TX', 'type' => 'Commercial Epoxy Floor Coating', 'desc' => 'Commercial-grade epoxy floor coatings for warehouses, showrooms, restaurants, and retail in Houston.'],
        'patio-pool-deck-coatings'    => ['name' => 'Patio & Pool Deck Coatings in Houston, TX', 'type' => 'Patio and Pool Deck Coating', 'desc' => 'UV-stable polyaspartic patio and pool deck coatings built for Houston heat and humidity.'],
        'microcement-overlay-systems' => ['name' => 'Microcement Overlay Systems in Houston, TX', 'type' => 'Microcement Overlay', 'desc' => 'Seamless 2-3mm microcement overlays for floors, walls, and countertops in Houston.'],
        'concrete-staining-sealing'   => ['name' => 'Concrete Staining & Sealing in Houston, TX', 'type' => 'Concrete Staining and Sealing', 'desc' => 'Acid-stained and sealed concrete floors for garages, patios, and commercial spaces in Houston.'],
        'epoxy-hybrid'                => ['name' => 'Epoxy Hybrid Garage Floor System in Houston, TX', 'type' => 'Hybrid Epoxy Floor Coating', 'desc' => '2-day hybrid: 100% solids epoxy base + polyaspartic topcoat. Starting at $5/sq ft. 15-year warranty.'],
        '1-day-garage-floors'         => ['name' => '1-Day Garage Floor Coatings in Houston, TX', 'type' => '1-Day Fast-Cure Epoxy Floor', 'desc' => 'Fast-cure 1-day epoxy garage floor. Same flake, same topcoat, same 15-year warranty. Starting at $6.50/sq ft.'],
    ];

    $cities = [
        'epoxy-floor-coatings-katy-tx'              => ['city' => 'Katy',            'lat' => 29.7858, 'lon' => -95.8245],
        'epoxy-floor-coatings-sugar-land-tx'         => ['city' => 'Sugar Land',      'lat' => 29.6196, 'lon' => -95.6349],
        'epoxy-floor-coatings-the-woodlands-tx'      => ['city' => 'The Woodlands',   'lat' => 30.1588, 'lon' => -95.4866],
        'epoxy-floor-coatings-cypress-tx'            => ['city' => 'Cypress',         'lat' => 29.9696, 'lon' => -95.6972],
        'epoxy-floor-coatings-pearland-tx'           => ['city' => 'Pearland',        'lat' => 29.5635, 'lon' => -95.2860],
        'epoxy-floor-coatings-spring-tx'             => ['city' => 'Spring',          'lat' => 30.0799, 'lon' => -95.4172],
        'epoxy-floor-coatings-friendswood-tx'        => ['city' => 'Friendswood',     'lat' => 29.5293, 'lon' => -95.2010],
        'epoxy-floor-coatings-league-city-tx'        => ['city' => 'League City',     'lat' => 29.5075, 'lon' => -95.0949],
        'epoxy-floor-coatings-kingwood-tx'           => ['city' => 'Kingwood',        'lat' => 29.9985, 'lon' => -95.2023],
        'epoxy-floor-coatings-memorial-riveroaks-tx' => ['city' => 'Memorial',        'lat' => 29.7698, 'lon' => -95.5180],
        'epoxy-floor-coatings-houston-heights-tx'    => ['city' => 'Houston Heights', 'lat' => 29.7974, 'lon' => -95.3984],
        'epoxy-floor-coatings-humble-tx'             => ['city' => 'Humble',          'lat' => 29.9988, 'lon' => -95.2624],
        'epoxy-floor-coatings-missouri-city-tx'      => ['city' => 'Missouri City',   'lat' => 29.6185, 'lon' => -95.5377],
        'epoxy-floor-coatings-pasadena-tx'           => ['city' => 'Pasadena',        'lat' => 29.6911, 'lon' => -95.2091],
        'epoxy-floor-coatings-galveston-tx'          => ['city' => 'Galveston',       'lat' => 29.2988, 'lon' => -94.7977],
    ];

    $schema = null;

    if (isset($services[$slug])) {
        $s = $services[$slug];
        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'Service', '@id' => $url . '#service', 'serviceType' => $s['type'], 'provider' => ['@id' => $base . '/#business'], 'name' => $s['name'], 'description' => $s['desc'], 'url' => $url, 'areaServed' => ['@type' => 'City', 'name' => 'Houston', 'containedInPlace' => ['@type' => 'State', 'name' => 'Texas']]],
                $biz,
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $base . '/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $s['name'], 'item' => $url]]],
            ],
        ];
    } elseif (isset($cities[$slug])) {
        $c = $cities[$slug];
        $name = 'Epoxy Floor Coatings in ' . $c['city'] . ', TX';
        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'Service', '@id' => $url . '#service', 'serviceType' => 'Epoxy Floor Coating', 'provider' => ['@id' => $base . '/#business'], 'name' => $name, 'description' => 'Hybrid epoxy + polyaspartic floor coatings in ' . $c['city'] . ', TX. 15-year installation warranty. Free estimate.', 'url' => $url, 'areaServed' => ['@type' => 'City', 'name' => $c['city'], 'containedInPlace' => ['@type' => 'State', 'name' => 'Texas'], 'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lon']]]],
                $biz,
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $base . '/'], ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url]]],
            ],
        ];
    }

    if ($schema) {
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}, 5);
