<?php
// podio/PodioFormBuilder.php

require_once __DIR__ . '/PodioSchema.php';

class PodioFormBuilder
{
    private PodioSchema $schema;
    private PodioClient $client;

    public function __construct(PodioClient $client)
    {
        $this->client = $client;
        $this->schema = new PodioSchema($client);
    }

    public function render(int $appId, string $action = '', string $appToken = '', ?int $itemId = null): string
    {
        $this->schema->loadSchema($appId);
        $fields = $this->schema->getSchema();
        $values = [];

        // ✅ Se item_id esiste, carica i valori esistenti
        if ($itemId) {
            $item = $this->client->request("item/{$itemId}");
            foreach ($item['fields'] as $field) {
                $values[$field['external_id']] = $field['values'];
            }
        }

        if (empty($fields)) {
            return "<div class='alert alert-danger'>❌ Nessun campo trovato nello schema.</div>";
        }

        $formAction = $action ? "action=\"$action\"" : '';
        $html = "<form method='POST' {$formAction} enctype='multipart/form-data' class='row g-3'>";
        $html .= "<input type='hidden' name='app_id' value='{$appId}'>";
        $html .= "<input type='hidden' name='app_token' value='{$appToken}'>";
        if ($itemId) {
            $html .= "<input type='hidden' name='item_id' value='{$itemId}'>";
        }

        foreach ($fields as $field) {
            $externalId = $field['external_id'];
            $label = htmlspecialchars($field['label']);
            $type = $field['type'];
            $required = !empty($field['config']['required']) ? 'required' : '';
            $placeholder = htmlspecialchars($field['config']['description'] ?? '');
            $value = $values[$externalId][0] ?? null;

            $html .= "<div class='col-12'>";
            $html .= "<label for='{$externalId}' class='form-label'><strong>{$label}</strong></label>";

            switch ($type) {
                case 'text':
                    $textVal = htmlspecialchars($value['value'] ?? '');
                    $format = $field['config']['settings']['format'] ?? 'plain';
                    if ($format === 'multiline') {
                        $html .= "<textarea class='form-control' name='{$externalId}' id='{$externalId}' placeholder='{$placeholder}' {$required}>{$textVal}</textarea>";
                    } else {
                        $html .= "<input type='text' class='form-control' name='{$externalId}' id='{$externalId}' value='{$textVal}' placeholder='{$placeholder}' {$required}>";
                    }
                    break;

                case 'number':
                case 'money':
                    $numVal = htmlspecialchars($value['value'] ?? '');
                    $html .= "<input type='number' class='form-control' name='{$externalId}' id='{$externalId}' value='{$numVal}' placeholder='{$placeholder}' {$required}>";
                    break;

                case 'date':
                    $dateVal = substr($value['start'] ?? '', 0, 10);
                    $html .= "<input type='date' class='form-control' name='{$externalId}' id='{$externalId}' value='{$dateVal}' {$required}>";
                    break;

                case 'phone':
                    $phoneVal = htmlspecialchars($value['value'] ?? '');
                    $html .= "<input type='tel' class='form-control' name='{$externalId}' id='{$externalId}' value='{$phoneVal}' placeholder='{$placeholder}' {$required}>";
                    break;

                case 'image':
                    $html .= "<input type='file' class='form-control' name='{$externalId}' id='{$externalId}' {$required}>";
                    break;

                case 'app':
                    $selectedId = is_array($value) ? ($value['value']['item_id'] ?? '') : '';
                    $displayValue = is_array($value) ? ($value['value']['title'] ?? '') : '';
                    $referencedAppId = $field['config']['settings']['referenced_apps'][0]['app_id'] ?? '';

                    $html .= <<<HTML
<input type="text" 
       class="form-control" 
       name="{$externalId}_label" 
       id="{$externalId}_label" 
       value="{$displayValue}" 
       placeholder="{$placeholder}" 
       data-podio-app-reference="{$referencedAppId}" 
       data-podio-app-token="{$appToken}" 
       autocomplete="off">

<input type="hidden" 
       name="{$externalId}" 
       id="{$externalId}_hidden" 
       value="{$selectedId}">
HTML;
                    break;

                case 'category':
                    $selected = $value['value']['text'] ?? '';
                    $html .= "<select class='form-select' name='{$externalId}' id='{$externalId}' {$required}>";
                    foreach ($field['config']['settings']['options'] as $option) {
                        $text = htmlspecialchars($option['text']);
                        $sel = $text === $selected ? 'selected' : '';
                        $html .= "<option value='{$text}' {$sel}>{$text}</option>";
                    }
                    $html .= "</select>";
                    break;

                default:
                    $defaultVal = is_string($value) ? htmlspecialchars($value) : '';
                    $html .= "<input type='text' class='form-control' name='{$externalId}' id='{$externalId}' value='{$defaultVal}' placeholder='{$placeholder}' {$required}>";
            }

            $html .= "</div>";
        }

        $html .= "<div class='col-12'><button type='submit' class='btn btn-primary'>Salva</button></div>";
        $html .= "</form>";

        return $html;
    }
}
