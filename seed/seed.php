<?php

/**
 * @file seed/seed.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Fills an OJS 3.5 installation with demo content: a bilingual journal
 *  with settings, sections, editorial team, demo login accounts, published
 *  issues with PDF galleys, submissions in the editorial workflow (incl. a
 *  pending review for the demo reviewer), announcements and usage statistics.
 *
 * Usage:
 *   php seed/seed.php /path/to/ojs [--journal=PATH] [--content=seed/content/journal.json]
 *        [--demo-password=demo] [--no-metrics] [--force]
 *
 * --journal=PATH  journal (URL path) to fill; default: the path from journal.json.
 *                 An existing journal is reused: its settings are replaced by the
 *                 demo settings and the demo issues, submissions and
 *                 announcements are added (items that already exist are skipped,
 *                 so the script can be re-run).
 * --force         delete the journal first and create it from scratch.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$ojsRoot = isset($argv[1]) ? realpath($argv[1]) : false;
if (!$ojsRoot || !is_file($ojsRoot . '/tools/bootstrap.php')) {
    fwrite(STDERR, "Usage: php seed.php /path/to/ojs [--content=FILE] [--demo-password=PASS] [--no-metrics] [--force]\n");
    exit(1);
}

$seedDir = __DIR__;
$options = [
    'content' => $seedDir . '/content/journal.json',
    'demo-password' => 'demo',
    'no-metrics' => false,
    'force' => false,
    'journal' => null,
];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

// OJS's CLI bootstrap reads $argv; hand it only the script name.
$argv = [$argv[0]];
chdir($ojsRoot);
require $ojsRoot . '/tools/bootstrap.php';

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\announcement\Announcement;
use PKP\cliTool\CommandLineTool;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submissionFile\SubmissionFile;

class DemoSeedTool extends CommandLineTool
{
    public const LOCALES = ['ru', 'en'];

    /** Demo login accounts shown to prospects. */
    public const DEMO_ACCOUNTS = [
        ['username' => 'demo', 'given' => ['ru' => 'Демо', 'en' => 'Demo'], 'family' => ['ru' => 'Администратор', 'en' => 'Administrator'], 'roles' => ['site-admin', 'manager', 'editor-in-chief']],
        ['username' => 'demo-editor', 'given' => ['ru' => 'Демо', 'en' => 'Demo'], 'family' => ['ru' => 'Редактор', 'en' => 'Editor'], 'roles' => ['editor-in-chief']],
        ['username' => 'demo-reviewer', 'given' => ['ru' => 'Демо', 'en' => 'Demo'], 'family' => ['ru' => 'Рецензент', 'en' => 'Reviewer'], 'roles' => ['reviewer']],
        ['username' => 'demo-author', 'given' => ['ru' => 'Демо', 'en' => 'Demo'], 'family' => ['ru' => 'Автор', 'en' => 'Author'], 'roles' => ['author']],
    ];

    /** Role keys used in journal.json mapped to [roleId, default group locale key]. */
    public const ROLE_GROUPS = [
        'manager' => [Role::ROLE_ID_MANAGER, 'default.groups.name.manager'],
        'editor-in-chief' => [Role::ROLE_ID_MANAGER, 'default.groups.name.editor'],
        'section-editor' => [Role::ROLE_ID_SUB_EDITOR, 'default.groups.name.sectionEditor'],
        'reviewer' => [Role::ROLE_ID_REVIEWER, 'default.groups.name.externalReviewer'],
        'author' => [Role::ROLE_ID_AUTHOR, 'default.groups.name.author'],
        'copyeditor' => [Role::ROLE_ID_ASSISTANT, 'default.groups.name.copyeditor'],
        'layout-editor' => [Role::ROLE_ID_ASSISTANT, 'default.groups.name.layoutEditor'],
    ];

    protected array $options;
    protected array $content;
    protected string $assetsDir;
    protected string $workDir;
    protected Journal $journal;
    protected array $userIds = [];
    protected string $adminUsername;

    public function __construct(array $argv, array $options)
    {
        parent::__construct($argv);
        $this->options = $options;
    }

    public function execute(): void
    {
        $contentFile = $this->options['content'];
        $this->content = json_decode(file_get_contents($contentFile), true, 512, JSON_THROW_ON_ERROR);
        if (!empty($this->options['journal']) && is_string($this->options['journal'])) {
            $this->content['journal']['path'] = $this->options['journal'];
        }
        $this->assetsDir = dirname($contentFile, 2) . '/assets';
        $this->workDir = sys_get_temp_dir() . '/ojs-demo-seed-' . getmypid();
        if (!is_dir($this->workDir)) {
            mkdir($this->workDir, 0700, true);
        }

        $admin = Repo::user()->getAdminUsers()->first();
        if (!$admin) {
            throw new RuntimeException('No site administrator found.');
        }
        $this->adminUsername = $admin->getUsername();

        $this->step('Locales', fn () => $this->ensureLocales());
        $this->step('Journal', fn () => $this->createJournal());
        $this->step('Users', fn () => $this->createUsers());
        $this->step('Plugins & theme', fn () => $this->configurePlugins());
        $this->step('Branding', fn () => $this->applyBranding());
        $this->step('Issues & articles', fn () => $this->importIssues());
        $this->step('Workflow submissions', fn () => $this->importSubmissions());
        $this->step('Announcements', fn () => $this->createAnnouncements());
        if (!$this->options['no-metrics']) {
            $this->step('Usage statistics', fn () => $this->generateMetrics());
        }
        $this->step('Search index', fn () => $this->rebuildSearchIndex());

        $this->cleanup();
        $this->line('');
        $this->line('Done. Journal: ' . Application::get()->getRequest()->getBaseUrl() . '/index.php/' . $this->journal->getPath());
        $this->line('Demo accounts (password: ' . $this->options['demo-password'] . '): ' . join(', ', array_column(self::DEMO_ACCOUNTS, 'username')));
    }

    // -----------------------------------------------------------------------
    // Steps
    // -----------------------------------------------------------------------

    protected function ensureLocales(): void
    {
        $siteDao = DAORegistry::getDAO('SiteDAO'); /** @var \PKP\site\SiteDAO $siteDao */
        $site = $siteDao->getSite();
        $installed = $site->getInstalledLocales();
        $supported = $site->getSupportedLocales();
        foreach ($this->uiLocales() as $locale) {
            if (!in_array($locale, $installed)) {
                Locale::installLocale($locale);
                $installed[] = $locale;
                $this->line("  installed locale {$locale}");
            }
            if (!in_array($locale, $supported)) {
                $supported[] = $locale;
            }
        }
        $site->setInstalledLocales(array_values(array_unique($installed)));
        $site->setSupportedLocales(array_values(array_unique($supported)));
        $site->setData('redirectContextId', $site->getData('redirectContextId'));
        $siteDao->updateObject($site);
    }

    protected function createJournal(): void
    {
        $data = $this->content['journal'];
        $contextService = app()->get('context');
        $contextDao = Application::getContextDAO();

        $existing = $contextDao->getByPath($data['path']);
        if ($existing && $this->options['force']) {
            $this->line("  deleting existing journal '{$data['path']}'");
            $contextService->delete($existing);
            $existing = null;
        }

        $props = [
            'urlPath' => $data['path'],
            'name' => $data['name'],
            'acronym' => $data['acronym'],
            'abbreviation' => $data['abbreviation'],
            'description' => $data['description'],
            'about' => $data['about'],
            'authorGuidelines' => $data['authorGuidelines'],
            'submissionChecklist' => $this->checklistHtml($data['submissionChecklist'] ?? []),
            'copyrightNotice' => $data['copyrightNotice'],
            'privacyStatement' => $data['privacyStatement'],
            'openAccessPolicy' => $data['openAccessPolicy'],
            'reviewGuidelines' => $data['reviewGuidelines'],
            'pageFooter' => $data['pageFooter'],
            'contactAffiliation' => $data['contactAffiliation'],
            'onlineIssn' => $data['onlineIssn'],
            'printIssn' => $data['printIssn'],
            'publisherInstitution' => $data['publisherInstitution'],
            'contactName' => $data['contactName'],
            'contactEmail' => $data['contactEmail'],
            'contactPhone' => $data['contactPhone'] ?? '',
            'mailingAddress' => $data['mailingAddress'] ?? '',
            'supportName' => $data['supportName'] ?? $data['contactName'],
            'supportEmail' => $data['supportEmail'] ?? $data['contactEmail'],
            'country' => 'RU',
            'primaryLocale' => 'ru',
            'supportedLocales' => $this->uiLocales(),
            // every interface language has its own texts; manuscripts are in Russian or English
            'supportedFormLocales' => $this->uiLocales(),
            'supportedSubmissionLocales' => self::LOCALES,
            'supportedSubmissionMetadataLocales' => $this->uiLocales(),
            'supportedDefaultSubmissionLocale' => 'ru',
            'enabled' => true,
            'enableAnnouncements' => true,
            'disableUserReg' => true,
            'numAnnouncementsHomepage' => 2,
            'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/',
            'copyrightHolderType' => 'author',
            'copyrightYearBasis' => 'issue',
            'publishingMode' => Journal::PUBLISHING_MODE_OPEN,
            'enableOai' => true,
            'itemsPerPage' => 25,
            'numPageLinks' => 10,
            'keywords' => 'request',
            'citations' => 'request',
            'defaultReviewMode' => ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS,
            'themePluginPath' => 'academicJournal',
        ];

        // Languages without a translation show the English texts
        foreach ($props as $key => $value) {
            if (is_array($value) && isset($value['en']) && !array_is_list($value)) {
                $props[$key] = $this->withFallbackLocales($value);
            }
        }

        $request = Application::get()->getRequest();
        if ($existing) {
            unset($props['urlPath']);
            // Leftovers of a previous design would override the demo look:
            // header logo, homepage image, custom stylesheet, extra homepage
            // content and custom <head> tags.
            $leftovers = ['pageHeaderLogoImage', 'homepageImage', 'styleSheet', 'additionalHomeContent', 'customHeaders', 'journalThumbnail'];
            $cleared = DB::table('journal_settings')
                ->where('journal_id', $existing->getId())
                ->whereIn('setting_name', $leftovers)
                ->delete();
            if ($cleared) {
                $this->line("  cleared {$cleared} leftover appearance setting(s) (logo, homepage image, stylesheet…)");
                $existing = $contextDao->getById($existing->getId());
            }
            $this->journal = $contextService->edit($existing, $props, $request);
            $this->line('  using existing journal #' . $this->journal->getId() . ' (' . $this->journal->getPath() . '), settings updated');
        } else {
            $journal = $contextDao->newDataObject();
            $journal->setAllData($props);
            $this->journal = $contextService->add($journal, $request);
            $this->line('  created journal #' . $this->journal->getId() . ' (' . $this->journal->getPath() . ')');
        }

        // Sections: matched by abbreviation (any locale); a fresh journal's
        // default "Articles" section is re-used as the first one.
        $sections = Repo::section()->getCollector()->filterByContextIds([$this->journal->getId()])->getMany()->values()->all();
        $hasContent = Repo::submission()->getCollector()->filterByContextIds([$this->journal->getId()])->getCount() > 0;
        foreach ($this->content['sections'] as $i => $sectionData) {
            $section = null;
            foreach ($sections as $candidate) {
                $abbrevs = array_map('mb_strtolower', array_filter((array) $candidate->getData('abbrev')));
                if (array_intersect($abbrevs, array_map('mb_strtolower', array_values($sectionData['abbrev'])))) {
                    $section = $candidate;
                    break;
                }
            }
            if (!$section && $i === 0 && !$hasContent && count($sections) === 1) {
                $section = $sections[0];
            }
            $section ??= Repo::section()->newDataObject(['contextId' => $this->journal->getId()]);
            $section->setData('title', $this->withFallbackLocales($sectionData['title']));
            $section->setData('abbrev', $this->withFallbackLocales($sectionData['abbrev']));
            $section->setData('policy', isset($sectionData['policy']) ? $this->withFallbackLocales($sectionData['policy']) : null);
            $section->setData('sequence', $i);
            $section->setData('metaIndexed', true);
            $section->setData('metaReviewed', true);
            $section->setData('abstractsNotRequired', false);
            $section->setData('editorRestricted', false);
            $section->setData('hideTitle', false);
            $section->setData('hideAuthor', false);
            $section->setData('contextId', $this->journal->getId());
            if ($section->getId()) {
                Repo::section()->edit($section, []);
            } else {
                Repo::section()->add($section);
            }
        }
    }

    protected function createUsers(): void
    {
        $password = (string) $this->options['demo-password'];

        foreach (self::DEMO_ACCOUNTS as $account) {
            $this->userIds[$account['username']] = $this->createUser([
                'username' => $account['username'],
                'email' => $account['username'] . '@example.org',
                'givenName' => $account['given'],
                'familyName' => $account['family'],
                'affiliation' => ['ru' => 'Демонстрационная учётная запись', 'en' => 'Demo account'],
                'country' => 'RU',
                'roles' => $account['roles'],
            ], $password);
        }

        foreach ($this->content['users'] as $userData) {
            // Editorial team members do not need to sign in: random password.
            $this->userIds[$userData['username']] = $this->createUser($userData, bin2hex(random_bytes(12)));
        }
        $this->line('  ' . count($this->userIds) . ' users');
    }

    protected function configurePlugins(): void
    {
        $contextId = $this->journal->getId();
        $enable = [
            'generic' => ['pdfjsviewerplugin', 'googlescholarplugin', 'dublincoremetaplugin', 'webfeedplugin', 'htmlarticlegalleyplugin', 'usageeventplugin', 'citationstylelanguageplugin', 'recommendbyauthorplugin', 'demomodeplugin'],
            'blocks' => ['makesubmissionblockplugin', 'informationblockplugin'],
        ];
        foreach ($enable as $category => $plugins) {
            PluginRegistry::loadCategory($category, false, $contextId);
            foreach ($plugins as $pluginName) {
                $plugin = PluginRegistry::getPlugin($category, $pluginName);
                if (!$plugin) {
                    $this->line("  (plugin {$category}/{$pluginName} not found, skipped)");
                    continue;
                }
                $plugin->updateSetting($contextId, 'enabled', true);
            }
        }

        // Block order in the sidebar
        $order = ['makesubmissionblockplugin', 'informationblockplugin', 'webfeedblockplugin'];
        foreach ($order as $seq => $pluginName) {
            $plugin = PluginRegistry::getPlugin('blocks', $pluginName);
            if ($plugin) {
                $plugin->updateSetting($contextId, 'seq', $seq);
                $plugin->updateSetting($contextId, 'context', 1); // sidebar
            }
        }
        $contextDao = Application::getContextDAO();
        $this->journal = $contextDao->getById($contextId);
        $this->journal->setData('sidebar', $order);
        $contextDao->updateObject($this->journal);

        // Theme: enable and set options
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /** @var \PKP\plugins\PluginSettingsDAO $pluginSettingsDao */
        $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'enabled', true, 'bool');
        $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'showHero', true, 'bool');
        $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'showJournalStats', true, 'bool');
        // Options inherited from the default theme
        $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'showDescriptionInJournalIndex', false, 'bool');
        $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'useHomepageImageAsHeader', false, 'bool');
        if (!empty($this->content['journal']['cta']['url'])) {
            // one URL for all languages, or {"ru": …, "en": …} per language
            $ctaUrl = $this->content['journal']['cta']['url'];
            $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'ctaUrl', is_array($ctaUrl) ? $ctaUrl : ['en' => $ctaUrl], 'object');
            $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'ctaLabel', $this->content['journal']['cta']['label'] ?? [], 'object');
        }
        if (!empty($this->content['journal']['kicker'])) {
            $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'heroKicker', $this->content['journal']['kicker'], 'object');
        }
        if (!empty($this->content['journal']['tagline'])) {
            $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', 'heroTagline', $this->content['journal']['tagline'], 'object');
        }

        // Do not print "(Author)" after every author name in tables of contents
        DB::table('user_groups')
            ->where('context_id', $contextId)
            ->where('role_id', Role::ROLE_ID_AUTHOR)
            ->update(['show_title' => 0]);

        // Make the journal the site's landing page
        $siteDao = DAORegistry::getDAO('SiteDAO');
        $site = $siteDao->getSite();
        $site->setData('redirectContextId', $contextId);
        $siteDao->updateObject($site);
    }

    /**
     * Corporate identity from journal.json "branding": theme colours and
     * fonts, header logo (per locale) and favicon.
     */
    protected function applyBranding(): void
    {
        $branding = $this->content['journal']['branding'] ?? null;
        if (!$branding) {
            return;
        }
        $contextId = $this->journal->getId();
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /** @var \PKP\plugins\PluginSettingsDAO $pluginSettingsDao */
        foreach (['primaryColour', 'accentColour', 'highlightColour', 'headingFont', 'logoPlacement'] as $option) {
            if (!empty($branding[$option])) {
                $pluginSettingsDao->updateSetting($contextId, 'academicjournalthemeplugin', $option, $branding[$option], 'string');
                $this->line("  theme {$option} = {$branding[$option]}");
            }
        }

        $publicFileManager = new \APP\file\PublicFileManager();
        $publicDir = Core::getBaseDir() . '/' . $publicFileManager->getContextFilesPath($contextId);
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        $install = function (string $relPath, string $setting, string $locale, string $altText = '') use ($publicDir): ?array {
            $src = $this->assetsDir . '/' . $relPath;
            if (!is_file($src)) {
                $this->line("  (missing {$relPath}, skipped)");
                return null;
            }
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $uploadName = "{$setting}_{$locale}.{$ext}";
            copy($src, $publicDir . '/' . $uploadName);
            [$width, $height] = getimagesize($src) ?: [0, 0];
            return [
                'name' => basename($src),
                'uploadName' => $uploadName,
                'width' => $width,
                'height' => $height,
                'dateUploaded' => Core::getCurrentDate(),
                'altText' => $altText,
            ];
        };

        $contextDao = Application::getContextDAO();
        $journal = $contextDao->getById($contextId);
        $logos = $this->withFallbackLocales($branding['logo'] ?? []);
        $altTexts = $this->withFallbackLocales($branding['logoAltText'] ?? []);
        foreach ($this->uiLocales() as $locale) {
            if (!empty($logos[$locale]) && ($value = $install($logos[$locale], 'pageHeaderLogoImage', $locale, $altTexts[$locale] ?? ''))) {
                $journal->setData('pageHeaderLogoImage', $value, $locale);
            }
            if (!empty($branding['favicon']) && ($value = $install($branding['favicon'], 'favicon', $locale))) {
                $journal->setData('favicon', $value, $locale);
            }
        }
        $contextDao->updateObject($journal);
        $this->journal = $contextDao->getById($contextId);
        $this->line('  logo and favicon installed');
    }

    protected function importIssues(): void
    {
        foreach ($this->content['issues'] as $issueData) {
            if ($existingIssue = $this->findIssue($issueData)) {
                if (!$this->issueIsOutdated($existingIssue, $issueData)) {
                    $this->refreshIssueAssets($existingIssue, $issueData);
                    $this->line('  Vol. ' . $issueData['volume'] . ' No. ' . $issueData['number'] . ' already exists: covers and PDFs refreshed');
                    continue;
                }
                // imported by an earlier version of the demo (fewer languages
                // or PDFs): replace it with a fresh import
                $this->deleteIssue($existingIssue);
                $this->line('  Vol. ' . $issueData['volume'] . ' No. ' . $issueData['number'] . ': outdated demo content removed');
            }
            $xml = $this->buildIssuesXml([$issueData]);
            $file = $this->workDir . '/issue-v' . $issueData['volume'] . 'n' . $issueData['number'] . '.xml';
            $xml->save($file);
            $this->nativeImport($file);
            $this->line('  imported Vol. ' . $issueData['volume'] . ' No. ' . $issueData['number'] . ' (' . count($issueData['articles']) . ' articles)');
        }

        // Mark the current issue
        $current = null;
        foreach ($this->content['issues'] as $issueData) {
            if (!empty($issueData['current'])) {
                $current = $issueData;
            }
        }
        $current ??= end($this->content['issues']);
        $issue = $this->findIssue($current);
        if ($issue) {
            Repo::issue()->updateCurrent($this->journal->getId(), $issue);
        }

        // Archive order: newest issue first
        $issues = Repo::issue()->getCollector()
            ->filterByContextIds([$this->journal->getId()])
            ->filterByPublished(true)
            ->getMany()
            ->sortByDesc(fn ($i) => sprintf('%04d-%04d-%04d', $i->getYear(), $i->getVolume(), (int) $i->getNumber()))
            ->values();
        DB::table('custom_issue_orders')->where('journal_id', $this->journal->getId())->delete();
        foreach ($issues as $seq => $i) {
            DB::table('custom_issue_orders')->insert(['issue_id' => $i->getId(), 'journal_id' => $this->journal->getId(), 'seq' => $seq + 1]);
        }
    }

    /**
     * Re-running the seed after the assets were rebuilt (new design, publisher
     * name…) replaces the cover images and the article PDFs of issues that
     * already exist, without touching their metadata.
     */
    protected function refreshIssueAssets(\APP\issue\Issue $issue, array $issueData): void
    {
        $publicFileManager = new \APP\file\PublicFileManager();
        $publicDir = Core::getBaseDir() . '/' . $publicFileManager->getContextFilesPath($this->journal->getId());
        $covers = $this->coverFiles($issueData);
        foreach ((array) $issue->getData('coverImage') as $locale => $name) {
            $src = $covers[$locale] ?? null;
            if ($src && is_string($name) && $name !== '' && basename($name) === $name && is_file($publicDir . '/' . $name)) {
                copy($src, $publicDir . '/' . $name);
            }
        }
        foreach ($issueData['articles'] as $articleData) {
            if ($submission = $this->findSubmission($articleData)) {
                $this->refreshSubmissionPdfs($submission, $articleData);
            }
        }
    }

    /** Replace the stored galley files (PDF, HTML, XML) of an existing demo article with the current assets (matched by language and format). */
    protected function refreshSubmissionPdfs(Submission $submission, array $data): void
    {
        $sources = [];
        foreach ($this->galleyFiles($data) as [$locale, $format, $path]) {
            $sources[$locale][$format] = $path;
        }
        $fileService = app()->get('file');
        $files = Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submission->getId()])->getMany();
        foreach ($files as $submissionFile) {
            $locale = $submissionFile->getData('locale') ?: ($data['locale'] ?? 'ru');
            $file = $fileService->get((int) $submissionFile->getData('fileId'));
            $src = $file ? ($sources[$locale][strtolower(pathinfo($file->path, PATHINFO_EXTENSION))] ?? null) : null;
            if ($src && $fileService->fs->fileExists($file->path)) {
                $fileService->fs->write($file->path, file_get_contents($src));
            }
        }
    }

    /**
     * True when an existing demo item lacks languages or PDFs that the
     * current content has (it was imported by an earlier version of the demo).
     */
    protected function submissionIsOutdated(Submission $submission, array $data): bool
    {
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return true;
        }
        $titles = array_filter((array) $publication->getData('title'));
        foreach ($this->contentLocales() as $locale) {
            if (!empty($data['title'][$locale]) && empty($titles[$locale])) {
                return true;
            }
        }
        if ($submission->getData('status') == Submission::STATUS_PUBLISHED) {
            // one galley per language and format (PDF, HTML, XML)
            $have = $want = [];
            foreach ($publication->getData('galleys') ?? [] as $galley) {
                $have[] = $galley->getData('locale');
            }
            foreach ($this->galleyFiles($data) as [$locale]) {
                $want[] = $locale;
            }
            sort($have);
            sort($want);
            if ($have !== $want) {
                return true;
            }
        }
        return false;
    }

    protected function issueIsOutdated(\APP\issue\Issue $issue, array $issueData): bool
    {
        $titles = array_filter((array) $issue->getData('title'));
        foreach ($this->contentLocales() as $locale) {
            if (!empty($issueData['title'][$locale]) && empty($titles[$locale])) {
                return true;
            }
        }
        foreach ($issueData['articles'] as $articleData) {
            $submission = $this->findSubmission($articleData);
            if (!$submission || $this->submissionIsOutdated($submission, $articleData)) {
                return true;
            }
        }
        return false;
    }

    /** Delete an issue together with its articles. */
    protected function deleteIssue(\APP\issue\Issue $issue): void
    {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$this->journal->getId()])
            ->filterByIssueIds([$issue->getId()])
            ->getMany();
        foreach ($submissions as $submission) {
            Repo::submission()->delete($submission);
        }
        Repo::issue()->delete($issue);
    }

    /** {locale: absolute path} of the article PDFs that exist ("pdf" may be a plain path). */
    protected function pdfFiles(array $data): array
    {
        $pdf = $data['pdf'] ?? [];
        $pdf = is_string($pdf) ? [($data['locale'] ?? 'ru') => $pdf] : $pdf;
        $out = [];
        foreach ($pdf as $locale => $rel) {
            if (in_array($locale, $this->uiLocales()) && is_file($this->assetsDir . '/' . $rel)) {
                $out[$locale] = $this->assetsDir . '/' . $rel;
            }
        }
        return $out;
    }

    /**
     * Galley files of a published article: [locale, format, absolute path],
     * PDFs first, then the HTML and JATS XML editions.
     */
    protected function galleyFiles(array $data): array
    {
        $out = [];
        foreach ($this->pdfFiles($data) as $locale => $path) {
            $out[] = [$locale, 'pdf', $path];
        }
        foreach (['html', 'xml'] as $format) {
            foreach ((array) ($data[$format] ?? []) as $locale => $rel) {
                if (in_array($locale, $this->uiLocales()) && is_file($this->assetsDir . '/' . $rel)) {
                    $out[] = [$locale, $format, $this->assetsDir . '/' . $rel];
                }
            }
        }
        return $out;
    }

    /** {locale: absolute path} of the issue cover images that exist. */
    protected function coverFiles(array $issueData): array
    {
        $cover = $issueData['cover'] ?? [];
        $cover = is_string($cover) ? array_fill_keys(self::LOCALES, $cover) : $cover;
        $out = [];
        foreach ($cover as $locale => $rel) {
            if (in_array($locale, $this->uiLocales()) && is_file($this->assetsDir . '/' . $rel)) {
                $out[$locale] = $this->assetsDir . '/' . $rel;
            }
        }
        return $out;
    }

    protected function findIssue(array $issueData): ?\APP\issue\Issue
    {
        return Repo::issue()->getCollector()
            ->filterByContextIds([$this->journal->getId()])
            ->filterByVolumes([(int) $issueData['volume']])
            ->filterByNumbers([(string) $issueData['number']])
            ->filterByYears([(int) $issueData['year']])
            ->getMany()
            ->first();
    }

    /** An existing demo article, matched by its Russian or English title. */
    protected function findSubmission(array $data): ?Submission
    {
        $id = DB::table('publication_settings as ps')
            ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
            ->join('submissions as s', 's.submission_id', '=', 'p.submission_id')
            ->where('s.context_id', $this->journal->getId())
            ->where('ps.setting_name', 'title')
            ->whereIn('ps.setting_value', array_values(array_intersect_key($data['title'], array_flip(self::LOCALES))))
            ->value('s.submission_id');
        return $id ? Repo::submission()->get((int) $id) : null;
    }

    protected function importSubmissions(): void
    {
        $editorId = $this->userIds['demo-editor'] ?? null;
        foreach ($this->content['submissions'] as $data) {
            if ($existing = $this->findSubmission($data)) {
                if (!$this->submissionIsOutdated($existing, $data)) {
                    $this->refreshSubmissionPdfs($existing, $data);
                    $this->line('  "' . $data['slug'] . '" already exists: PDF refreshed');
                    continue;
                }
                Repo::submission()->delete($existing);
                $this->line('  "' . $data['slug'] . '": outdated demo content removed');
            }
            $xml = $this->buildSubmissionXml($data);
            $file = $this->workDir . '/submission-' . $data['slug'] . '.xml';
            $xml->save($file);
            $ids = $this->nativeImport($file);
            $submissionId = $ids[0] ?? null;
            if (!$submissionId) {
                $this->line('  could not import ' . $data['slug']);
                continue;
            }

            DB::table('submissions')->where('submission_id', $submissionId)->update([
                'date_submitted' => $data['dateSubmitted'] . ' 10:00:00',
                'date_last_activity' => $data['dateSubmitted'] . ' 10:00:00',
            ]);

            // Submitter: the author account whose email matches, else demo-author
            $submitterId = $this->userIdByEmail($data['authors'][0]['email'] ?? '') ?? $this->userIds['demo-author'];
            $this->assignToStage($submissionId, 'author', $submitterId);
            if ($data['stage'] !== 'submission') {
                // both editor demo accounts see these under "Assigned to me"
                foreach (array_filter([$editorId, $this->userIds['demo'] ?? null]) as $id) {
                    $this->assignToStage($submissionId, 'editor-in-chief', $id);
                }
            }
            if (isset($this->userIds['demo-author']) && $submitterId !== $this->userIds['demo-author'] && $data['stage'] === 'editorial') {
                $this->assignToStage($submissionId, 'author', $this->userIds['demo-author']);
            }

            if ($data['stage'] === 'externalReview') {
                $this->createPendingReview($submissionId);
            }
            $this->line('  imported "' . $data['slug'] . '" (' . $data['stage'] . ')');
        }
    }

    protected function createAnnouncements(): void
    {
        foreach ($this->content['announcements'] as $data) {
            $texts = [
                'title' => $this->onlyContentLocales($data['title']),
                'descriptionShort' => $this->onlyContentLocales($data['descriptionShort']),
                'description' => $this->onlyContentLocales($data['description']),
            ];
            $existingId = DB::table('announcement_settings as s')
                ->join('announcements as a', 'a.announcement_id', '=', 's.announcement_id')
                ->where('a.assoc_id', $this->journal->getId())
                ->where('s.setting_name', 'title')
                ->whereIn('s.setting_value', array_values(array_intersect_key($data['title'], array_flip(self::LOCALES))))
                ->value('a.announcement_id');
            if ($existingId) {
                // add the texts of new languages
                Announcement::find($existingId)?->update($texts);
                continue;
            }
            $announcement = Announcement::create([
                'assocType' => Application::ASSOC_TYPE_JOURNAL,
                'assocId' => $this->journal->getId(),
            ] + $texts);
            DB::table('announcements')->where('announcement_id', $announcement->id)
                ->update(['date_posted' => $data['datePosted'] . ' 09:00:00']);
        }
        $this->line('  ' . count($this->content['announcements']) . ' announcements');
    }

    /**
     * Plausible daily abstract views / galley downloads for the last 18 months.
     */
    protected function generateMetrics(): void
    {
        $contextId = $this->journal->getId();
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany();

        foreach (['metrics_submission', 'metrics_context'] as $table) {
            DB::table($table)->where('context_id', $contextId)->where('load_id', 'demo_seed')->delete();
        }
        DB::table('metrics_submission_geo_monthly')->where('context_id', $contextId)->where('country', 'RU')->where('region', '')->delete();

        $rows = [];
        $total = 0;
        $end = new DateTimeImmutable('yesterday');
        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            $published = new DateTimeImmutable($publication->getData('datePublished') ?: '-12 months');
            $popularity = random_int(40, 140) / 100;
            // downloads: most readers use the Russian and English PDFs
            $galleyFiles = [];
            foreach ($publication->getData('galleys') as $galley) {
                if ($galley->getData('submissionFileId')) {
                    $label = strtoupper((string) $galley->getLabel());
                    [$fileType, $weight] = str_starts_with($label, 'HTML') ? [1, 0.35] : (str_starts_with($label, 'XML') ? [3, 0.05] : [2, 1.0]);
                    $weight *= ['ru' => 0.55, 'en' => 0.3][$galley->getData('locale')] ?? 0.02;
                    $galleyFiles[] = [$galley->getId(), $galley->getData('submissionFileId'), $fileType, $weight];
                }
            }
            for ($day = $published; $day <= $end; $day = $day->modify('+1 day')) {
                $age = max(1, (int) $published->diff($day)->days);
                // views decay after publication, with weekday seasonality
                $base = (12 / sqrt($age / 7 + 1) + 1.2) * $popularity * ((int) $day->format('N') >= 6 ? 0.55 : 1);
                $views = $this->poisson($base);
                if ($views > 0) {
                    $rows['metrics_submission'][] = [
                        'load_id' => 'demo_seed', 'context_id' => $contextId, 'submission_id' => $submission->getId(),
                        'representation_id' => null, 'submission_file_id' => null, 'file_type' => null,
                        'assoc_type' => Application::ASSOC_TYPE_SUBMISSION, 'date' => $day->format('Y-m-d'), 'metric' => $views,
                    ];
                    $total += $views;
                }
                foreach ($galleyFiles as [$galleyId, $fileId, $fileType, $weight]) {
                    $downloads = $this->poisson($base * 0.5 * $weight);
                    if ($downloads > 0) {
                        $rows['metrics_submission'][] = [
                            'load_id' => 'demo_seed', 'context_id' => $contextId, 'submission_id' => $submission->getId(),
                            'representation_id' => $galleyId, 'submission_file_id' => $fileId, 'file_type' => $fileType, // 1 HTML, 2 PDF, 3 other
                            'assoc_type' => Application::ASSOC_TYPE_SUBMISSION_FILE, 'date' => $day->format('Y-m-d'), 'metric' => $downloads,
                        ];
                    }
                }
            }
        }

        // Journal homepage / issue views
        for ($day = $end->modify('-18 months'); $day <= $end; $day = $day->modify('+1 day')) {
            $rows['metrics_context'][] = [
                'load_id' => 'demo_seed', 'context_id' => $contextId, 'date' => $day->format('Y-m-d'),
                'metric' => $this->poisson(((int) $day->format('N') >= 6 ? 18 : 35)),
            ];
        }

        foreach ($rows as $table => $tableRows) {
            foreach (array_chunk($tableRows, 1000) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        // Monthly aggregates used by the statistics UI (computed here to stay
        // independent of the database engine)
        $monthly = [];
        foreach ($rows['metrics_submission'] ?? [] as $row) {
            if ($row['assoc_type'] !== Application::ASSOC_TYPE_SUBMISSION) {
                continue;
            }
            $key = $row['submission_id'] . '-' . substr(str_replace('-', '', $row['date']), 0, 6);
            $monthly[$key] ??= [
                'context_id' => $contextId, 'submission_id' => $row['submission_id'], 'country' => 'RU', 'region' => '', 'city' => '',
                'month' => (int) substr(str_replace('-', '', $row['date']), 0, 6), 'metric' => 0, 'metric_unique' => 0,
            ];
            $monthly[$key]['metric'] += $row['metric'];
            $monthly[$key]['metric_unique'] += $row['metric'];
        }
        foreach (array_chunk(array_values($monthly), 1000) as $chunk) {
            DB::table('metrics_submission_geo_monthly')->insert($chunk);
        }

        $this->line('  ' . number_format($total) . ' abstract views');
    }

    protected function rebuildSearchIndex(): void
    {
        $articleSearchIndex = Application::getSubmissionSearchIndex();
        $articleSearchIndex->rebuildIndex(false, $this->journal);
    }

    // -----------------------------------------------------------------------
    // Users & assignments
    // -----------------------------------------------------------------------

    protected function createUser(array $data, string $password): int
    {
        if ($existing = Repo::user()->getByUsername($data['username'], true)) {
            $userId = $existing->getId();
            // texts in languages added since the last run
            Repo::user()->edit($existing, array_filter([
                'givenName' => $data['givenName'],
                'familyName' => $data['familyName'],
                'affiliation' => $data['affiliation'] ?? null,
                'biography' => $data['biography'] ?? null,
            ]));
        } else {
            $user = Repo::user()->newDataObject();
            $user->setUsername($data['username']);
            $user->setEmail($data['email']);
            $user->setPassword(Validation::encryptCredentials($data['username'], $password));
            $user->setData('givenName', $data['givenName']);
            $user->setData('familyName', $data['familyName']);
            $user->setData('affiliation', $data['affiliation'] ?? null);
            $user->setData('biography', $data['biography'] ?? null);
            $user->setCountry($data['country'] ?? 'RU');
            $user->setDateRegistered(Core::getCurrentDate());
            $user->setDateValidated(Core::getCurrentDate());
            $user->setMustChangePassword(false);
            $user->setDisabled(false);
            $user->setData('locales', self::LOCALES);
            $userId = Repo::user()->add($user);
        }

        foreach ($data['roles'] as $roleKey) {
            if ($roleKey === 'site-admin') {
                $adminGroupId = DB::table('user_groups')->whereNull('context_id')->where('role_id', Role::ROLE_ID_SITE_ADMIN)->value('user_group_id');
                $adminGroup = $adminGroupId ? Repo::userGroup()->get((int) $adminGroupId) : null;
                if ($adminGroup && !Repo::userGroup()->userInGroup($userId, $adminGroup->id)) {
                    Repo::userGroup()->assignUserToGroup($userId, $adminGroup->id);
                }
                continue;
            }
            $group = $this->userGroup($roleKey);
            if ($group && !Repo::userGroup()->userInGroup($userId, $group->id)) {
                Repo::userGroup()->assignUserToGroup($userId, $group->id, null, null, (bool) $group->masthead);
            }
        }

        return $userId;
    }

    protected function userGroup(string $roleKey): ?\PKP\userGroup\UserGroup
    {
        [$roleId, $localeKey] = self::ROLE_GROUPS[$roleKey] ?? [null, null];
        if (!$roleId) {
            return null;
        }
        $groups = Repo::userGroup()->getByRoleIds([$roleId], $this->journal->getId());
        $groupId = DB::table('user_group_settings')
            ->whereIn('user_group_id', $groups->map(fn ($g) => $g->id)->all() ?: [0])
            ->where('setting_name', 'nameLocaleKey')
            ->where('setting_value', $localeKey)
            ->value('user_group_id');
        return $groupId ? Repo::userGroup()->get((int) $groupId) : $groups->first();
    }

    protected function userIdByEmail(string $email): ?int
    {
        if (!$email) {
            return null;
        }
        $user = Repo::user()->getByEmail($email, true);
        return $user ? $user->getId() : null;
    }

    protected function assignToStage(int $submissionId, string $roleKey, int $userId): void
    {
        $group = $this->userGroup($roleKey);
        if (!$group) {
            return;
        }
        Repo::stageAssignment()->build($submissionId, $group->id, $userId, false, true);
    }

    protected function createPendingReview(int $submissionId): void
    {
        $reviewerId = $this->userIds['demo-reviewer'] ?? null;
        if (!$reviewerId) {
            return;
        }
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var \PKP\submission\reviewRound\ReviewRoundDAO $reviewRoundDao */
        $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW)
            ?? $reviewRoundDao->build($submissionId, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW, 1, ReviewRound::REVIEW_ROUND_STATUS_PENDING_REVIEWS);

        // Copy the manuscript into the review round
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])
            ->getMany();
        $reviewFileIds = [];
        foreach ($files as $file) {
            $reviewFileIds[] = Repo::submissionFile()->copy($file, SubmissionFile::SUBMISSION_FILE_REVIEW_FILE, $reviewRound->getId());
        }

        $now = new DateTimeImmutable();
        $reviewAssignment = Repo::reviewAssignment()->newDataObject([
            'submissionId' => $submissionId,
            'reviewerId' => $reviewerId,
            'reviewRoundId' => $reviewRound->getId(),
            'stageId' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
            'round' => $reviewRound->getRound(),
            'reviewMethod' => ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS,
            'dateAssigned' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
            'dateNotified' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
            'dateResponseDue' => $now->modify('+4 days')->format('Y-m-d H:i:s'),
            'dateDue' => $now->modify('+25 days')->format('Y-m-d H:i:s'),
        ]);
        $reviewAssignmentId = Repo::reviewAssignment()->add($reviewAssignment);

        $reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO'); /** @var \PKP\submission\ReviewFilesDAO $reviewFilesDao */
        foreach ($reviewFileIds as $fileId) {
            $reviewFilesDao->grant($reviewAssignmentId, $fileId);
        }

        $reviewRound->setStatus(ReviewRound::REVIEW_ROUND_STATUS_PENDING_REVIEWS);
        $reviewRoundDao->updateObject($reviewRound);

        (new \APP\notification\NotificationManager())->createNotification(
            $reviewerId,
            \PKP\notification\Notification::NOTIFICATION_TYPE_REVIEW_ASSIGNMENT,
            $this->journal->getId(),
            Application::ASSOC_TYPE_REVIEW_ASSIGNMENT,
            $reviewAssignmentId,
            \PKP\notification\Notification::NOTIFICATION_LEVEL_TASK
        );
    }

    // -----------------------------------------------------------------------
    // Native XML
    // -----------------------------------------------------------------------

    protected function nativeImport(string $file): array
    {
        PluginRegistry::loadCategory('importexport', true);
        $plugin = PluginRegistry::getPlugin('importexport', 'NativeImportExportPlugin');
        $args = ['import', $file, $this->journal->getPath(), $this->adminUsername];
        ob_start();
        $plugin->executeCLI('seed.php', $args);
        $output = ob_get_clean();

        // Success is judged by what actually landed in the database (the CLI
        // messages are localized, and a failed import is rolled back).
        $deployment = $plugin->getDeployment();
        $ids = $deployment ? array_values(array_filter((array) $deployment->getProcessedObjectsIds(Application::ASSOC_TYPE_SUBMISSION))) : [];
        $imported = array_filter($ids, fn ($id) => (bool) Repo::submission()->get((int) $id));
        if (!$imported) {
            fwrite(STDERR, $output . "\n");
            throw new RuntimeException('Native import failed for ' . basename($file));
        }
        return array_values($imported);
    }

    protected function newDocument(string $root): array
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $rootNode = $doc->createElementNS('http://pkp.sfu.ca', $root);
        $rootNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', 'http://pkp.sfu.ca native.xsd');
        $doc->appendChild($rootNode);
        return [$doc, $rootNode];
    }

    protected function el(DOMDocument $doc, DOMElement $parent, string $name, ?string $value = null, array $attrs = []): DOMElement
    {
        $node = $doc->createElementNS('http://pkp.sfu.ca', $name);
        if ($value !== null) {
            $node->appendChild($doc->createTextNode($value));
        }
        foreach ($attrs as $k => $v) {
            $node->setAttribute($k, (string) $v);
        }
        $parent->appendChild($node);
        return $node;
    }

    /**
     * Controlled vocabulary entry (keyword, agency…). OJS 3.5.0-4 changed the
     * native schema from <keyword>text</keyword> to
     * <keyword><name>text</name></keyword> (also used inside <agencies>).
     */
    protected function vocabEntry(DOMDocument $doc, DOMElement $parent, string $legacyName, string $value): void
    {
        static $structured = null;
        $structured ??= str_contains(
            (string) @file_get_contents(Core::getBaseDir() . '/lib/pkp/plugins/importexport/native/pkp-native.xsd'),
            'ControlledVocabEntryType'
        );
        if ($structured) {
            $entry = $this->el($doc, $parent, 'keyword');
            $this->el($doc, $entry, 'name', $value);
        } else {
            $this->el($doc, $parent, $legacyName, $value);
        }
    }

    /** Append one element per locale for a {"ru": ..., "en": ...} value. */
    protected function localized(DOMDocument $doc, DOMElement $parent, string $name, ?array $values): void
    {
        foreach ($this->contentLocales() as $locale) {
            if (isset($values[$locale]) && $values[$locale] !== '') {
                $this->el($doc, $parent, $name, $values[$locale], ['locale' => $locale]);
            }
        }
    }

    protected function buildIssuesXml(array $issues): DOMDocument
    {
        [$doc, $root] = $this->newDocument('issues');
        foreach ($issues as $issueData) {
            $issue = $this->el($doc, $root, 'issue', null, [
                'published' => 1,
                'current' => empty($issueData['current']) ? 0 : 1,
                'access_status' => 1,
                'url_path' => '',
            ]);
            $this->localized($doc, $issue, 'description', $issueData['description'] ?? null);
            $ident = $this->el($doc, $issue, 'issue_identification');
            $this->el($doc, $ident, 'volume', (string) $issueData['volume']);
            $this->el($doc, $ident, 'number', (string) $issueData['number']);
            $this->el($doc, $ident, 'year', (string) $issueData['year']);
            $this->localized($doc, $ident, 'title', $issueData['title'] ?? null);
            $this->el($doc, $issue, 'date_published', $issueData['datePublished']);
            $this->el($doc, $issue, 'last_modified', $issueData['datePublished']);

            $sections = $this->el($doc, $issue, 'sections');
            foreach ($this->content['sections'] as $seq => $sectionData) {
                $section = $this->el($doc, $sections, 'section', null, [
                    'ref' => $sectionData['ref'], 'seq' => $seq, 'editor_restricted' => 0, 'meta_indexed' => 1,
                    'meta_reviewed' => 1, 'abstracts_not_required' => 0, 'hide_title' => 0, 'hide_author' => 0,
                    'abstract_word_count' => 0,
                ]);
                $this->localized($doc, $section, 'abbrev', $sectionData['abbrev']);
                $this->localized($doc, $section, 'policy', $sectionData['policy'] ?? null);
                $this->localized($doc, $section, 'title', $sectionData['title']);
            }

            if ($coverFiles = $this->coverFiles($issueData)) {
                $covers = $this->el($doc, $issue, 'covers');
                foreach ($coverFiles as $locale => $src) {
                    $cover = $this->el($doc, $covers, 'cover', null, ['locale' => $locale]);
                    $this->el($doc, $cover, 'cover_image', basename($src));
                    $this->el($doc, $cover, 'cover_image_alt_text', $issueData['title'][$locale] ?? $issueData['title']['en'] ?? '');
                    $this->el($doc, $cover, 'embed', base64_encode(file_get_contents($src)), ['encoding' => 'base64']);
                }
            }

            $articles = $this->el($doc, $issue, 'articles');
            foreach ($issueData['articles'] as $seq => $articleData) {
                $this->buildArticle($doc, $articles, $articleData, $issueData, $seq);
            }
        }
        return $doc;
    }

    protected function buildSubmissionXml(array $data): DOMDocument
    {
        [$doc, $root] = $this->newDocument('articles');
        $this->buildArticle($doc, $root, $data, null, 0);
        return $doc;
    }

    protected function buildArticle(DOMDocument $doc, DOMElement $parent, array $data, ?array $issueData, int $seq): void
    {
        static $fileId = 0;
        static $authorId = 0;
        $published = $issueData !== null;
        $locale = $data['locale'] ?? 'ru';

        $article = $this->el($doc, $parent, 'article', null, [
            'locale' => $locale,
            'date_submitted' => $data['dateSubmitted'],
            'status' => $published ? Submission::STATUS_PUBLISHED : Submission::STATUS_QUEUED,
            'submission_progress' => '',
            'current_publication_id' => 1,
            'stage' => $published ? 'production' : $data['stage'],
        ]);
        $this->el($doc, $article, 'id', '1', ['type' => 'internal', 'advice' => 'ignore']);

        // Galley files: PDF, HTML and JATS XML per language for published
        // articles; the manuscript PDF in its own language for submissions
        // in the workflow
        $galleyFiles = $published
            ? $this->galleyFiles($data)
            : array_map(fn ($path) => [$locale, 'pdf', $path], array_intersect_key($this->pdfFiles($data), [$locale => true]));
        $mimeTypes = ['pdf' => 'application/pdf', 'html' => 'text/html', 'xml' => 'text/xml'];
        $fileIds = [];
        foreach ($galleyFiles as [$fileLocale, $format, $pdfPath]) {
            $thisFileId = ++$fileId;
            $fileIds[] = [$fileLocale, $format, $thisFileId];
            $pdf = file_get_contents($pdfPath);
            $submissionFile = $this->el($doc, $article, 'submission_file', null, [
                'id' => $thisFileId,
                'file_id' => $thisFileId,
                'stage' => $published ? 'proof' : 'submission',
                'genre' => $this->genreName(),
                'uploader' => $this->adminUsername,
                'viewable' => 'false',
                'created_at' => $data['dateSubmitted'],
                'updated_at' => $data['dateSubmitted'],
            ]);
            $this->el($doc, $submissionFile, 'name', basename($pdfPath), ['locale' => $fileLocale]);
            $file = $this->el($doc, $submissionFile, 'file', null, ['id' => $thisFileId, 'filesize' => strlen($pdf), 'extension' => $format]);
            $this->el($doc, $file, 'embed', base64_encode($pdf), ['encoding' => 'base64', 'mime_type' => $mimeTypes[$format]]);
        }

        $publicationAttrs = [
            'version' => 1,
            'status' => $published ? Submission::STATUS_PUBLISHED : Submission::STATUS_QUEUED,
            'url_path' => '',
            'seq' => $seq,
            'section_ref' => $data['section'],
            'access_status' => 0,
        ];
        if ($published) {
            $publicationAttrs['date_published'] = $issueData['datePublished'];
        }
        $primaryIndex = 0;
        foreach ($data['authors'] as $i => $authorData) {
            if (!empty($authorData['primaryContact'])) {
                $primaryIndex = $i;
                break;
            }
        }
        $publicationAttrs['primary_contact_id'] = $authorId + 1 + $primaryIndex;
        $publication = $this->el($doc, $article, 'publication', null, $publicationAttrs);
        $this->el($doc, $publication, 'id', '1', ['type' => 'internal', 'advice' => 'ignore']);
        $this->localized($doc, $publication, 'title', $data['title']);
        $this->localized($doc, $publication, 'subtitle', $data['subtitle'] ?? null);
        $this->localized($doc, $publication, 'abstract', $data['abstract']);
        $this->el($doc, $publication, 'licenseUrl', 'https://creativecommons.org/licenses/by/4.0/');
        $holder = [];
        foreach ($this->contentLocales() as $l) {
            $names = array_map(fn ($a) => trim(($a['givenName'][$l] ?? '') . ' ' . ($a['familyName'][$l] ?? '')), $data['authors']);
            $holder[$l] = join(', ', $names);
        }
        $this->localized($doc, $publication, 'copyrightHolder', $holder);
        $this->el($doc, $publication, 'copyrightYear', (string) ($issueData['year'] ?? date('Y')));

        foreach ($this->contentLocales() as $l) {
            if (!empty($data['keywords'][$l])) {
                $keywords = $this->el($doc, $publication, 'keywords', null, ['locale' => $l]);
                foreach ($data['keywords'][$l] as $keyword) {
                    $this->vocabEntry($doc, $keywords, 'keyword', $keyword);
                }
            }
        }
        foreach ($this->contentLocales() as $l) {
            if (!empty($data['fundingAgency'][$l])) {
                $agencies = $this->el($doc, $publication, 'agencies', null, ['locale' => $l]);
                $this->vocabEntry($doc, $agencies, 'agency', $data['fundingAgency'][$l]);
            }
        }

        $authors = $this->el($doc, $publication, 'authors');
        foreach ($data['authors'] as $i => $authorData) {
            $author = $this->el($doc, $authors, 'author', null, [
                'include_in_browse' => 'true',
                'user_group_ref' => $this->authorGroupName(),
                'seq' => $i,
                'id' => ++$authorId,
                'primary_contact' => $i === $primaryIndex ? 'true' : 'false',
            ]);
            $this->localized($doc, $author, 'givenname', $authorData['givenName']);
            $this->localized($doc, $author, 'familyname', $authorData['familyName']);
            if (!empty($authorData['affiliation'])) {
                $affiliation = $this->el($doc, $author, 'affiliation');
                $this->localized($doc, $affiliation, 'name', $authorData['affiliation']);
            }
            $this->el($doc, $author, 'country', $authorData['country'] ?? 'RU');
            $this->el($doc, $author, 'email', $authorData['email']);
        }

        if ($published) {
            $galleySeq = 0;
            $labels = ['pdf' => 'PDF', 'html' => 'HTML', 'xml' => 'XML (JATS)'];
            foreach ($fileIds as [$fileLocale, $format, $thisFileId]) {
                $galley = $this->el($doc, $publication, 'article_galley', null, ['locale' => $fileLocale, 'approved' => 'true']);
                $this->el($doc, $galley, 'id', (string) $thisFileId, ['type' => 'internal', 'advice' => 'ignore']);
                $this->el($doc, $galley, 'name', $labels[$format], ['locale' => $fileLocale]);
                $this->el($doc, $galley, 'seq', (string) $galleySeq++);
                $this->el($doc, $galley, 'submission_file_ref', null, ['id' => $thisFileId]);
            }
        }

        if (!empty($data['citations'])) {
            $citations = $this->el($doc, $publication, 'citations');
            foreach ($data['citations'] as $citation) {
                $this->el($doc, $citations, 'citation', $citation);
            }
        }

        if ($published) {
            $ident = $this->el($doc, $publication, 'issue_identification');
            $this->el($doc, $ident, 'volume', (string) $issueData['volume']);
            $this->el($doc, $ident, 'number', (string) $issueData['number']);
            $this->el($doc, $ident, 'year', (string) $issueData['year']);
            if (!empty($data['pages'])) {
                $this->el($doc, $publication, 'pages', $data['pages']);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Name of the journal's author group as stored (native import matches by name). */
    protected function authorGroupName(): string
    {
        static $name = null;
        if ($name === null) {
            $group = $this->userGroup('author');
            $names = $group ? array_filter((array) $group->name) : [];
            $name = $names['en'] ?? (reset($names) ?: 'Author');
        }
        return $name;
    }

    /** Name of the "Article Text" genre as stored in this journal. */
    protected function genreName(): string
    {
        static $name = null;
        if ($name === null) {
            $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var \PKP\submission\GenreDAO $genreDao */
            $genre = $genreDao->getByKey('SUBMISSION', $this->journal->getId());
            $names = $genre ? array_filter((array) $genre->getName(null)) : [];
            $name = $names['en'] ?? (reset($names) ?: 'Article Text');
        }
        return $name;
    }

    /**
     * Languages the demo content is written in (journal.json "contentLocales",
     * Russian and English first), limited to the interface languages.
     */
    protected function contentLocales(): array
    {
        $wanted = $this->content['journal']['contentLocales'] ?? self::LOCALES;
        return array_values(array_unique(array_merge(self::LOCALES, array_intersect($wanted, $this->uiLocales()))));
    }

    protected function onlyContentLocales(array $values): array
    {
        return array_intersect_key($values, array_flip($this->contentLocales()));
    }

    /** Fill the interface languages that have no translation with the English value. */
    protected function withFallbackLocales(array $values): array
    {
        foreach ($this->uiLocales() as $locale) {
            if (!isset($values[$locale]) && isset($values['en'])) {
                $values[$locale] = $values['en'];
            }
        }
        return $values;
    }

    protected function uiLocales(): array
    {
        $available = array_keys(Locale::getLocales());
        $wanted = $this->content['journal']['uiLocales'] ?? self::LOCALES;
        $missing = array_diff($wanted, $available);
        if ($missing) {
            static $reported = false;
            if (!$reported) {
                $this->line('  (locales not shipped with this OJS version, skipped: ' . join(', ', $missing) . ')');
                $reported = true;
            }
        }
        return array_values(array_unique(array_merge(self::LOCALES, array_intersect($wanted, $available))));
    }

    protected function checklistHtml(array $items): array
    {
        $html = [];
        foreach ($this->contentLocales() as $locale) {
            $lis = array_map(fn ($item) => '<li>' . htmlspecialchars($item[$locale] ?? '') . '</li>', $items);
            $html[$locale] = $lis ? '<ul>' . join('', $lis) . '</ul>' : '';
        }
        return $html;
    }

    protected function poisson(float $lambda): int
    {
        if ($lambda <= 0) {
            return 0;
        }
        if ($lambda > 30) {
            return max(0, (int) round($lambda + sqrt($lambda) * $this->gauss()));
        }
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);
        return $k - 1;
    }

    protected function gauss(): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    protected function step(string $title, callable $fn): void
    {
        $this->line("» {$title}");
        $fn();
    }

    protected function line(string $text): void
    {
        echo $text . "\n";
    }

    protected function cleanup(): void
    {
        foreach (glob($this->workDir . '/*.xml') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->workDir);
    }
}

try {
    $tool = new DemoSeedTool($argv, $options);
    $tool->execute();
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
