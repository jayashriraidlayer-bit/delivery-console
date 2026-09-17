<?php
require __DIR__ . '/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = get_pdo();

    if ($method === 'GET') {
        // GET /api/drivers.php  (dashboard call — requires the owner's session)
        $companyId = require_company_id();
        $stmt = $pdo->prepare(
            "SELECT d.id, d.name, d.status, d.access_token, z.name AS zone_name, ST_AsGeoJSON(d.geom) AS geojson
             FROM drivers d
             LEFT JOIN zones z ON z.id = d.zone_id
             WHERE d.company_id = :company_id
             ORDER BY d.id"
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
                    'status' => $row['status'],
                    'zone_name' => $row['zone_name'],
                    'access_token' => $row['access_token'],
                ],
            ];
        }, $rows);

        json_response(['type' => 'FeatureCollection', 'features' => $features]);
    }

    if ($method === 'POST') {
        // POST /api/drivers.php
        // body: {"name": "New Driver", "lon": 120.94, "lat": 14.46, "zone_id": 1, "status": "ok"}
        $companyId = require_company_id();
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($body['name'] ?? '');
        $lon = $body['lon'] ?? null;
        $lat = $body['lat'] ?? null;
        $zoneId = isset($body['zone_id']) ? (int)$body['zone_id'] : null;
        $status = $body['status'] ?? 'ok';

        if ($name === '' || $lon === null || $lat === null) {
            json_response(['error' => 'name, lon and lat are required'], 400);
        }

        // A zone_id, if given, must belong to this same company — prevents cross-tenant linking.
        if ($zoneId !== null) {
            $check = $pdo->prepare("SELECT id FROM zones WHERE id = :id AND company_id = :company_id");
            $check->execute(['id' => $zoneId, 'company_id' => $companyId]);
            if (!$check->fetch()) {
                json_response(['error' => 'zone_id does not belong to this company'], 400);
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO drivers (company_id, name, status, zone_id, geom)
             VALUES (:company_id, :name, :status, :zone_id, ST_SetSRID(ST_MakePoint(:lon, :lat), 4326))
             RETURNING id, access_token"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'name' => $name,
            'status' => $status,
            'zone_id' => $zoneId,
            'lon' => $lon,
            'lat' => $lat,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        json_response(['id' => (int)$row['id'], 'access_token' => $row['access_token']], 201);
    }

    if ($method === 'PATCH') {
        // PATCH /api/drivers.php?token=<access_token>  body: {"lon": 120.96, "lat": 14.46, "status": "ok"}
        // Called by a driver's own phone (driver.html), not the owner's
        // dashboard — so it can't use the owner's session. Instead, each
        // driver has a private, unguessable access_token (see schema.sql);
        // the token itself both identifies and authorizes which driver's
        // row this call may update, replacing the old open numeric ?id=.
        $token = $_GET['token'] ?? '';
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($token === '' || !isset($body['lon'], $body['lat'])) {
            json_response(['error' => 'token, lon and lat are required'], 400);
        }

        $stmt = $pdo->prepare(
            "UPDATE drivers
             SET geom = ST_SetSRID(ST_MakePoint(:lon, :lat), 4326),
                 status = COALESCE(:status, status),
                 updated_at = now()
             WHERE access_token = :token
             RETURNING id"
        );
        $stmt->execute([
            'lon' => $body['lon'],
            'lat' => $body['lat'],
            'status' => $body['status'] ?? null,
            'token' => $token,
        ]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'invalid token'], 404);
        }

        json_response(['updated' => true]);
    }

    json_response(['error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
