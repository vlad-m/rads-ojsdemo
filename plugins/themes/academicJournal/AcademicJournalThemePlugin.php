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
    public const DEFAULT_HIGHLIGHT = '#B08D57';

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

        $this->addOption('highlightColour', 'FieldColor', [
            'label' => __('plugins.themes.academicJournal.option.highlightColour.label'),
            'description' => __('plugins.themes.academicJournal.option.highlightColour.description'),
            'default' => self::DEFAULT_HIGHLIGHT,
        ]);

        $this->addOption('headingFont', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.academicJournal.option.headingFont.label'),
            'options' => [
                ['value' => 'serif', 'label' => __('plugins.themes.academicJournal.option.headingFont.serif')],
                ['value' => 'sans', 'label' => __('plugins.themes.academicJournal.option.headingFont.sans')],
            ],
            'default' => 'serif',
        ]);

        $this->addOption('logoPlacement', 'FieldOptions', [
            'type' => 'radio',
            'label' => __('plugins.themes.academicJournal.option.logoPlacement.label'),
            'description' => __('plugins.themes.academicJournal.option.logoPlacement.description'),
            'options' => [
                ['value' => 'beside', 'label' => __('plugins.themes.academicJournal.option.logoPlacement.beside')],
                ['value' => 'replace', 'label' => __('plugins.themes.academicJournal.option.logoPlacement.replace')],
            ],
            'default' => 'beside',
        ]);

        $this->addOption('ctaLabel', 'FieldText', [
            'label' => __('plugins.themes.academicJournal.option.ctaLabel.label'),
            'description' => __('plugins.themes.academicJournal.option.ctaLabel.description'),
            'isMultilingual' => true,
            'default' => '',
        ]);

        $this->addOption('ctaUrl', 'FieldText', [
            'label' => __('plugins.themes.academicJournal.option.ctaUrl.label'),
            'isMultilingual' => true,
            'default' => '',
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

        $this->addOption('heroKicker', 'FieldText', [
            'label' => __('plugins.themes.academicJournal.option.heroKicker.label'),
            'description' => __('plugins.themes.academicJournal.option.heroKicker.description'),
            'isMultilingual' => true,
            'default' => '',
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

        $this->addOption('galleysByLocale', 'FieldOptions', [
            'label' => __('plugins.themes.academicJournal.option.galleysByLocale.label'),
            'description' => __('plugins.themes.academicJournal.option.galleysByLocale.description'),
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.themes.academicJournal.option.galleysByLocale.option'),
                ],
            ],
            'default' => true,
        ]);

        $primary = $this->sanitizeColour($this->getOption('primaryColour'), self::DEFAULT_PRIMARY);
        $accent = $this->sanitizeColour($this->getOption('accentColour'), self::DEFAULT_ACCENT);
        $highlight = $this->sanitizeColour($this->getOption('highlightColour'), self::DEFAULT_HIGHLIGHT);
        $headingFont = $this->getOption('headingFont') === 'sans'
            ? 'Montserrat, "Century Gothic", Inter, sans-serif'
            : '"Source Serif 4", "PT Serif", Georgia, "Times New Roman", serif';

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
                '@ac-highlight: ' . $highlight . ';',
                '@ac-heading: ' . $headingFont . ';',
                '@bg-base: ' . $primary . ';',
                '@primary: ' . $accent . ';',
            ]),
        ]);

        // The parent loads Noto Sans by default; our own fonts are bundled.
        $this->removeStyle('font');

        $this->addScript('academicJournal', 'js/academic.js');

        Hook::add('TemplateManager::display', $this->assignTemplateData(...));
        Hook::add('NavigationMenus::displaySettings', $this->localizeMenuItem(...));
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

        // current locale → English → journal primary locale → any
        $localized = function ($value): string {
            if (is_array($value)) {
                $locale = \PKP\facades\Locale::getLocale();
                $context = Application::get()->getRequest()->getContext();
                $value = array_filter($value, fn ($v) => is_string($v) && $v !== '');
                $value = $value[$locale] ?? $value['en'] ?? ($context ? ($value[$context->getPrimaryLocale()] ?? null) : null) ?? (reset($value) ?: '');
            }
            return (string) $value;
        };
        $tagline = $localized($this->getOption('heroTagline'));
        $kicker = $localized($this->getOption('heroKicker'));

        $templateMgr->assign([
            'academicShowHero' => (bool) $this->getOption('showHero'),
            'academicHeroTagline' => $tagline,
            'academicHeroKicker' => $kicker,
            'academicCtaLabel' => $localized($this->getOption('ctaLabel')),
            'academicCtaUrl' => preg_match('#^https?://#i', $ctaUrl = $localized($this->getOption('ctaUrl'))) ? $ctaUrl : '',
            'academicShowStats' => (bool) $this->getOption('showJournalStats'),
            'academicYear' => date('Y'),
            'academicLogoBeside' => $this->getOption('logoPlacement') !== 'replace',
        ]);

        if ($context) {
            $templateMgr->assign('academicLocaleNames', $this->nativeLocaleNames($context));
        }

        if ($this->getOption('galleysByLocale')) {
            $this->filterGalleysByLocale($templateMgr);
        }

        if ($context && $template === 'frontend/pages/indexJournal.tpl' && $this->getOption('showJournalStats')) {
            $templateMgr->assign('academicStats', $this->getJournalStats($context->getId()));
        }

        return Hook::CONTINUE;
    }

    /**
     * Show each article's full text only in the reader's language: of the
     * galleys that have a language, keep those in the interface language,
     * else those in the article's own language. Galleys without a language
     * are always kept. Applies to issue tables of contents, the homepage and
     * the article page; the galley URLs themselves stay reachable.
     */
    protected function filterGalleysByLocale($templateMgr): void
    {
        $locale = \PKP\facades\Locale::getLocale();
        $pick = function (iterable $galleys, ?string $articleLocale) use ($locale): array {
            $all = $withLocale = [];
            foreach ($galleys as $galley) {
                $all[] = $galley;
                if ($galley->getData('locale')) {
                    $withLocale[$galley->getData('locale')][] = $galley;
                }
            }
            if (count($withLocale) < 2) {
                return $all;
            }
            $keep = $withLocale[$locale] ?? $withLocale[$articleLocale] ?? $withLocale['en'] ?? reset($withLocale);
            return array_values(array_filter($all, fn ($g) => !$g->getData('locale') || in_array($g, $keep, true)));
        };
        $filterPublication = function ($publication) use ($pick): void {
            if ($publication && !$publication->getData('academicGalleysFiltered')) {
                $publication->setData('galleys', collect($pick($publication->getData('galleys') ?? [], $publication->getData('locale'))));
                $publication->setData('academicGalleysFiltered', true);
            }
        };

        // issue table of contents (issue page, homepage)
        foreach ((array) $templateMgr->getTemplateVars('publishedSubmissions') as $section) {
            foreach ((array) ($section['articles'] ?? []) as $article) {
                if ($article instanceof Submission) {
                    $filterPublication($article->getCurrentPublication());
                }
            }
        }

        // article page
        $publication = $templateMgr->getTemplateVars('publication');
        if (is_object($publication) && is_array($primary = $templateMgr->getTemplateVars('primaryGalleys'))) {
            $templateMgr->assign('primaryGalleys', $pick($primary, $publication->getData('locale')));
            $filterPublication($publication);
        }
    }

    /**
     * OJS builds menu item titles only for the journal's form languages, so
     * in any other interface language the default items (Current, Archives…)
     * fell back to the primary language. Translate them on the fly.
     */
    public function localizeMenuItem(string $hookName, array $args): bool
    {
        $item = $args[0];
        $key = $item->getTitleLocaleKey();
        $locale = \PKP\facades\Locale::getLocale();
        if ($key && $key !== '{$loggedInUsername}' && !$item->getTitle($locale)) {
            $item->setTitle(__($key), $locale);
        }
        return Hook::CONTINUE;
    }

    /**
     * Each interface language named in that language ("Español", "中文"…).
     */
    protected function nativeLocaleNames($context): array
    {
        $names = [];
        foreach ($context->getSupportedLocales() as $localeKey) {
            $metadata = \PKP\facades\Locale::getMetadata($localeKey);
            $name = $metadata ? $metadata->getDisplayName($localeKey) : $localeKey;
            $names[$localeKey] = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        return $names;
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
