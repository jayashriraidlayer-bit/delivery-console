<?php
require __DIR__ . '/auth.php';

$companyId = require_company_id();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = get_pdo();

    if ($method === 'GET') {
        // GET /api/stops.php  (company is taken from the logged-in session, not the URL)
        $stmt = $pdo->prepare(
            "SELECT s.id, s.label, s.status, z.name AS zone_name, d.name AS driver_name, ST_AsGeoJSON(s.geom) AS geojson
             FROM stops s
             LEFT JOIN zones z ON z.id = s.zone_id
             LEFT JOIN drivers d ON d.id = s.driver_id
             WHERE s.company_id = :company_id
             ORDER BY s.id"
        );
        $stmt->execute(['company_id' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = array_map(function ($row) {
            return [
                'type' => 'Feature',
                'geometry' => json_decode($row['geojson']),
                'properties' => [
                    'id' => (int)$row['id'],
                    'label' => $row['label'],
                    'status' => $row['status'],
                    'zone_name' => $row['zone_name'],
                    'driver_name' => $row['driver_name'],
                ],
            ];
        }, $rows);

        json_response(['type' => 'FeatureCollection', 'features' => $features]);
    }

    if ($method === 'POST') {
        // POST /api/stops.php
        // body: {"label": "New Stop", "lon": 120.94, "lat": 14.46, "zone_id": 1}
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $label = trim($body['label'] ?? '');
        $lon = $body['lon'] ?? null;
        $lat = $body['lat'] ?? null;
        $zoneId = isset($body['zone_id']) && $body['zone_id'] !== '' ? (int)$body['zone_id'] : null;

        if ($label === '' || $lon === null || $lat === null) {
            json_response(['error' => 'label, lon and lat are required'], 400);
        }

        if ($zoneId !== null) {
            $check = $pdo->prepare("SELECT id FROM zones WHERE id = :id AND company_id = :company_id");
            $check->execute(['id' => $zoneId, 'company_id' => $companyId]);
            if (!$check->fetch()) {
                json_response(['error' => 'zone_id does not belong to this company'], 400);
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO stops (company_id, label, zone_id, status, geom)
             VALUES (:company_id, :label, :zone_id, 'pending', ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
             RETURNING id"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'label' => $label,
            'zone_id' => $zoneId,
            'lon' => $lon,
            'lat' => $lat,
        ]);
        $id = $stmt->fetchColumn();

        json_response(['id' => (int)$id], 201);
    }

    if ($method === 'PATCH') {
        // PATCH /api/stops.php?id=3  body: {"status": "delivered"}
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $status = $body['status'] ?? null;
        $validStatuses = ['pending', 'delivered', 'failed'];

        if (!$id || !in_array($status, $validStatuses, true)) {
            json_response(['error' => 'id and a valid status (pending|delivered|failed) are required'], 400);
        }

        $stmt = $pdo->prepare(
            "UPDATE stops SET status = :status
             WHERE id = :id AND company_id = :company_id
             RETURNING id"
        );
        $stmt->execute(['status' => $status, 'id' => $id, 'company_id' => $companyId]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'stop not found'], 404);
        }

        json_response(['updated' => $id]);
    }

    json_response(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
