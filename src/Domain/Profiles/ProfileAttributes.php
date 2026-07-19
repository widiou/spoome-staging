<?php

namespace Spoome\Domain\Profiles;

/**
 * Campi descrittivi type-specific del profilo (colonne JSON già presenti in schema):
 *  - `profile_types.attributes_schema` = DEFINIZIONE dei campi per tipo (whitelist).
 *  - `profiles.attributes`             = VALORI per singolo profilo.
 *
 * Regola di sicurezza (MASSIMO): lo schema è l'unica whitelist. Solo le chiavi definite
 * dallo schema del tipo sono accettate in scrittura e rese in output; ogni altra chiave è
 * scartata. I valori sono validati per tipo (text/year/url/select) prima di essere persistiti;
 * l'escaping in output resta responsabilità della view (`e()`).
 */
final class ProfileAttributes
{
    /** Tipi di campo ammessi nella definizione di uno schema. */
    private const FIELD_TYPES = ['text', 'year', 'url', 'select'];

    private const YEAR_MIN = 1800;
    private const YEAR_MAX = 2100;
    private const MAX_DEFAULT = 200;

    /**
     * Decodifica lo schema di un tipo in una lista di definizioni di campo validate.
     * Ritorna sempre una lista pulita (chiavi/tipi noti): input malformato → [].
     * @return array<int,array{key:string,label:string,type:string,maxlen:int,options:string[]}>
     */
    public static function fields(?string $schemaJson): array
    {
        if ($schemaJson === null || $schemaJson === '') {
            return [];
        }
        $data = json_decode($schemaJson, true);
        if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
            return [];
        }
        $out = [];
        foreach ($data['fields'] as $f) {
            if (!is_array($f) || !isset($f['key'], $f['label'], $f['type'])) {
                continue;
            }
            $type = (string) $f['type'];
            if (!in_array($type, self::FIELD_TYPES, true)) {
                continue;
            }
            $def = [
                'key'     => (string) $f['key'],
                'label'   => (string) $f['label'],
                'type'    => $type,
                'maxlen'  => isset($f['maxlen']) ? max(1, (int) $f['maxlen']) : self::MAX_DEFAULT,
                'options' => [],
            ];
            if ($type === 'select' && isset($f['options']) && is_array($f['options'])) {
                $def['options'] = array_values(array_map('strval', $f['options']));
            }
            if ($def['key'] !== '') {
                $out[] = $def;
            }
        }
        return $out;
    }

    /** Valori grezzi (chiave→valore) da `profiles.attributes`. Input malformato → []. */
    public static function values(?string $valuesJson): array
    {
        if ($valuesJson === null || $valuesJson === '') {
            return [];
        }
        $v = json_decode($valuesJson, true);
        return is_array($v) ? $v : [];
    }

    /**
     * Valida l'input contro lo schema e produce il JSON da salvare (o null se vuoto).
     * Difesa in profondità: itera SOLO le chiavi dello schema → ogni chiave sconosciuta/iniettata
     * è ignorata; ogni valore non conforme al tipo è scartato (mai persistito).
     * @param array<int,array> $fields definizioni da fields()
     */
    public static function sanitize(array $fields, mixed $input): ?string
    {
        if (!is_array($input)) {
            $input = [];
        }
        $out = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if (!is_scalar($raw)) {
                continue; // no array/oggetti annidati
            }
            $val = trim((string) $raw);
            if ($val === '') {
                continue;
            }
            $max = (int) ($f['maxlen'] ?? self::MAX_DEFAULT);

            switch ($f['type']) {
                case 'year':
                    if (!ctype_digit($val)) {
                        continue 2;
                    }
                    $y = (int) $val;
                    if ($y < self::YEAR_MIN || $y > self::YEAR_MAX) {
                        continue 2;
                    }
                    $val = (string) $y;
                    break;

                case 'url':
                    if (mb_strlen($val) > $max) {
                        $val = mb_substr($val, 0, $max);
                    }
                    // Solo http(s) assoluti: nessun javascript:/data: (anti-XSS in output link).
                    if (!preg_match('~^https?://~i', $val) || filter_var($val, FILTER_VALIDATE_URL) === false) {
                        continue 2;
                    }
                    break;

                case 'select':
                    if (!in_array($val, $f['options'] ?? [], true)) {
                        continue 2;
                    }
                    break;

                case 'text':
                default:
                    if (mb_strlen($val) > $max) {
                        $val = mb_substr($val, 0, $max);
                    }
                    break;
            }
            $out[$key] = $val;
        }
        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Forma per la vista/API: lista ordinata (secondo lo schema) dei soli campi valorizzati,
     * con label e tipo. Non espone mai chiavi fuori dallo schema (whitelist in lettura).
     * @param array<int,array> $fields definizioni da fields()
     * @return array<int,array{key:string,label:string,type:string,value:string}>
     */
    public static function present(array $fields, ?string $valuesJson): array
    {
        $values = self::values($valuesJson);
        if ($values === []) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            if (!isset($values[$key]) || !is_scalar($values[$key])) {
                continue;
            }
            $v = (string) $values[$key];
            if ($v === '') {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $f['label'], 'type' => $f['type'], 'value' => $v];
        }
        return $out;
    }

    /**
     * Descrittore di sezioni abilitate per tipo (single source per show + edit).
     * Additivo: le sezioni "personali" (skill/esperienze) sono nascoste per org e fan,
     * dove leggono male; il palmarès resta per gli org (trofei dell'entità).
     * @return array{attributes:bool,skills:bool,experiences:bool,achievements:bool,links:bool,roster:bool,career:bool,org_career:bool}
     */
    public static function sections(string $typeKey, bool $isOrg): array
    {
        if ($typeKey === 'fan') {
            return ['attributes' => false, 'skills' => false, 'experiences' => false, 'achievements' => false, 'links' => true, 'roster' => false, 'career' => false, 'org_career' => false];
        }
        if ($isOrg) {
            // roster = membri affiliati confermati (atleti per società/assoc; società affiliate per federazione).
            // org_career = affiliazioni PROPRIE dell'org verso una federazione (solo società/associazione, non federazione = vertice).
            $isFed = $typeKey === 'federazione';
            return ['attributes' => true, 'skills' => false, 'experiences' => false, 'achievements' => true, 'links' => true, 'roster' => true, 'career' => false, 'org_career' => !$isFed];
        }
        // atleta (e default): career = militanza confermata (Militanza / Carriera).
        return ['attributes' => false, 'skills' => true, 'experiences' => true, 'achievements' => true, 'links' => true, 'roster' => false, 'career' => true, 'org_career' => false];
    }
}
