<?php

/**
 * @file plugins/themes/academicJournal/AcademicJournalThemePlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class AcademicJournalThemePlugin
 *
 * @brief Classic academic theme for scholarly journals. A child theme of the
 *  OJS default theme: it reuses the parent's templates and scripts and adds
 *  its own typography, colour scheme, header/footer and journal homepage.
 */

namespace APP\plugins\themes\academicJournal;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use PKP\core\PKPSessionGuard;
use PKP\plugins\Hook;
use PKP\plugins\ThemePlugin;

class AcademicJournalThemePlugin extends ThemePlugin
{
    public const DEFAULT_PRIMARY = '#14213D';
    public const DEFAULT_ACCENT = '#8C1C2C';

    /**
     * @copydoc ThemePlugin::isActive()
     *
     * Command-line tools have no router; the core implementation would fail.
     */
    public function isActive()
    {
        if (PKPSessionGuard::isSessionDisable() || !Application::get()->getRequest()->getRouter()) {
            return false;
        }
        return parent::isActive();
    }

    /**
     * @copydoc ThemePlugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc ThemePlugin::init()
     */
    public function init()
    {
        $this->setParent('defaultthemeplugin');

        $this->addOption('primaryColour', 'FieldColor', [
            'label' => __('plugins.themes.academicJournal.option.primaryColour.label'),
            'description' => __('plugins.themes.academicJournal.option.primaryColour.description'),
            'default' => self::DEFAULT_PRIMARY,
        ]);

        $this->addOption('accentColour', 'FieldColor', [
            'label' => __('plugins.themes.academicJournal.option.accentColour.label'),
            'description' => __('plugins.themes.academicJournal.option.accentColour.description'),
            'default' => self::DEFAULT_ACCENT,
        ]);

        $this->addOption('showHero', 'FieldOptions', [
            'label' => __('plugins.themes.academicJournal.option.showHero.label'),
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.themes.academicJournal.option.showHero.option'),
                ],
            ],
            'default' => true,
        ]);

        $this->addOption('heroTagline', 'FieldText', [
            'label' => __('plugins.themes.academicJournal.option.heroTagline.label'),
            'description' => __('plugins.themes.academicJournal.option.heroTagline.description'),
            'isMultilingual' => true,
            'default' => '',
        ]);

        $this->addOption('showJournalStats', 'FieldOptions', [
            'label' => __('plugins.themes.academicJournal.option.showJournalStats.label'),
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.themes.academicJournal.option.showJournalStats.option'),
                ],
            ],
            'default' => true,
        ]);

        $primary = $this->sanitizeColour($this->getOption('primaryColour'), self::DEFAULT_PRIMARY);
        $accent = $this->sanitizeColour($this->getOption('accentColour'), self::DEFAULT_ACCENT);

        // Compile our styles together with the parent stylesheet so that the
        // parent's LESS variables and mixins are available.
        $this->modifyStyle('stylesheet', [
            'addLess' => [
                'styles/fonts.less',
                'styles/academic.less',
            ],
            'addLessVariables' => join("\n", [
                '@ac-primary: ' . $primary . ';',
                '@ac-accent: ' . $accent . ';',
                '@bg-base: ' . $primary . ';',
                '@primary: ' . $accent . ';',
            ]),
        ]);

        // The parent loads Noto Sans by default; our own fonts are bundled.
        $this->removeStyle('font');

        $this->addScript('academicJournal', 'js/academic.js');

        Hook::add('TemplateManager::display', $this->assignTemplateData(...));
    }

    /**
     * Provide extra template variables used by the overridden templates.
     */
    public function assignTemplateData(string $hookName, array $args): bool
    {
        $templateMgr = $args[0];
        $template = $args[1];

        if (!str_starts_with((string) $template, 'frontend/')) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        $tagline = $this->getOption('heroTagline');
        if (is_array($tagline)) {
            $locale = \PKP\facades\Locale::getLocale();
            $tagline = $tagline[$locale] ?? ($context ? ($tagline[$context->getPrimaryLocale()] ?? '') : '');
        }

        $templateMgr->assign([
            'academicShowHero' => (bool) $this->getOption('showHero'),
            'academicHeroTagline' => (string) $tagline,
            'academicShowStats' => (bool) $this->getOption('showJournalStats'),
            'academicYear' => date('Y'),
        ]);

        if ($context && $template === 'frontend/pages/indexJournal.tpl' && $this->getOption('showJournalStats')) {
            $templateMgr->assign('academicStats', $this->getJournalStats($context->getId()));
        }

        return Hook::CONTINUE;
    }

    /**
     * Counts shown in the homepage statistics strip.
     */
    protected function getJournalStats(int $contextId): array
    {
        try {
            $articles = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_PUBLISHED])
                ->getCount();
            $issues = Repo::issue()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByPublished(true)
                ->getCount();
            $authors = \Illuminate\Support\Facades\DB::table('authors as a')
                ->join('publications as p', 'p.publication_id', '=', 'a.publication_id')
                ->join('submissions as s', 's.current_publication_id', '=', 'p.publication_id')
                ->where('s.context_id', $contextId)
                ->where('s.status', Submission::STATUS_PUBLISHED)
                ->distinct()
                ->count('a.email');
        } catch (\Throwable $e) {
            return [];
        }

        return [
            'articles' => $articles,
            'issues' => $issues,
            'authors' => $authors,
        ];
    }

    /**
     * Only accept #RGB / #RRGGBB values so that options can never inject LESS.
     */
    protected function sanitizeColour($value, string $default): string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : $default;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.themes.academicJournal.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.themes.academicJournal.description');
    }
}
