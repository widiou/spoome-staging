<?php

namespace Spoome\Core;

/**
 * Esito di un'operazione applicativa (Service). Contratto UNICO tra il livello di dominio
 * e i controller: il Web lo traduce in redirect+flash, l'API in JSON envelope.
 *
 * Così la logica (validazione, ownership, persistenza) vive una volta sola nel Service ed è
 * riusata identica da web e app native — niente duplicazione al crescere delle funzionalità.
 *
 * - `ok`     true/false
 * - `data`   payload in caso di successo (id creato, entità, lista…)
 * - `error`  messaggio primario già localizzato (per flash/UI)
 * - `errors` mappa campo→messaggio (per evidenziare i campi nei form / risposta API dettagliata)
 * - `code`   suggerimento di status HTTP (200/201/204/400/401/403/404/422/429)
 * - `meta`   metadati (paginazione, totali…)
 */
final class ServiceResult
{
    /**
     * @param array<string,string> $errors
     * @param array<string,mixed>  $meta
     */
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly ?string $error = null,
        public readonly array $errors = [],
        public readonly int $code = 200,
        public readonly array $meta = [],
    ) {
    }

    /** Successo. */
    public static function ok(mixed $data = null, array $meta = [], int $code = 200): self
    {
        return new self(true, $data, null, [], $code, $meta);
    }

    /** Successo senza corpo (es. delete) → 204. */
    public static function noContent(): self
    {
        return new self(true, null, null, [], 204, []);
    }

    /**
     * Fallimento con messaggio primario e (opzionale) mappa campo→errore.
     * @param array<string,string> $errors
     */
    public static function fail(string $error, int $code = 422, array $errors = []): self
    {
        return new self(false, null, $error, $errors, $code, []);
    }

    /** Fallimento a partire da un Validator: usa il primo errore come messaggio primario. */
    public static function fromValidator(Validator $v, int $code = 422): self
    {
        return new self(false, null, $v->firstError(), $v->errors(), $code, []);
    }
}
