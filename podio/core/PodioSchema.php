<?php
// podio/PodioSchema.php

class PodioSchema
{
    private PodioClient $client;
    private array $schema = [];

    public function __construct(PodioClient $client)
    {
        $this->client = $client;
    }

    /**
     * Carica lo schema (campi) di una app Podio
     *
     * @param int $appId
     * @throws Exception se la risposta non contiene campi
     */
    public function loadSchema(int $appId): void
    {
        $response = $this->client->request("app/{$appId}");
        if (!isset($response['fields'])) {
            throw new Exception("❌ Nessun campo trovato per l'app ID {$appId}");
        }

        $this->schema = $response['fields'];
    }

    /**
     * Restituisce tutti i campi della app corrente
     *
     * @return array
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Restituisce un campo dallo schema tramite external_id
     *
     * @param string $externalId
     * @return array|null
     */
    public function getFieldByExternalId(string $externalId): ?array
    {
        foreach ($this->schema as $field) {
            if ($field['external_id'] === $externalId) {
                return $field;
            }
        }
        return null;
    }

    /**
     * Ritorna il client Podio per chiamate avanzate
     *
     * @return PodioClient
     */
    public function getClient(): PodioClient
    {
        return $this->client;
    }
}
