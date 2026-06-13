<?php
// podio/PodioCRUD.php

require_once __DIR__ . '/PodioSchema.php';

class PodioCRUD
{
    private PodioClient $client;
    private PodioSchema $schema;

    public function __construct(PodioClient $client)
    {
        $this->client = $client;
        $this->schema = new PodioSchema($client);
    }

    public function createItem(int $appId, array $data): array
    {
        $this->schema->loadSchema($appId);

        $fields = [];
        foreach ($data as $externalId => $value) {
            $field = $this->schema->getFieldByExternalId($externalId);
            if (!$field) {
                throw new Exception("Campo '{$externalId}' non trovato nello schema.");
            }

            $fields[] = [
                "external_id" => $externalId,
                "values" => [$value]
            ];
        }

        $payload = [
            "fields" => $fields
        ];

        return $this->client->request("item/app/{$appId}/", $payload, "POST");
    }

    public function getItem(int $itemId): array
    {
        return $this->client->request("item/{$itemId}");
    }

    public function updateItem(int $itemId, int $appId, array $data): array
    {
        $this->schema->loadSchema($appId);

        $fields = [];
        foreach ($data as $externalId => $value) {
            $field = $this->schema->getFieldByExternalId($externalId);
            if (!$field) {
                throw new Exception("Campo '{$externalId}' non trovato nello schema.");
            }

            $fields[] = [
                "external_id" => $externalId,
                "values" => [$value]
            ];
        }

        $payload = [
            "fields" => $fields
        ];

        return $this->client->request("item/{$itemId}", $payload, "PUT");
    }

    public function deleteItem(int $itemId): bool
    {
        $this->client->request("item/{$itemId}", [], "DELETE");
        return true;
    }

}
