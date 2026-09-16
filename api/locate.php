<?php
require __DIR__ . '/auth.php';

// GET /api/locate.php?lon=120.94&lat=14.46  (company is taken from the logged-in session)
// Returns which zone (if any) contains the given point — a real PostGIS spatial query (ST_Contains).
$companyId = require_company_id();
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;

if ($lon === null || $lat === null) {
    json_response(['error' => 'lon and lat are required'], 400);
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT id, name, schedule
         FROM zones
         WHERE company_id = :company_id
           AND ST_Contains(geom, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
         LIMIT 1"
    );
    $stmt->execute(['company_id' => $companyId, 'lon' => $lon, 'lat' => $lat]);
    $zone = $stmt->fetch(PDO::FETCH_ASSOC);

    json_response([
        'point' => ['lon' => $lon, 'lat' => $lat],
        'zone' => $zone ?: null,
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
