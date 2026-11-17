<?php
/**
 * GET /api/places.php?action=...   (Authorization: Bearer <token>)
 *
 * A thin proxy in front of Google's Places and Geocoding APIs, so the
 * mobile app can offer address autocomplete without the API key ever
 * shipping inside the app binary.
 *
 *   ?action=autocomplete&q=Ayala          -> suggestions
 *   ?action=details&place_id=ChIJ...      -> formatted address + lat/lng + parts
 *   ?action=reverse&lat=14.55&lng=121.02  -> address from coordinates
 *
 * Why a proxy rather than calling Google from the app: GOOGLE_MAPS_API_KEY
 * is restricted by HTTP referrer, which is correct for a web page but
 * rejects a native app (it sends no referrer). Routing through here keeps
 * the existing restriction working — this server sends the site's own URL
 * as the referrer, which is true — and means the key stays on the server
 * where it can be rotated without shipping a new build.
 *
 * Uses the same two APIs the website already calls, so the key needs no
 * new services enabled: Places API (New) for autocomplete and details,
 * Geocoding v4 for reverse lookups.
 *
 * Auth is required so this can't be used as a free, open Places quota for
 * anyone who finds the URL.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/google_maps_config.php';
require_once __DIR__ . '/../functions/helpers.php';

require_auth($pdo);

/**
 * One request to Google. The referrer is what satisfies the key's HTTP
 * referrer restriction; without it a restricted key answers 403.
 */
function google_request(string $url, ?array $jsonBody = null, array $extraHeaders = []): ?array
{
    $ch = curl_init($url);

    $headers = array_merge(['X-Goog-Api-Key: ' . GOOGLE_MAPS_API_KEY], $extraHeaders);
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_REFERER => cg_base_url() . '/',
        CURLOPT_TIMEOUT => 10,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $code < 200 || $code >= 300) {
        error_log("Places proxy error ($code): $error " . substr((string) $response, 0, 500));
        return null;
    }

    return json_decode($response, true);
}

/** Pulls city and postal code out of either API's addressComponents shape. */
function extract_parts(array $components): array
{
    $city = '';
    $postal = '';

    foreach ($components as $c) {
        $types = $c['types'] ?? [];
        $value = $c['longText'] ?? $c['long_name'] ?? '';

        if (in_array('locality', $types, true) && $city === '') {
            $city = $value;
        }
        // Metro Manila cities often come back only at this level.
        if (in_array('administrative_area_level_2', $types, true) && $city === '') {
            $city = $value;
        }
        if (in_array('postal_code', $types, true) && $postal === '') {
            $postal = $value;
        }
    }

    return ['city' => $city, 'postal_code' => $postal];
}

$action = $_GET['action'] ?? '';

if ($action === 'autocomplete') {
    $query = trim($_GET['q'] ?? '');
    if (mb_strlen($query) < 3) {
        // Below three characters the suggestions are noise and each call
        // still costs quota.
        json_out(['success' => true, 'suggestions' => []]);
    }

    $result = google_request('https://places.googleapis.com/v1/places:autocomplete', [
        'input' => $query,
        'regionCode' => 'PH',
    ]);

    if ($result === null) {
        json_error('Address lookup is unavailable right now. You can still type the address yourself.', 502);
    }

    $suggestions = [];
    foreach (($result['suggestions'] ?? []) as $s) {
        $p = $s['placePrediction'] ?? null;
        if (!$p) {
            continue;
        }
        $suggestions[] = [
            'place_id'   => $p['placeId'] ?? '',
            'label'      => $p['structuredFormat']['mainText']['text'] ?? ($p['text']['text'] ?? ''),
            'secondary'  => $p['structuredFormat']['secondaryText']['text'] ?? '',
            'full'       => $p['text']['text'] ?? '',
        ];
    }

    json_out(['success' => true, 'suggestions' => $suggestions]);
}

if ($action === 'details') {
    $placeId = trim($_GET['place_id'] ?? '');
    if ($placeId === '') {
        json_error('A place_id is required.');
    }

    $result = google_request(
        'https://places.googleapis.com/v1/places/' . rawurlencode($placeId),
        null,
        ['X-Goog-FieldMask: formattedAddress,location,addressComponents']
    );

    if ($result === null) {
        json_error('Could not look up that address. Please type it instead.', 502);
    }

    $parts = extract_parts($result['addressComponents'] ?? []);

    json_out([
        'success' => true,
        'address_line' => $result['formattedAddress'] ?? '',
        'city' => $parts['city'],
        'postal_code' => $parts['postal_code'],
        'latitude' => $result['location']['latitude'] ?? null,
        'longitude' => $result['location']['longitude'] ?? null,
    ]);
}

if ($action === 'reverse') {
    $lat = $_GET['lat'] ?? null;
    $lng = $_GET['lng'] ?? null;

    if (!is_numeric($lat) || !is_numeric($lng)) {
        json_error('Valid lat and lng values are required.');
    }

    // The same Geocoding v4 endpoint pages/account.php uses when the map
    // pin is dragged.
    $url = 'https://geocode.googleapis.com/v4/geocode/location'
        . '?location.latitude=' . rawurlencode((string) $lat)
        . '&location.longitude=' . rawurlencode((string) $lng);

    $result = google_request($url);

    if ($result === null || empty($result['results'][0])) {
        json_error('Could not find an address at that location.', 502);
    }

    $first = $result['results'][0];
    $parts = extract_parts($first['addressComponents'] ?? []);

    json_out([
        'success' => true,
        'address_line' => $first['formattedAddress'] ?? '',
        'city' => $parts['city'],
        'postal_code' => $parts['postal_code'],
        'latitude' => (float) $lat,
        'longitude' => (float) $lng,
    ]);
}

if ($action === 'staticmap') {
    $lat = $_GET['lat'] ?? null;
    $lng = $_GET['lng'] ?? null;

    if (!is_numeric($lat) || !is_numeric($lng)) {
        json_error('Valid lat and lng values are required.');
    }

    // Clamp the knobs so this can't be driven into an expensive or absurd
    // request by whoever is holding a token.
    $zoom  = min(20, max(1, (int) ($_GET['zoom'] ?? 16)));
    $width = min(640, max(120, (int) ($_GET['w'] ?? 600)));
    $height = min(640, max(80, (int) ($_GET['h'] ?? 260)));
    $scale = ((int) ($_GET['scale'] ?? 2)) === 1 ? 1 : 2;

    $center = rawurlencode($lat . ',' . $lng);

    // Styled to the app's palette rather than Google's default blue-and-
    // white, which would look pasted in against either theme. The pin is
    // the brand gold in both so it still reads as ours.
    $theme = ($_GET['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $pin = $theme === 'light' ? '0x8E6A2C' : '0xC9A15C';

    $style = $theme === 'light'
        ? [
            'feature:all|element:geometry|color:0xf1ebe1',
            'feature:all|element:labels.text.fill|color:0x6b6257',
            'feature:all|element:labels.text.stroke|color:0xfaf7f2',
            'feature:road|element:geometry|color:0xffffff',
            'feature:water|element:geometry|color:0xdfe6ea',
            'feature:poi|element:labels|visibility:off',
        ]
        : [
            'feature:all|element:geometry|color:0x12122a',
            'feature:all|element:labels.text.fill|color:0x8a8aa3',
            'feature:all|element:labels.text.stroke|color:0x0a0a1a',
            'feature:road|element:geometry|color:0x2a2a45',
            'feature:water|element:geometry|color:0x0a0a1a',
            'feature:poi|element:labels|visibility:off',
        ];

    $url = 'https://maps.googleapis.com/maps/api/staticmap'
        . '?center=' . $center
        . '&zoom=' . $zoom
        . '&size=' . $width . 'x' . $height
        . '&scale=' . $scale
        . '&markers=' . rawurlencode('color:' . $pin . '|' . $lat . ',' . $lng);

    foreach ($style as $s) {
        $url .= '&style=' . rawurlencode($s);
    }

    $url .= '&key=' . rawurlencode(GOOGLE_MAPS_API_KEY);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_REFERER => cg_base_url() . '/',
        CURLOPT_TIMEOUT => 10,
    ]);
    $image = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    // A 403 here usually means "Maps Static API" is not enabled on the
    // key — a different product from Places and Geocoding. The app treats
    // any failure as "no preview" and still shows the address, so this
    // degrades instead of blocking the screen.
    if ($error || $code < 200 || $code >= 300 || stripos((string) $type, 'image/') !== 0) {
        error_log("Static map proxy error ($code): $error " . substr((string) $image, 0, 300));
        json_error('Map preview is unavailable.', 502);
    }

    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($image));
    // The pin only moves when the address does, so let the device keep it.
    header('Cache-Control: private, max-age=86400');
    echo $image;
    exit;
}

json_error('Unknown action. Use autocomplete, details, reverse or staticmap.');