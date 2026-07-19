<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Core\Validator;
use Spoome\Domain\Feed\ActivityRepository;

/**
 * Logica di dominio delle sotto-entità del profilo (esperienze, palmarès, link).
 * Validazione, normalizzazione e sanitizzazione URL vivono qui una volta sola, riusate da Web e API.
 * Ownership garantita a valle: gli update/delete del repository filtrano sempre per profile_id.
 * Sicurezza link: ammessi solo http/https (o mailto per l'email) → mai javascript:/data: (XSS).
 */
final class ProfileDetailsService
{
    public const LINK_KINDS = ['website', 'instagram', 'x', 'facebook', 'linkedin', 'youtube', 'tiktok', 'email', 'other'];
    private const YEAR_MIN = 1900;
    private const YEAR_MAX = 2100;

    private ProfileDetailsRepository $repo;
    private ActivityRepository $activities;

    public function __construct(?ProfileDetailsRepository $repo = null, ?ActivityRepository $activities = null)
    {
        $this->repo = $repo ?? new ProfileDetailsRepository();
        $this->activities = $activities ?? new ActivityRepository();
    }

    /* ------------------------------------------------------- ESPERIENZE ---- */

    public function addExperience(int $profileId, array $input): ServiceResult
    {
        $r = $this->experienceData($input);
        if (!$r->ok) {
            return $r;
        }
        $id = $this->repo->addExperience($profileId, $r->data);
        $this->activities->record($profileId, ActivityRepository::EXPERIENCE_ADDED, $id, $r->data['role'] . ' · ' . $r->data['org_name']);
        return ServiceResult::ok(['id' => $id], [], 201);
    }

    public function updateExperience(int $id, int $profileId, array $input): ServiceResult
    {
        $r = $this->experienceData($input);
        if (!$r->ok) {
            return $r;
        }
        if (!$this->repo->updateExperience($id, $profileId, $r->data)) {
            return ServiceResult::fail(I18n::t('profile.details.not_found'), 404);
        }
        return ServiceResult::ok(['id' => $id]);
    }

    public function deleteExperience(int $id, int $profileId): ServiceResult
    {
        $this->repo->deleteExperience($id, $profileId);
        return ServiceResult::noContent();
    }

    /* --------------------------------------------------------- PALMARÈS ---- */

    public function addAchievement(int $profileId, array $input): ServiceResult
    {
        $r = $this->achievementData($input);
        if (!$r->ok) {
            return $r;
        }
        $id = $this->repo->addAchievement($profileId, $r->data);
        $this->activities->record($profileId, ActivityRepository::ACHIEVEMENT_ADDED, $id, $r->data['title']);
        return ServiceResult::ok(['id' => $id], [], 201);
    }

    public function updateAchievement(int $id, int $profileId, array $input): ServiceResult
    {
        $r = $this->achievementData($input);
        if (!$r->ok) {
            return $r;
        }
        if (!$this->repo->updateAchievement($id, $profileId, $r->data)) {
            return ServiceResult::fail(I18n::t('profile.details.not_found'), 404);
        }
        return ServiceResult::ok(['id' => $id]);
    }

    public function deleteAchievement(int $id, int $profileId): ServiceResult
    {
        $this->repo->deleteAchievement($id, $profileId);
        return ServiceResult::noContent();
    }

    /* ------------------------------------------------------------- LINK ---- */

    public function addLink(int $profileId, array $input): ServiceResult
    {
        $r = $this->linkData($input);
        if (!$r->ok) {
            return $r;
        }
        return ServiceResult::ok(['id' => $this->repo->addLink($profileId, $r->data)], [], 201);
    }

    public function updateLink(int $id, int $profileId, array $input): ServiceResult
    {
        $r = $this->linkData($input);
        if (!$r->ok) {
            return $r;
        }
        if (!$this->repo->updateLink($id, $profileId, $r->data)) {
            return ServiceResult::fail(I18n::t('profile.details.not_found'), 404);
        }
        return ServiceResult::ok(['id' => $id]);
    }

    public function deleteLink(int $id, int $profileId): ServiceResult
    {
        $this->repo->deleteLink($id, $profileId);
        return ServiceResult::noContent();
    }

    /* --------------------------------------------------- validazione input ---- */

    private function experienceData(array $d): ServiceResult
    {
        $v = Validator::make($d, [
            'org_name'    => 'required|max:160',
            'role'        => 'required|max:160',
            'location'    => 'max:160',
            'description' => 'max:1000',
        ]);
        if ($v->fails()) {
            return ServiceResult::fromValidator($v);
        }
        $cur = !empty($d['is_current']);
        return ServiceResult::ok([
            'org_name'    => trim((string) $d['org_name']),
            'role'        => trim((string) $d['role']),
            'location'    => $this->nullable($d['location'] ?? null),
            'start_year'  => $this->year($d['start_year'] ?? null),
            'end_year'    => $cur ? null : $this->year($d['end_year'] ?? null),
            'is_current'  => $cur ? 1 : 0,
            'description' => $this->nullable($d['description'] ?? null),
        ]);
    }

    private function achievementData(array $d): ServiceResult
    {
        $v = Validator::make($d, ['title' => 'required|max:200', 'description' => 'max:500']);
        if ($v->fails()) {
            return ServiceResult::fromValidator($v);
        }
        return ServiceResult::ok([
            'title'       => trim((string) $d['title']),
            'year'        => $this->year($d['year'] ?? null),
            'description' => $this->nullable($d['description'] ?? null),
        ]);
    }

    private function linkData(array $d): ServiceResult
    {
        $kind = (string) ($d['kind'] ?? '');
        if (!in_array($kind, self::LINK_KINDS, true)) {
            return ServiceResult::fail(I18n::t('profile.details.link_kind_invalid'), 422, ['kind' => I18n::t('profile.details.link_kind_invalid')]);
        }
        $url = $this->sanitizeLink($kind, trim((string) ($d['url'] ?? '')));
        if ($url === null) {
            return ServiceResult::fail(I18n::t('profile.details.link_url_invalid'), 422, ['url' => I18n::t('profile.details.link_url_invalid')]);
        }
        return ServiceResult::ok([
            'kind'  => $kind,
            'label' => $this->nullable($d['label'] ?? null),
            'url'   => $url,
        ]);
    }

    /* ------------------------------------------------------------ helpers ---- */

    /** Anno valido nel range, o null. */
    private function year(mixed $value): ?int
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '' || !ctype_digit($s)) {
            return null;
        }
        $y = (int) $s;
        return ($y >= self::YEAR_MIN && $y <= self::YEAR_MAX) ? $y : null;
    }

    private function nullable(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }

    /**
     * URL sicuro (solo http/https, o mailto per l'email) o null se non valido.
     * Blocca schemi pericolosi (javascript:, data:, ecc.).
     */
    private function sanitizeLink(string $kind, string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if ($kind === 'email') {
            $email = str_starts_with($url, 'mailto:') ? substr($url, 7) : $url;
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $email : null;
        }
        // se manca lo schema, assumiamo https://
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'https://' . $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
