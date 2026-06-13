<?php
// podio/PodioFormatter.php

require_once __DIR__ . '/PodioSchema.php';

class PodioFormatter
{
    private PodioSchema $schema;

    public function __construct(PodioClient $client)
    {
        $this->schema = new PodioSchema($client);
    }

    /**
     * Carica lo schema di una specifica app
     *
     * @param int $appId
     */
    public function loadSchema(int $appId): void
    {
        $this->schema->loadSchema($appId);
    }

    /**
     * Ritorna il blocco completo da inviare a Podio: ["fields" => [...]]
     *
     * @param array $input
     * @return array
     */
    public function formatFieldsBlock(array $input): array
    {
        return ['fields' => $this->format($input)];
    }

    /**
     * Ritorna l'array formattato di campi e valori secondo lo schema dell'app
     *
     * @param array $input
     * @return array
     */
    public function format(array $input): array
    {
        $output = [];

        foreach ($input as $externalId => $value) {
            $field = $this->schema->getFieldByExternalId($externalId);
            if (!$field) continue;

            $type = $field['type'];
            $formattedValue = $this->formatFieldValue($type, $field, $value);

            if ($formattedValue !== null && $formattedValue !== '') {
                $output[] = [
                    'external_id' => $externalId,
                    'values' => [$formattedValue]
                ];
            }
        }

        return $output;
    }

    /**
     * Format individual field values according to type
     */
    private function formatFieldValue(string $type, array $field, mixed $value): mixed
    {
        switch ($type) {
            case 'text':
            case 'link':
            case 'embed':
                return (string)$value;

            case 'number':
            case 'progress':
            case 'duration':
                return is_numeric($value) ? (float)$value : null;

            case 'money':
                return is_array($value) ? $value : ['value' => (float)$value, 'currency' => 'EUR'];

            case 'date':
                if (is_string($value)) {
                    return ['start' => date('Y-m-d H:i:s', strtotime($value))];
                } elseif ($value instanceof DateTime) {
                    return ['start' => $value->format('Y-m-d H:i:s')];
                } elseif (is_array($value)) {
                    return $value;
                }
                return null;

            case 'category':
                return $this->mapCategoryLabelToId($field, (string)$value);

            case 'phone':
            case 'email':
                return is_array($value) && isset($value['value'])
                    ? $value
                    : ['type' => 'mobile', 'value' => (string)$value];

            case 'image':
            case 'app':
                return (is_numeric($value) && $value > 0) ? (int)$value : null;

            case 'location':
                return is_array($value) ? $value : ['value' => (string)$value];

            case 'contact':
            case 'question':
                return $value;

            default:
                return $value;
        }
    }

    /**
     * Cerca l'ID di una category a partire dal testo
     */
    private function mapCategoryLabelToId(array $field, string $label): ?int
    {
        if (!isset($field['config']['settings']['options'])) {
            return null;
        }

        foreach ($field['config']['settings']['options'] as $option) {
            if (strcasecmp($option['text'], $label) === 0) {
                return $option['id'];
            }
        }

        return null;
    }
}
