<?php

/**
 * @file plugins/generic/demoMode/DemoModePlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class DemoModePlugin
 *
 * @brief Read-only "demo mode" for public OJS demonstrations.
 *
 * Visitors can sign in with demo accounts (administrator, editor, reviewer,
 * author), open every page, form and wizard, but nothing is ever written:
 * all state-changing requests (POST/PUT/PATCH/DELETE, plus a short list of
 * GET operations with side effects) are refused with a friendly message.
 * Only the "superusers" listed in config.inc.php may change the system.
 *
 * Configuration (config.inc.php):
 *
 *   [demo]
 *   enabled = On                   ; master switch (cannot be changed from the UI)
 *   superusers = "admin"           ; comma-separated usernames allowed to write
 *   password = "demo"              ; shown on the login page next to the demo accounts
 *   accounts = "demo,demo-editor,demo-reviewer,demo-author"
 *   reset_hours = 3                ; shown in the banner ("data is reset every N hours")
 */

namespace APP\plugins\generic\demoMode;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\APIRouter;
use PKP\core\PKPComponentRouter;
use PKP\core\PKPPageRouter;
use PKP\core\PKPRequest;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Validation;

class DemoModePlugin extends GenericPlugin
{
    /** HTTP methods that never change state */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Write requests that stay allowed for everybody ("page/op") */
    public const ALLOWED_WRITES = [
        'login/signIn',
        'login/signOut',
        'search/search',
        'search/index',
    ];

    /**
     * GET operations that have side effects or disclose server details
     * ("page/op" for page handlers, "component/op" for component handlers).
     */
    public const BLOCKED_READS = [
        'login/signInAsUser',
        'login/signOutAsUser',
        'login/resetPassword',
        'login/requestResetPassword',
        'user/activateUser',
        'admin/systemInfo',
        'admin/phpinfo',
        'admin/expireSessions',
        'admin/clearTemplateCache',
        'admin/clearDataCache',
        'admin/downloadScheduledTaskLogFile',
        'admin/clearScheduledTaskLogFiles',
        'payment/plugin',
    ];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        Hook::add('Dispatcher::dispatch', $this->enforce(...));
        Hook::add('TemplateManager::display', $this->addAssets(...));

        return $success;
    }

    /**
     * The plugin is a site-wide plugin, controlled from config.inc.php.
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * @copydoc LazyLoadPlugin::getEnabled()
     *
     * @param null|mixed $contextId
     */
    public function getEnabled($contextId = null)
    {
        return (bool) Config::getVar('demo', 'enabled', false);
    }

    /**
     * Enabling/disabling happens only in config.inc.php.
     */
    public function getCanEnable()
    {
        return false;
    }

    public function getCanDisable()
    {
        return false;
    }

    // ---------------------------------------------------------------------
    // Enforcement
    // ---------------------------------------------------------------------

    /**
     * Refuse writes before the request reaches any handler.
     */
    public function enforce(string $hookName, array $args): bool
    {
        /** @var PKPRequest $request */
        $request = $args[0];

        if ($this->isPrivileged($request)) {
            return Hook::CONTINUE;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $router = $request->getRouter();
        $target = $this->describeTarget($router, $request);

        if (in_array($method, self::SAFE_METHODS, true)) {
            if (in_array($target, self::BLOCKED_READS, true)) {
                $this->refuse($router, $request);
            }
            return Hook::CONTINUE;
        }

        if (in_array($target, self::ALLOWED_WRITES, true)) {
            return Hook::CONTINUE;
        }

        $this->refuse($router, $request);
        return Hook::ABORT; // not reached
    }

    /**
     * "page/op", "component/op" or "api/<entity>" for the current request.
     */
    protected function describeTarget($router, PKPRequest $request): string
    {
        if ($router instanceof APIRouter) {
            return 'api/' . $router->getEntity();
        }
        if ($router instanceof PKPComponentRouter) {
            return $router->getRequestedComponent($request) . '/' . $router->getRequestedOp($request);
        }
        if ($router instanceof PKPPageRouter) {
            return $router->getRequestedPage($request) . '/' . $router->getRequestedOp($request);
        }
        return '';
    }

    /**
     * Users listed in [demo] superusers may write, unless they are only
     * impersonated through "Login as" by somebody else.
     */
    public function isPrivileged(PKPRequest $request): bool
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }

        $superUsers = $this->getSuperUsers();
        if (!in_array(strtolower($user->getUsername()), $superUsers, true)) {
            return false;
        }

        $originalUserId = Validation::loggedInAs();
        if ($originalUserId && $originalUserId !== $user->getId()) {
            $originalUser = Repo::user()->get($originalUserId, true);
            return $originalUser && in_array(strtolower($originalUser->getUsername()), $superUsers, true);
        }

        return true;
    }

    protected function getSuperUsers(): array
    {
        $value = (string) Config::getVar('demo', 'superusers', '');
        return array_values(array_filter(array_map(fn ($u) => strtolower(trim($u)), explode(',', $value))));
    }

    /**
     * Send a response appropriate for the kind of request and stop.
     */
    protected function refuse($router, PKPRequest $request): never
    {
        $message = __('plugins.generic.demoMode.refused');

        if (!headers_sent()) {
            header('X-Demo-Mode: read-only');
            header('Cache-Control: no-store');
        }

        // REST API (Vue forms show "errorMessage")
        if ($router instanceof APIRouter) {
            $this->sendJson(403, ['error' => 'plugins.generic.demoMode.refused', 'errorMessage' => $message]);
        }

        // Legacy AJAX handlers (grids, modals): JSONMessage with status=false
        // makes the client display the message.
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($router instanceof PKPComponentRouter || $isAjax) {
            $this->sendJson(200, ['status' => false, 'content' => $message, 'elementId' => '0', 'events' => null]);
        }

        // Regular page request (form post or link)
        $this->sendPage($request, $message);
    }

    protected function sendJson(int $status, array $data): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function sendPage(PKPRequest $request, string $message): never
    {
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        $host = parse_url($request->getBaseUrl(), PHP_URL_HOST);
        if (!$back || parse_url($back, PHP_URL_HOST) !== $host) {
            $back = $request->getBaseUrl();
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }
        $title = htmlspecialchars(__('plugins.generic.demoMode.title'));
        $text = htmlspecialchars($message);
        $backLabel = htmlspecialchars(__('plugins.generic.demoMode.back'));
        $backUrl = htmlspecialchars($back);
        $lang = htmlspecialchars(str_replace('_', '-', \PKP\facades\Locale::getLocale()));
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{$title}</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fbfaf7;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1c1c1e}
.box{max-width:30rem;margin:1rem;padding:2rem 2.25rem;background:#fff;border:1px solid #e5e1d8;border-top:4px solid #8c1c2c;border-radius:6px;box-shadow:0 4px 16px rgba(20,20,30,.06)}
h1{margin:0 0 .75rem;font:600 1.4rem/1.3 Georgia,serif;color:#14213d}a{display:inline-block;margin-top:1rem;padding:.6rem 1.1rem;background:#14213d;color:#fff;border-radius:4px;text-decoration:none}</style>
</head><body><div class="box"><h1>{$title}</h1><p>{$text}</p><a href="{$backUrl}">&larr; {$backLabel}</a></div></body></html>
HTML;
        exit;
    }

    // ---------------------------------------------------------------------
    // Banner & login helper
    // ---------------------------------------------------------------------

    public function addAssets(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        static $added = false;
        if ($added) {
            return Hook::CONTINUE;
        }
        $added = true;

        $request = Application::get()->getRequest();
        $privileged = $this->isPrivileged($request);
        $resetHours = (int) Config::getVar('demo', 'reset_hours', 0);

        $accounts = [];
        $roleLabels = [
            'demo' => __('plugins.generic.demoMode.account.admin'),
            'demo-editor' => __('plugins.generic.demoMode.account.editor'),
            'demo-reviewer' => __('plugins.generic.demoMode.account.reviewer'),
            'demo-author' => __('plugins.generic.demoMode.account.author'),
        ];
        $accountList = (string) Config::getVar('demo', 'accounts', 'demo,demo-editor,demo-reviewer,demo-author');
        foreach (array_filter(array_map('trim', explode(',', $accountList))) as $username) {
            $accounts[] = ['username' => $username, 'label' => $roleLabels[$username] ?? $username];
        }

        $config = [
            'privileged' => $privileged,
            'loggedIn' => (bool) $request->getUser(),
            'banner' => $privileged
                ? __('plugins.generic.demoMode.banner.superuser')
                : __('plugins.generic.demoMode.banner') . ($resetHours ? ' ' . __('plugins.generic.demoMode.banner.reset', ['hours' => $resetHours]) : ''),
            'loginTitle' => __('plugins.generic.demoMode.login.title'),
            'loginHint' => __('plugins.generic.demoMode.login.hint'),
            'password' => (string) Config::getVar('demo', 'password', ''),
            'accounts' => $accounts,
        ];

        $baseUrl = $request->getBaseUrl() . '/' . $this->getPluginPath();
        $contexts = ['frontend', 'backend'];
        $templateMgr->addJavaScript('demoModeConfig', 'window.ojsDemoMode = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';', ['inline' => true, 'contexts' => $contexts]);
        $templateMgr->addJavaScript('demoMode', $baseUrl . '/js/demoMode.js', ['contexts' => $contexts]);
        $templateMgr->addStyleSheet('demoMode', $baseUrl . '/css/demoMode.css', ['contexts' => $contexts]);
        $templateMgr->addHeader('demoModeRobots', '<meta name="robots" content="noindex, nofollow">', ['contexts' => $contexts]);

        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.demoMode.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.demoMode.description');
    }
}
