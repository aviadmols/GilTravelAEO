<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Custom objects that the report fills in HubSpot (SEO metrics, AEO metrics), so HubSpot's
 * custom report builder can use data HubSpot does not have. Needs an Enterprise plan and the
 * crm.schemas.custom.read/write and crm.objects.custom.read/write scopes.
 */
class HubSpotObjects
{
    public function __construct(private HubSpotClient $hubspot) {}

    /** objectTypeId (e.g. 2-1234567) of the custom object named $schema['name'], created from $schema if missing. */
    public function ensure(array $schema, ?callable $onCreate = null): string
    {
        foreach ($this->hubspot->get('/crm/v3/schemas')['results'] ?? [] as $existing) {
            if (($existing['name'] ?? null) === $schema['name']) {
                $this->addMissingProperties($existing, $schema['properties']);

                return $existing['objectTypeId'];
            }
        }
        if ($onCreate) $onCreate();

        return $this->hubspot->post('/crm/v3/schemas', $schema + ['associatedObjects' => []])['objectTypeId'];
    }

    /** Properties added to the schema later, or left out when HubSpot created the object but refused a property. */
    private function addMissingProperties(array $existing, array $properties): void
    {
        if (! isset($existing['properties'])) return;
        $have = array_column($existing['properties'], 'name');
        $missing = array_filter($properties, fn ($p) => ! in_array($p['name'], $have, true));
        if (! $missing) return;

        $type = $existing['objectTypeId'];
        $group = $this->hubspot->get("/crm/v3/properties/{$type}/groups")['results'][0]['name'] ?? null;
        foreach ($missing as $property) {
            $this->hubspot->post("/crm/v3/properties/{$type}", $property + array_filter(['groupName' => $group]));
        }
    }

    /** Creates or updates records by their unique id property, 100 per call. */
    public function upsert(string $objectType, array $records): void
    {
        foreach (array_chunk($records, 100) as $chunk) {
            $this->hubspot->post("/crm/v3/objects/{$objectType}/batch/upsert", ['inputs' => $chunk]);
        }
    }

    /** One batch-upsert input. */
    public static function record(string $idProperty, string $id, array $properties): array
    {
        return ['idProperty' => $idProperty, 'id' => $id, 'properties' => [$idProperty => $id] + $properties];
    }

    /** A property definition for a schema. */
    public static function prop(string $name, string $label, string $type, string $field, array $extra = []): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'fieldType' => $field] + $extra;
    }

    /** Select options from value => label. */
    public static function options(array $labels): array
    {
        return array_map(
            fn ($value, $label, $order) => ['label' => $label, 'value' => (string) $value, 'displayOrder' => $order],
            array_keys($labels), $labels, range(0, count($labels) - 1),
        );
    }

    public static function explain(Throwable $e): string
    {
        if ($e instanceof RequestException && str_contains((string) $e->response->effectiveUri(), 'hubapi.com')) {
            return match ($e->response->status()) {
                401 => 'HubSpot rejected the key.',
                403 => 'HubSpot refused: the key needs the crm.schemas.custom.read, crm.schemas.custom.write, '
                    . 'crm.objects.custom.read and crm.objects.custom.write scopes, and custom objects need an Enterprise plan.',
                default => 'HubSpot answered ' . $e->response->status() . ': ' . mb_substr((string) $e->response->json('message', $e->response->body()), 0, 300),
            };
        }

        return $e->getMessage();
    }
}
