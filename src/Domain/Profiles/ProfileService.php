<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Core\Validator;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Support\Str;

/**
 * Logica di dominio del profilo core (campi anagrafici, handle, sport, visibilità).
 * Unica sede di validazione + persistenza: riusata identica da Web (`/profilo`) e API (`PATCH /me`).
 * I controller si limitano ad adattare l'input HTTP e a tradurre il ServiceResult in output.
 */
final class ProfileService
{
    public const VISIBILITIES = ['public', 'members', 'private'];

    private ProfileRepository $profiles;
    private SportRepository $sports;

    public function __construct(?ProfileRepository $profiles = null, ?SportRepository $sports = null)
    {
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->sports   = $sports ?? new SportRepository();
    }

    /**
     * Aggiorna i campi core del profilo. Ritorna ServiceResult:
     *  - ok  → data = array normalizzato effettivamente salvato
     *  - fail → error + errors (campo→messaggio), code 422
     *
     * @param array<string,mixed> $input campi grezzi dalla richiesta
     */
    public function update(int $profileId, array $input): ServiceResult
    {
        $v = Validator::make($input, [
            'display_name'     => 'required|min:2|max:160',
            'handle'           => 'required|min:3|max:30',
            'headline'         => 'max:200',
            'bio'              => 'max:5000',
            'location_city'    => 'max:120',
            'location_region'  => 'max:120',
            'location_country' => 'max:120',
            'visibility'       => 'required|in:' . implode(',', self::VISIBILITIES),
        ]);
        if ($v->fails()) {
            return ServiceResult::fromValidator($v);
        }

        $handle = Str::handle((string) ($input['handle'] ?? ''));
        if (strlen($handle) < 3) {
            return ServiceResult::fail(I18n::t('profile.error.handle_invalid'), 422, ['handle' => I18n::t('profile.error.handle_invalid')]);
        }
        if ($this->profiles->handleTakenByOther($handle, $profileId)) {
            return ServiceResult::fail(I18n::t('profile.error.handle_taken'), 422, ['handle' => I18n::t('profile.error.handle_taken')]);
        }

        // Sport: slug → id; vuoto = nessuno; slug inesistente = errore.
        $sportSlug = trim((string) ($input['sport'] ?? ''));
        $sportId   = $sportSlug !== '' ? $this->sports->idBySlug($sportSlug) : null;
        if ($sportSlug !== '' && $sportId === null) {
            return ServiceResult::fail(I18n::t('profile.error.sport_invalid'), 422, ['sport' => I18n::t('profile.error.sport_invalid')]);
        }

        $data = [
            'display_name'     => trim((string) $input['display_name']),
            'handle'           => $handle,
            'headline'         => $this->nullable($input['headline'] ?? null),
            'bio'              => $this->nullable($input['bio'] ?? null),
            'sport_id'         => $sportId,
            'location_city'    => $this->nullable($input['location_city'] ?? null),
            'location_region'  => $this->nullable($input['location_region'] ?? null),
            'location_country' => $this->nullable($input['location_country'] ?? null),
            'visibility'       => (string) $input['visibility'],
        ];
        $this->profiles->updateCore($profileId, $data);

        // Campi descrittivi type-specific: validati contro lo schema del tipo (whitelist di chiavi).
        // Le chiavi sconosciute/iniettate sono scartate, i valori non conformi ignorati.
        $fields  = $this->profiles->schemaFieldsForProfile($profileId);
        $attrJson = ProfileAttributes::sanitize($fields, $input['attr'] ?? []);
        $this->profiles->updateAttributes($profileId, $attrJson);
        $data['attributes'] = $attrJson;

        return ServiceResult::ok($data);
    }

    /** Stringa ripulita o null se vuota (per non salvare stringhe vuote nel DB). */
    private function nullable(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }
}
