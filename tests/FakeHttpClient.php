<?php
declare(strict_types=1);

namespace Landlords\Tests;

use Landlords\Council\Layers;
use Landlords\Http\HttpClient;

/**
 * Stands in for the council's servers. Responses are chosen by URL and
 * parameters, and every request is recorded for assertions.
 */
final class FakeHttpClient implements HttpClient
{
    public array $requests = [];
    /** @var array<int, list<array>> features returned for each layer's query */
    public array $layers = [];
    /** @var array<int, list<string>> field names reported for each layer (defaults: what we use) */
    public array $fields = [];
    /** @var array<int, array> error responses by layer */
    public array $errors = [];
    public ?array $bufferError = null;
    /** @var array<string, string> raw bodies by URL (register pages and files) */
    public array $raw = [];

    public function __construct()
    {
        foreach (Layers::licensing() as $id => $layer) {
            $this->fields[$id] = array_merge(['OBJECTID', 'SHAPE', 'WEBSITE'], $layer['fields']);
            $this->layers[$id] = [];
        }
        $this->fields[Layers::STUDENT_LAYER] = array_merge(['OBJECTID', 'SHAPE'], Layers::STUDENT_FIELDS);
        $this->layers[Layers::STUDENT_LAYER] = [];
    }

    public function getJson(string $url, array $params = []): array
    {
        $this->requests[] = ['GET', $url, $params];
        if (preg_match('#/Housing_and_Property/MapServer/(\d+)$#', $url, $m)) {
            return ['fields' => array_map(static fn($n) => ['name' => $n], $this->fields[(int) $m[1]] ?? [])];
        }
        if (str_contains($url, '/MapServer/49/query')) {
            $ring = [[456000.0, 339000.0], [456800.0, 339000.0], [456800.0, 339800.0], [456000.0, 339800.0], [456000.0, 339000.0]];
            if (($params['f'] ?? '') === 'geojson') {
                return ['type' => 'FeatureCollection', 'features' => [[
                    'type' => 'Feature', 'properties' => ['NAME' => 'The Park'],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-1.170, 52.946], [-1.153, 52.946], [-1.153, 52.955], [-1.170, 52.955], [-1.170, 52.946]]]],
                ]]];
            }
            return ['spatialReference' => ['wkid' => 27700, 'latestWkid' => 27700],
                    'features' => [['attributes' => ['NAME' => 'The Park'], 'geometry' => ['rings' => [$ring]]]]];
        }
        throw new \RuntimeException("Unexpected GET {$url}");
    }

    public function postJson(string $url, array $params): array
    {
        $this->requests[] = ['POST', $url, $params];
        if (preg_match('#/Housing_and_Property/MapServer/(\d+)/query$#', $url, $m)) {
            $id = (int) $m[1];
            if (isset($this->errors[$id])) {
                return ['error' => $this->errors[$id]];
            }
            $all = $this->layers[$id] ?? [];
            $offset = (int) ($params['resultOffset'] ?? 0);
            $size = (int) ($params['resultRecordCount'] ?? 2000);
            $page = array_slice($all, $offset, $size);
            $response = ['features' => $page];
            if ($offset + $size < count($all)) {
                $response['exceededTransferLimit'] = true;
            }
            return $response;
        }
        if (str_ends_with($url, '/GeometryServer/buffer')) {
            if ($this->bufferError) {
                return ['error' => $this->bufferError];
            }
            return ['geometries' => [['rings' => [[[-1.1707, 52.9455], [-1.1707, 52.9555], [-1.1523, 52.9555], [-1.1523, 52.9455], [-1.1707, 52.9455]]]]]];
        }
        throw new \RuntimeException("Unexpected POST {$url}");
    }

    public function getRaw(string $url): string
    {
        $this->requests[] = ['RAW', $url, []];
        if (!array_key_exists($url, $this->raw)) {
            throw new \RuntimeException("HTTP 404 from {$url}");
        }
        return $this->raw[$url];
    }
}
