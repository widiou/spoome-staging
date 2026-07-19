<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Core\Validator;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Users\User;
use Spoome\Support\Str;
use Throwable;

/**
 * Creazione di una PAGINA organizzazione (società/associazione/federazione) da parte di un utente
 * loggato. Modello LinkedIn: la persona si registra come persona, poi CREA pagine che amministra.
 *
 * Transazione atomica: `profiles` (user_id = creatore, primary owner denormalizzato, claim_status
 * 'claimed') + `profile_members` (creatore = owner, sorgente di verità dell'authz). Gratuita e senza
 * verifica (decisione founder); la pagina nasce NON verificata. Rate-limit anti mass-squatting.
 */
final class PageService
{
    /** Solo i tipi organizzazione possono essere creati come pagina (mai atleta/fan). */
    public const ORG_TYPES = ['societa', 'associazione', 'federazione'];

    /** Anti-abuso: massimo N pagine create per utente nella finestra (minuti). */
    private const RL_MAX     = 5;
    private const RL_WINDOW  = 1440; // 24h

    private PDO $pdo;
    private ProfileRepository $profiles;
    private ProfileMemberRepository $members;
    private RateLimiter $rateLimiter;

    public function __construct(
        ?PDO $pdo = null,
        ?ProfileRepository $profiles = null,
        ?ProfileMemberRepository $members = null,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->pdo         = $pdo ?? Db::connection();
        $this->profiles    = $profiles ?? new ProfileRepository($this->pdo);
        $this->members     = $members ?? new ProfileMemberRepository($this->pdo);
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($this->pdo);
    }

    /**
     * Crea la pagina. @param array<string,mixed> $input {type, display_name, handle?}
     * @return ServiceResult ok(['id'=>int,'handle'=>string]) | fail(errore, code)
     */
    public function create(User $creator, array $input, string $ip = 'unknown'): ServiceResult
    {
        $rlKey = 'page:' . $creator->id;
        if ($this->rateLimiter->tooManyByKey($rlKey, self::RL_MAX, self::RL_WINDOW)) {
            return ServiceResult::fail(I18n::t('page.error.throttled'), 429);
        }

        $v = Validator::make($input, [
            'type'         => 'required|in:' . implode(',', self::ORG_TYPES),
            'display_name' => 'required|min:2|max:160',
        ]);
        if ($v->fails()) {
            return ServiceResult::fromValidator($v);
        }

        $typeKey = (string) $input['type'];
        $typeId  = $this->profiles->typeIdByKey($typeKey);
        if ($typeId === null || !in_array($typeKey, self::ORG_TYPES, true)) {
            return ServiceResult::fail(I18n::t('page.error.type_invalid'), 422, ['type' => I18n::t('page.error.type_invalid')]);
        }

        $displayName = trim((string) $input['display_name']);

        // Handle: se fornito → normalizzato + univoco; altrimenti derivato dal nome (garantito univoco).
        $rawHandle = trim((string) ($input['handle'] ?? ''));
        if ($rawHandle !== '') {
            $handle = Str::handle($rawHandle);
            if (strlen($handle) < 3) {
                return ServiceResult::fail(I18n::t('profile.error.handle_invalid'), 422, ['handle' => I18n::t('profile.error.handle_invalid')]);
            }
            // handleTakenByOther(h, 0): nessun profilo ha id 0 → equivale a "handle già esistente".
            if ($this->profiles->handleTakenByOther($handle, 0)) {
                return ServiceResult::fail(I18n::t('profile.error.handle_taken'), 422, ['handle' => I18n::t('profile.error.handle_taken')]);
            }
        } else {
            $handle = $this->profiles->uniqueHandle($displayName);
        }

        try {
            $this->pdo->beginTransaction();
            $pid = $this->profiles->create($creator->id, $typeId, $handle, $displayName, null);
            $this->members->addMember($pid, $creator->id, 'owner', null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ServiceResult::fail(I18n::t('page.error.create_failed'), 500);
        }

        $this->rateLimiter->hit($rlKey, $ip);

        return ServiceResult::ok(['id' => $pid, 'handle' => $handle], [], 201);
    }
}
