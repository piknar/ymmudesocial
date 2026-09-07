<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.ymmudesocial
 * @copyright   Copyright (C) 2026 ymmude.com. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Unified social login: Google / Apple / Facebook / GitHub.
 *
 * Start URL   : /social-login/{provider}
 * Callback URL: /social-login/{provider}/callback   (register this in each provider console)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;

class PlgSystemYmmudesocial extends CMSPlugin
{
    protected $autoloadLanguage = true;

    private const PROVIDERS   = ['google', 'apple', 'facebook', 'github'];
    private const PATH_PREFIX = 'social-login';
    private const STATE_TTL   = 900; // seconds

    /* ------------------------------------------------------------------ */
    /*  Routing: /social-login/{provider}[/callback]                       */
    /* ------------------------------------------------------------------ */

    public function onAfterInitialise()
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $path = trim(Uri::getInstance()->getPath(), '/');
        $base = trim(Uri::base(true), '/');

        if ($base !== '' && strpos($path, $base) === 0) {
            $path = trim(substr($path, strlen($base)), '/');
        }

        if (strpos($path, self::PATH_PREFIX . '/') !== 0) {
            return;
        }

        $parts    = explode('/', $path);
        $provider = strtolower($parts[1] ?? '');
        $action   = strtolower($parts[2] ?? 'start');

        if (!in_array($provider, self::PROVIDERS, true)) {
            return;
        }

        if (!$this->isEnabled($provider)) {
            $this->fail('Login Unavailable', ucfirst($provider) . ' login is not enabled on this site.');
        }

        try {
            if ($action === 'callback') {
                $this->handleCallback($provider);
            } else {
                $this->handleStart($provider);
            }
        } catch (\Throwable $e) {
            $this->fail('Login Failed', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Provider configuration                                             */
    /* ------------------------------------------------------------------ */

    private function isEnabled(string $p): bool
    {
        if (!(int) $this->params->get($p . '_enabled', 0)) {
            return false;
        }

        switch ($p) {
            case 'google':
                return $this->params->get('google_client_id') && $this->params->get('google_client_secret');
            case 'github':
                return $this->params->get('github_client_id') && $this->params->get('github_client_secret');
            case 'facebook':
                return $this->params->get('facebook_app_id') && $this->params->get('facebook_app_secret');
            case 'apple':
                return $this->params->get('apple_client_id') && $this->params->get('apple_team_id')
                    && $this->params->get('apple_key_id') && $this->params->get('apple_private_key');
        }

        return false;
    }

    private function callbackUrl(string $p): string
    {
        return rtrim(Uri::root(), '/') . '/' . self::PATH_PREFIX . '/' . $p . '/callback';
    }

    private function startUrl(string $p, string $return = ''): string
    {
        $url = rtrim(Uri::root(), '/') . '/' . self::PATH_PREFIX . '/' . $p;

        if ($return !== '') {
            $url .= '?return=' . rawurlencode(base64_encode($return));
        }

        return $url;
    }

    /* ------------------------------------------------------------------ */
    /*  Step 1: redirect user to provider                                  */
    /* ------------------------------------------------------------------ */

    private function handleStart(string $p): void
    {
        $app    = Factory::getApplication();
        $return = '';
        $b64    = $app->getInput()->get('return', '', 'base64');

        if ($b64) {
            $decoded = base64_decode($b64, true);

            if ($decoded && Uri::isInternal($decoded)) {
                $return = $decoded;
            }
        }

        $state = $this->signState([
            'p'  => $p,
            'n'  => bin2hex(random_bytes(8)),
            't'  => time(),
            'r'  => $return,
        ]);

        $redirect = $this->callbackUrl($p);

        switch ($p) {
            case 'google':
                $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'response_type' => 'code',
                    'client_id'     => $this->params->get('google_client_id'),
                    'redirect_uri'  => $redirect,
                    'scope'         => 'openid email profile',
                    'access_type'   => 'online',
                    'prompt'        => 'select_account',
                    'state'         => $state,
                ]);
                break;

            case 'github':
                $url = 'https://github.com/login/oauth/authorize?' . http_build_query([
                    'client_id'    => $this->params->get('github_client_id'),
                    'redirect_uri' => $redirect,
                    'scope'        => 'read:user user:email',
                    'state'        => $state,
                ]);
                break;

            case 'facebook':
                $url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
                    'client_id'     => $this->params->get('facebook_app_id'),
                    'redirect_uri'  => $redirect,
                    'response_type' => 'code',
                    'scope'         => 'email,public_profile',
                    'state'         => $state,
                ]);
                break;

            case 'apple':
                $url = 'https://appleid.apple.com/auth/authorize?' . http_build_query([
                    'response_type' => 'code',
                    'response_mode' => 'form_post',
                    'client_id'     => $this->params->get('apple_client_id'),
                    'redirect_uri'  => $redirect,
                    'scope'         => 'name email',
                    'state'         => $state,
                ]);
                break;

            default:
                throw new \RuntimeException('Unknown provider');
        }

        $app->redirect($url);
    }

    /* ------------------------------------------------------------------ */
    /*  Step 2: provider callback                                          */
    /* ------------------------------------------------------------------ */

    private function handleCallback(string $p): void
    {
        $app   = Factory::getApplication();
        $input = $app->getInput();

        // Apple uses form_post (POST), others GET
        $src   = ($p === 'apple') ? $input->post : $input->get;
        $code  = $src->get('code', '', 'raw');
        $state = $src->get('state', '', 'raw');
        $error = $src->get('error', '', 'string');

        if ($error) {
            $desc = $src->get('error_description', '', 'string');
            throw new \RuntimeException(ucfirst($p) . ' returned an error: ' . $error . ($desc ? ' - ' . $desc : ''));
        }

        $stateData = $this->verifyState($state);

        if (!$stateData || ($stateData['p'] ?? '') !== $p) {
            throw new \RuntimeException('Invalid or expired login state. Please try again.');
        }

        if ($code === '') {
            throw new \RuntimeException(ucfirst($p) . ' did not return an authorization code.');
        }

        // Fetch normalized profile: ['uid','email','email_verified','name','username']
        switch ($p) {
            case 'google':
                $profile = $this->profileGoogle($code);
                break;
            case 'github':
                $profile = $this->profileGithub($code);
                break;
            case 'facebook':
                $profile = $this->profileFacebook($code);
                break;
            case 'apple':
                $profile = $this->profileApple($code, $src->get('user', '', 'raw'));
                break;
            default:
                throw new \RuntimeException('Unknown provider');
        }

        if (empty($profile['uid'])) {
            throw new \RuntimeException('Could not read your ' . ucfirst($p) . ' account id.');
        }

        $user = $this->resolveUser($p, $profile);

        if (!$user || !$user->id) {
            throw new \RuntimeException('Failed to find or create your account. Please contact the administrator.');
        }

        $this->loginUser($user, $stateData['r'] ?? '');
    }

    /* ------------------------------------------------------------------ */
    /*  Provider profile fetchers                                          */
    /* ------------------------------------------------------------------ */

    private function http()
    {
        return HttpFactory::getHttp([], ['curl', 'stream']);
    }

    private function jsonPost(string $url, array $data, array $headers = []): array
    {
        $resp = $this->http()->post($url, $data, $headers, 30);
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);

        if ($resp->getStatusCode() >= 400) {
            $msg = is_array($json) ? ($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? $body) : $body;
            throw new \RuntimeException('Token exchange failed: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return is_array($json) ? $json : [];
    }

    private function jsonGet(string $url, array $headers = []): array
    {
        $resp = $this->http()->get($url, $headers, 30);
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);

        if ($resp->getStatusCode() >= 400) {
            throw new \RuntimeException('Profile request failed (' . $resp->getStatusCode() . '): ' . substr($body, 0, 200));
        }

        return is_array($json) ? $json : [];
    }

    private function profileGoogle(string $code): array
    {
        $token = $this->jsonPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $this->params->get('google_client_id'),
            'client_secret' => $this->params->get('google_client_secret'),
            'redirect_uri'  => $this->callbackUrl('google'),
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($token['access_token'])) {
            throw new \RuntimeException('Google did not return an access token.');
        }

        $info = $this->jsonGet('https://www.googleapis.com/oauth2/v3/userinfo', [
            'Authorization' => 'Bearer ' . $token['access_token'],
        ]);

        return [
            'uid'            => $info['sub'] ?? '',
            'email'          => $info['email'] ?? '',
            'email_verified' => !empty($info['email_verified']),
            'name'           => $info['name'] ?? '',
            'username'       => '',
        ];
    }

    private function profileGithub(string $code): array
    {
        $token = $this->jsonPost('https://github.com/login/oauth/access_token', [
            'code'          => $code,
            'client_id'     => $this->params->get('github_client_id'),
            'client_secret' => $this->params->get('github_client_secret'),
            'redirect_uri'  => $this->callbackUrl('github'),
        ], ['Accept' => 'application/json']);

        if (empty($token['access_token'])) {
            throw new \RuntimeException('GitHub did not return an access token' . (isset($token['error_description']) ? ': ' . $token['error_description'] : '.'));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Accept'        => 'application/vnd.github+json',
            'User-Agent'    => 'ymmude-social-login',
        ];

        $user   = $this->jsonGet('https://api.github.com/user', $headers);
        $email  = $user['email'] ?? '';
        $verif  = false;

        // Primary email is often hidden; fetch verified primary
        try {
            $emails = $this->jsonGet('https://api.github.com/user/emails', $headers);

            foreach ($emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) {
                    $email = $e['email'];
                    $verif = true;
                    break;
                }
            }

            if (!$verif && $email === '') {
                foreach ($emails as $e) {
                    if (!empty($e['verified'])) {
                        $email = $e['email'];
                        $verif = true;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore, fall back to public email
        }

        return [
            'uid'            => isset($user['id']) ? (string) $user['id'] : '',
            'email'          => $email,
            'email_verified' => $verif,
            'name'           => $user['name'] ?? ($user['login'] ?? ''),
            'username'       => $user['login'] ?? '',
        ];
    }

    private function profileFacebook(string $code): array
    {
        $token = $this->jsonGet('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
            'client_id'     => $this->params->get('facebook_app_id'),
            'client_secret' => $this->params->get('facebook_app_secret'),
            'redirect_uri'  => $this->callbackUrl('facebook'),
            'code'          => $code,
        ]));

        if (empty($token['access_token'])) {
            throw new \RuntimeException('Facebook did not return an access token.');
        }

        $me = $this->jsonGet('https://graph.facebook.com/v19.0/me?' . http_build_query([
            'fields'       => 'id,name,email',
            'access_token' => $token['access_token'],
        ]));

        return [
            'uid'            => $me['id'] ?? '',
            'email'          => $me['email'] ?? '',
            'email_verified' => !empty($me['email']), // Facebook only returns verified emails
            'name'           => $me['name'] ?? '',
            'username'       => '',
        ];
    }

    private function profileApple(string $code, string $userJson): array
    {
        $token = $this->jsonPost('https://appleid.apple.com/auth/token', [
            'client_id'     => $this->params->get('apple_client_id'),
            'client_secret' => $this->appleClientSecret(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->callbackUrl('apple'),
        ]);

        if (empty($token['id_token'])) {
            throw new \RuntimeException('Apple did not return an identity token.');
        }

        // id_token comes directly from Apple over TLS -> payload is trustworthy
        $parts = explode('.', $token['id_token']);

        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed Apple identity token.');
        }

        $claims = json_decode($this->b64urlDecode($parts[1]), true) ?: [];

        if (($claims['aud'] ?? '') !== $this->params->get('apple_client_id')) {
            throw new \RuntimeException('Apple identity token audience mismatch.');
        }

        // Name is only sent on the very first authorization, in the `user` POST field
        $name = '';

        if ($userJson) {
            $u = json_decode($userJson, true);

            if (is_array($u)) {
                $name = trim(($u['name']['firstName'] ?? '') . ' ' . ($u['name']['lastName'] ?? ''));
            }
        }

        $verified = $claims['email_verified'] ?? false;
        $verified = ($verified === true || $verified === 'true');

        return [
            'uid'            => $claims['sub'] ?? '',
            'email'          => $claims['email'] ?? '',
            'email_verified' => $verified,
            'name'           => $name,
            'username'       => '',
        ];
    }

    /**
     * Build the ES256-signed JWT Apple requires as client_secret.
     */
    private function appleClientSecret(): string
    {
        $teamId   = trim($this->params->get('apple_team_id'));
        $keyId    = trim($this->params->get('apple_key_id'));
        $clientId = trim($this->params->get('apple_client_id'));
        $pem      = trim($this->params->get('apple_private_key'));

        if (strpos($pem, '-----BEGIN') === false) {
            // Allow pasting the raw base64 body only
            $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END PRIVATE KEY-----";
        }

        $header  = $this->b64urlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT']));
        $now     = time();
        $payload = $this->b64urlEncode(json_encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + 86400 * 30,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ]));

        $key = openssl_pkey_get_private($pem);

        if (!$key) {
            throw new \RuntimeException('Apple private key could not be loaded. Check the .p8 key contents in plugin settings.');
        }

        $der = '';

        if (!openssl_sign($header . '.' . $payload, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign Apple client secret.');
        }

        return $header . '.' . $payload . '.' . $this->b64urlEncode($this->derToRawSignature($der));
    }

    /** Convert DER ECDSA signature to raw 64-byte R||S. */
    private function derToRawSignature(string $der): string
    {
        $off = 0;

        if (ord($der[$off++]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature.');
        }

        $len = ord($der[$off++]);

        if ($len & 0x80) {
            $off += ($len & 0x7f);
        }

        $off++; // 0x02
        $rLen = ord($der[$off++]);
        $r    = substr($der, $off, $rLen);
        $off += $rLen;
        $off++; // 0x02
        $sLen = ord($der[$off++]);
        $s    = substr($der, $off, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /* ------------------------------------------------------------------ */
    /*  User resolution / creation                                         */
    /* ------------------------------------------------------------------ */

    private function ensureTable(): void
    {
        $db = Factory::getDbo();
        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__ymmudesocial_links') . ' (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `provider` VARCHAR(20) NOT NULL,
                `provider_uid` VARCHAR(191) NOT NULL,
                `email` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created` DATETIME NOT NULL,
                `last_login` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_provider_uid` (`provider`, `provider_uid`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        )->execute();
    }

    private function resolveUser(string $p, array $profile): ?User
    {
        $this->ensureTable();
        $db  = Factory::getDbo();
        $now = Factory::getDate()->toSql();

        // 1. Existing link?
        $q = $db->getQuery(true)
            ->select($db->quoteName('user_id'))
            ->from($db->quoteName('#__ymmudesocial_links'))
            ->where($db->quoteName('provider') . ' = ' . $db->quote($p))
            ->where($db->quoteName('provider_uid') . ' = ' . $db->quote($profile['uid']));
        $userId = (int) $db->setQuery($q)->loadResult();

        if ($userId) {
            $user = User::getInstance($userId);

            if ($user->id) {
                $this->touchLink($p, $profile['uid'], $now);
                $this->maybeActivate($user, $profile);

                return $user;
            }

            // dangling link -> remove
            $db->setQuery(
                $db->getQuery(true)->delete($db->quoteName('#__ymmudesocial_links'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId)
            )->execute();
        }

        // 2. Match by verified email
        $email = strtolower(trim($profile['email'] ?? ''));

        if ($email !== '') {
            $q = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__users'))
                ->where('LOWER(' . $db->quoteName('email') . ') = ' . $db->quote($email));
            $userId = (int) $db->setQuery($q)->loadResult();

            if ($userId) {
                if (!$profile['email_verified'] && !(int) $this->params->get('link_unverified', 0)) {
                    throw new \RuntimeException('An account with this email already exists, but ' . ucfirst($p) . ' did not confirm the email address is verified. Please log in with your password first.');
                }

                $user = User::getInstance($userId);
                $this->saveLink($user->id, $p, $profile['uid'], $email, $now);
                $this->maybeActivate($user, $profile);

                return $user;
            }
        }

        // 3. Create new user
        if (!(int) $this->params->get('allow_user_creation', 1)) {
            throw new \RuntimeException('This ' . ucfirst($p) . ' account is not linked to any user on this site, and new registrations via social login are disabled.');
        }

        if ($email === '') {
            throw new \RuntimeException(ucfirst($p) . ' did not share an email address. Please allow email access or register manually.');
        }

        $user = $this->createUser($p, $profile, $email);

        if ($user) {
            $this->saveLink($user->id, $p, $profile['uid'], $email, $now);
        }

        return $user;
    }

    private function saveLink(int $userId, string $p, string $uid, string $email, string $now): void
    {
        $db  = Factory::getDbo();
        $obj = (object) [
            'user_id'      => $userId,
            'provider'     => $p,
            'provider_uid' => $uid,
            'email'        => $email,
            'created'      => $now,
            'last_login'   => $now,
        ];

        try {
            $db->insertObject('#__ymmudesocial_links', $obj);
        } catch (\Throwable $e) {
            // duplicate -> just touch
            $this->touchLink($p, $uid, $now);
        }
    }

    private function touchLink(string $p, string $uid, string $now): void
    {
        $db = Factory::getDbo();
        $db->setQuery(
            $db->getQuery(true)->update($db->quoteName('#__ymmudesocial_links'))
                ->set($db->quoteName('last_login') . ' = ' . $db->quote($now))
                ->where($db->quoteName('provider') . ' = ' . $db->quote($p))
                ->where($db->quoteName('provider_uid') . ' = ' . $db->quote($uid))
        )->execute();
    }

    /** Activate users still waiting on the email activation link when provider verified their email. */
    private function maybeActivate(User $user, array $profile): void
    {
        if (!(int) $this->params->get('auto_activate', 1) || !$profile['email_verified']) {
            return;
        }

        if ($user->activation !== '' && (int) $user->block === 1) {
            $db = Factory::getDbo();
            $db->setQuery(
                $db->getQuery(true)->update($db->quoteName('#__users'))
                    ->set($db->quoteName('block') . ' = 0')
                    ->set($db->quoteName('activation') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('id') . ' = ' . (int) $user->id)
            )->execute();
            $user->block      = 0;
            $user->activation = '';
        }
    }

    private function uniqueUsername(string $base): string
    {
        $base = preg_replace('/[^a-z0-9._-]/i', '', $base);
        $base = substr($base !== '' ? $base : 'user', 0, 40);
        $try  = $base;
        $i    = 1;

        while (UserHelper::getUserId($try)) {
            $try = $base . $i++;
        }

        return $try;
    }

    private function createUser(string $p, array $profile, string $email): ?User
    {
        $seed = $profile['username'] !== '' ? $profile['username'] : strstr($email, '@', true);
        $name = trim($profile['name'] ?? '');

        if ($name === '') {
            $name = $seed;
        }

        $groupId = (int) $this->params->get('default_group', 2) ?: 2;

        $user = new User();
        $data = [
            'name'      => $name,
            'username'  => $this->uniqueUsername($seed),
            'email'     => $email,
            'password'  => UserHelper::genRandomPassword(24),
            'password2' => '',
            'groups'    => [$groupId],
            'block'     => 0,
            'activation' => '',
            'sendEmail' => 0,
            'requireReset' => 0,
        ];
        $data['password2'] = $data['password'];

        if (!$user->bind($data)) {
            throw new \RuntimeException('Could not create account: ' . $user->getError());
        }

        if (!$user->save()) {
            throw new \RuntimeException('Could not save account: ' . $user->getError());
        }

        return $user;
    }

    /* ------------------------------------------------------------------ */
    /*  Log the user in                                                    */
    /* ------------------------------------------------------------------ */

    private function loginUser(User $user, string $return = ''): void
    {
        $app = Factory::getApplication();

        if ((int) $user->block === 1) {
            throw new \RuntimeException('Your account is blocked. Please contact the administrator.');
        }

        if ($user->activation !== '') {
            throw new \RuntimeException('Your account is not activated yet. Please check your email for the activation link.');
        }

        $response = [
            'status'        => Authentication::STATUS_SUCCESS,
            'type'          => 'ymmudesocial',
            'error_message' => '',
            'username'      => $user->username,
            'password'      => '',
            'email'         => $user->email,
            'fullname'      => $user->name,
            'language'      => '',
        ];

        $options = [
            'action'       => 'core.login.site',
            'remember'     => (bool) $this->params->get('remember_me', 1),
            'silent'       => true,
            'autoregister' => false,
        ];

        $dispatcher = $app->getDispatcher();
        $results    = [];

        // User plugins (plg_user_joomla) perform the actual session login
        PluginHelper::importPlugin('user', null, true, $dispatcher);

        if (class_exists('Joomla\\CMS\\Event\\User\\LoginEvent')) {
            $event   = new \Joomla\CMS\Event\User\LoginEvent('onUserLogin', ['subject' => $response, 'options' => $options]);
            $results = $dispatcher->dispatch('onUserLogin', $event)->getArgument('result', []);
        } else {
            $results = $app->triggerEvent('onUserLogin', [$response, $options]);
        }

        if (in_array(false, (array) $results, true)) {
            throw new \RuntimeException('Login was rejected by a system plugin.');
        }

        $options['user']         = $app->getIdentity();
        $options['responseType'] = 'ymmudesocial';

        if (class_exists('Joomla\\CMS\\Event\\User\\AfterLoginEvent')) {
            $dispatcher->dispatch('onUserAfterLogin', new \Joomla\CMS\Event\User\AfterLoginEvent('onUserAfterLogin', ['options' => $options]));
        }

        if ($return === '' || !Uri::isInternal($return)) {
            $return = trim((string) $this->params->get('redirect_after_login', ''));

            if ($return === '') {
                $return = Route::_('index.php?option=com_users&view=profile', false);
            } elseif (strpos($return, 'http') !== 0 && strpos($return, '/') !== 0) {
                $return = Route::_($return, false);
            }
        }

        $app->redirect($return);
    }

    /* ------------------------------------------------------------------ */
    /*  Button rendering                                                   */
    /* ------------------------------------------------------------------ */

    private function enabledProviders(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $p) {
            if ($this->isEnabled($p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private function icon(string $p): string
    {
        switch ($p) {
            case 'google':
                return '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18A11 11 0 0 0 1 12c0 1.77.42 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>';
            case 'apple':
                return '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M16.37 12.62c.03 2.94 2.58 3.92 2.61 3.93-.02.07-.41 1.4-1.35 2.77-.81 1.18-1.66 2.36-2.99 2.39-1.31.02-1.73-.78-3.22-.78-1.5 0-1.96.75-3.2.8-1.28.05-2.26-1.28-3.08-2.46C3.47 16.85 2.19 12.44 3.91 9.42c.85-1.5 2.38-2.45 4.04-2.47 1.26-.02 2.45.85 3.22.85.77 0 2.22-1.05 3.74-.9.64.03 2.42.26 3.57 1.94-.09.06-2.13 1.25-2.11 3.78zM13.9 5.3c.68-.83 1.14-1.98 1.02-3.13-.98.04-2.17.65-2.88 1.48-.63.73-1.18 1.9-1.03 3.02 1.09.09 2.21-.55 2.89-1.37z"/></svg>';
            case 'facebook':
                return '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.23 2.69.23v2.96h-1.52c-1.49 0-1.96.93-1.96 1.88V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12z"/><path fill="#fff" d="M16.67 15.47 17.2 12h-3.33V9.76c0-.95.47-1.88 1.96-1.88h1.52V4.92s-1.38-.23-2.69-.23c-2.74 0-4.53 1.66-4.53 4.67V12H7.08v3.47h3.04v8.38a12.1 12.1 0 0 0 3.76 0v-8.38h2.79z"/></svg>';
            case 'github':
                return '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 .3a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.7-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0c2.3-1.5 3.3-1.2 3.3-1.2.7 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .3z"/></svg>';
        }

        return '';
    }

    private function label(string $p): string
    {
        $names = ['google' => 'Google', 'apple' => 'Apple', 'facebook' => 'Facebook', 'github' => 'GitHub'];
        $tpl   = (string) $this->params->get('button_text', 'Continue with %s');

        return sprintf($tpl, $names[$p] ?? ucfirst($p));
    }

    public function getButtonsHtml(string $return = ''): string
    {
        $providers = $this->enabledProviders();

        if (!$providers) {
            return '';
        }

        $heading = trim((string) $this->params->get('heading_text', 'or continue with'));
        $html    = '<div class="ymsocial">';

        if ($heading !== '') {
            $html .= '<div class="ymsocial-sep"><span>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</span></div>';
        }

        $html .= '<div class="ymsocial-buttons">';

        foreach ($providers as $p) {
            $html .= '<a class="ymsocial-btn ymsocial-' . $p . '" href="' . htmlspecialchars($this->startUrl($p, $return), ENT_QUOTES, 'UTF-8') . '" rel="nofollow">'
                . '<span class="ymsocial-icon">' . $this->icon($p) . '</span>'
                . '<span class="ymsocial-label">' . htmlspecialchars($this->label($p), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private function css(): string
    {
        return <<<CSS
.ymsocial{margin:1.25rem 0 .5rem}
.ymsocial-sep{display:flex;align-items:center;gap:.75rem;margin:0 0 .9rem;color:#8b8fa3;font-size:.8rem;text-transform:uppercase;letter-spacing:.08em}
.ymsocial-sep::before,.ymsocial-sep::after{content:"";flex:1;height:1px;background:rgba(139,92,246,.35)}
.ymsocial-buttons{display:flex;flex-direction:column;gap:.6rem}
.ymsocial-btn{display:flex;align-items:center;justify-content:center;gap:.65rem;padding:.7rem 1rem;border-radius:8px;border:1px solid rgba(139,92,246,.45);background:rgba(20,20,32,.85);color:#e5e7eb;font-weight:600;font-size:.95rem;text-decoration:none;transition:all .18s ease;font-family:inherit}
.ymsocial-btn:hover{border-color:#06b6d4;box-shadow:0 0 0 1px rgba(6,182,212,.35),0 6px 18px rgba(6,182,212,.15);transform:translateY(-1px);color:#fff;text-decoration:none}
.ymsocial-icon{display:inline-flex;width:20px;height:20px}
.ymsocial-icon svg{width:20px;height:20px}
.ymsocial-apple .ymsocial-icon{color:#fff}
.ymsocial-github .ymsocial-icon{color:#fff}
body.a0-light .ymsocial-btn{background:#fff;color:#111827;border-color:#c4b5fd}
body.a0-light .ymsocial-btn:hover{color:#111827}
body.a0-light .ymsocial-apple .ymsocial-icon,body.a0-light .ymsocial-github .ymsocial-icon{color:#111}
body.a0-light .ymsocial-sep{color:#6b7280}
CSS;
    }

    public function onBeforeCompileHead()
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site') || $app->getIdentity()->id) {
            return;
        }

        $html = $this->getButtonsHtml();

        if ($html === '') {
            return;
        }

        $doc = $app->getDocument();

        if (!method_exists($doc, 'addStyleDeclaration')) {
            return;
        }

        $doc->addStyleDeclaration($this->css());

        $targets = [];

        foreach (['module_target', 'component_target', 'registration_target'] as $key) {
            $t = trim((string) $this->params->get($key, ''));

            if ($t !== '') {
                $targets[] = $t;
            }
        }

        if (!$targets) {
            return;
        }

        $json   = json_encode($html);
        $tjson  = json_encode($targets);
        $script = <<<JS
document.addEventListener('DOMContentLoaded',function(){
  var html={$json},targets={$tjson};
  targets.forEach(function(sel){
    document.querySelectorAll(sel).forEach(function(el){
      if(el.closest('form')&&el.closest('form').querySelector('.ymsocial'))return;
      if(el.previousElementSibling&&el.previousElementSibling.classList.contains('ymsocial'))return;
      el.insertAdjacentHTML('beforebegin',html);
    });
  });
});
JS;
        $doc->addScriptDeclaration($script);
    }

    public function onContentPrepare($context, &$row, &$params, $page = 0)
    {
        if (!isset($row->text) || strpos($row->text, '{ymmude_social_login}') === false) {
            return;
        }

        $html = Factory::getApplication()->getIdentity()->id ? '' : $this->getButtonsHtml();

        if ($html !== '') {
            $html = '<style>' . $this->css() . '</style>' . $html;
        }

        $row->text = str_replace('{ymmude_social_login}', $html, $row->text);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function secretKey(): string
    {
        return hash('sha256', Factory::getApplication()->get('secret') . '|ymmudesocial|state');
    }

    private function signState(array $data): string
    {
        $payload = $this->b64urlEncode(json_encode($data));
        $sig     = substr(hash_hmac('sha256', $payload, $this->secretKey()), 0, 40);

        return $payload . '.' . $sig;
    }

    private function verifyState(string $state): ?array
    {
        if ($state === '' || strpos($state, '.') === false) {
            return null;
        }

        [$payload, $sig] = explode('.', $state, 2);
        $expected = substr(hash_hmac('sha256', $payload, $this->secretKey()), 0, 40);

        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $data = json_decode($this->b64urlDecode($payload), true);

        if (!is_array($data) || !isset($data['t']) || (time() - (int) $data['t']) > self::STATE_TTL) {
            return null;
        }

        return $data;
    }

    private function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private function fail(string $title, string $message): void
    {
        $app = Factory::getApplication();

        while (ob_get_level()) {
            ob_end_clean();
        }

        $loginUrl = rtrim(Uri::root(), '/') . '/index.php?option=com_users&view=login';
        $siteName = htmlspecialchars((string) $app->get('sitename'), ENT_QUOTES, 'UTF-8');
        $title    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $message  = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        include __DIR__ . '/tmpl/error.php';
        $app->close();
    }
}
