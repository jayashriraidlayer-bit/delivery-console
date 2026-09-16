<?php
require __DIR__ . '/auth.php';

$companyId = require_company_id();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = get_pdo();

    if ($method === 'GET') {
        // GET /api/zones.php  (company is taken from the logged-in session, not the URL)
        $stmt = $pdo->prepare(
            "SELECT id, name, color, schedule, ST_AsGeoJSON(geom) AS geojson,
                    (SELECT COUNT(*) FROM stops s WHERE s.zone_id = zones.id) AS stop_count
             FROM zones
             WHERE company_id = :company_id
             ORDER BY id"
        );
        $stmt->execute(['company_id' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = array_map(function ($row) {
            return [
                'type' => 'Feature',
                'geometry' => json_decode($row['geojson']),
                'properties' => [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'color' => $row['color'],
                    'schedule' => $row['schedule'],
                    'stop_count' => (int)$row['stop_count'],
                ],
            ];
        }, $rows);

        json_response(['type' => 'FeatureCollection', 'features' => $features]);
    }

    if ($method === 'POST') {
        // POST /api/zones.php
        // body: {"name": "New Zone", "color": "#2e6f5e", "schedule": "Daily",
        //        "coords": [[lon,lat], [lon,lat], ...]}  (a closed or open ring; at least 3 points)
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($body['name'] ?? '');
        $color = $body['color'] ?? '#2e6f5e';
        $schedule = $body['schedule'] ?? null;
        $coords = $body['coords'] ?? null;

        if ($name === '' || !is_array($coords) || count($coords) < 3) {
            json_response(['error' => 'name and at least 3 coords are required'], 400);
        }

        // Close the ring if the caller didn't repeat the first point at the end.
        if ($coords[0] !== $coords[count($coords) - 1]) {
            $coords[] = $coords[0];
        }
        $wkt = 'POLYGON((' . implode(',', array_map(function ($c) {
            return $c[0] . ' ' . $c[1];
        }, $coords)) . '))';

        $stmt = $pdo->prepare(
            "INSERT INTO zones (company_id, name, color, schedule, geom)
             VALUES (:company_id, :name, :color, :schedule, ST_SetSRID(ST_GeomFromText(:wkt), 4326))
             RETURNING id"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'color' => $color,
            'schedule' => $schedule,
            'wkt' => $wkt,
        ]);
        $id = $stmt->fetchColumn();

        json_response(['id' => (int)$id], 201);
    }

    json_response(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
