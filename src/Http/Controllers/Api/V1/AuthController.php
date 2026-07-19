<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Validator;
use Spoome\Domain\Auth\AuthService;
use Spoome\Domain\Auth\TokenService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API di autenticazione (JSON) per web-JS e app native. Auth stateless via token Bearer. Testi via i18n.
 */
final class AuthController extends ApiController
{
    public function register(Request $request): void
    {
        $data  = $request->body();
        $types = implode(',', (new ProfileRepository())->activePersonalTypeKeys());

        $v = Validator::make($data, [
            'email'        => 'required|email|max:190',
            'password'     => 'required|min:10|max:200|confirmed',
            'display_name' => 'required|min:2|max:160',
            'profile_type' => 'required|in:' . $types,
        ]);
        if ($v->fails()) {
            Response::error($v->firstError() ?? I18n::t('api.error.invalid_data'), 422);
            return;
        }
        if (!AuthService::isStrongPassword((string) $data['password'])) {
            Response::error(AuthService::passwordPolicyMessage(), 422);
            return;
        }

        (new AuthService())->register(
            (string) $data['email'], (string) $data['password'],
            (string) $data['display_name'], (string) $data['profile_type'], $request->ip()
        );
        // Anti-enumeration: risposta identica sia che l'email esista o meno.
        Response::json(['message' => I18n::t('api.auth.registered')], 201);
    }

    public function login(Request $request): void
    {
        $data = $request->body();
        $v = Validator::make($data, ['email' => 'required|email', 'password' => 'required']);
        if ($v->fails()) {
            Response::error(I18n::t('api.error.credentials_required'), 422);
            return;
        }

        $result = (new AuthService())->login((string) $data['email'], (string) $data['password'], $request->ip());
        if (!$result['ok']) {
            Response::error($result['error'], $result['code'] ?? 401);
            return;
        }

        $device = isset($data['device']) ? (string) $data['device'] : ($_SERVER['HTTP_USER_AGENT'] ?? null);
        $tokens = (new TokenService())->issue($result['user']->id, $device);
        Response::json([
            'token_type'    => 'Bearer',
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'expires_in'    => $tokens['expires_in'],
        ]);
    }

    public function refresh(Request $request): void
    {
        $raw = (string) $request->input('refresh_token', '');
        if ($raw === '') {
            Response::error(I18n::t('api.error.refresh_missing'), 422);
            return;
        }
        $tokens = (new TokenService())->refresh($raw, $_SERVER['HTTP_USER_AGENT'] ?? null);
        if ($tokens === null) {
            Response::error(I18n::t('api.error.refresh_invalid'), 401);
            return;
        }
        Response::json([
            'token_type'    => 'Bearer',
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'expires_in'    => $tokens['expires_in'],
        ]);
    }

    public function logout(Request $request): void
    {
        $svc = new TokenService();
        if (($access = $request->bearerToken()) !== null) {
            $svc->revoke($access);
        }
        if (($refresh = (string) $request->input('refresh_token', '')) !== '') {
            $svc->revoke($refresh);
        }
        Response::noContent();
    }

    public function me(Request $request): void
    {
        $user = $this->requireUser($request);
        if ($user === null) {
            return;
        }
        $profile = (new ProfileRepository())->findByUserId($user->id);
        Response::json([
            'id'      => $user->id,
            'email'   => $user->email,
            'role'    => $user->role,
            'profile' => $profile === null ? null : [
                'handle'       => $profile->handle,
                'display_name' => $profile->displayName,
                'type_id'      => $profile->profileTypeId,
                'verified'     => $profile->isVerified(),
            ],
        ]);
    }

    public function verify(Request $request): void
    {
        $token  = (string) ($request->query['token'] ?? '');
        $userId = $token !== '' ? (new AuthService())->verifyEmail($token) : null;
        if ($userId === null) {
            Response::error(I18n::t('api.auth.verify_invalid'), 400);
            return;
        }
        Response::json(['message' => I18n::t('api.auth.verified')]);
    }

    public function forgotPassword(Request $request): void
    {
        $email = (string) $request->input('email', '');
        if ($email !== '') {
            (new AuthService())->requestPasswordReset($email, $request->ip());
        }
        Response::json(['message' => I18n::t('api.auth.forgot_generic')]);
    }

    public function resetPassword(Request $request): void
    {
        $token = (string) $request->input('token', '');
        $pw    = (string) $request->input('password', '');
        if ($token === '' || $pw === '') {
            Response::error(I18n::t('api.error.token_password_required'), 422);
            return;
        }
        $result = (new AuthService())->resetPassword($token, $pw, $request->ip());
        if (!$result['ok']) {
            Response::error($result['error'] ?? I18n::t('auth.error.reset_failed'), 400);
            return;
        }
        Response::json(['message' => I18n::t('api.auth.reset_done')]);
    }
}
