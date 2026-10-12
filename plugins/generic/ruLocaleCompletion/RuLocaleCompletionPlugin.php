<?php

/**
 * @file plugins/generic/ruLocaleCompletion/RuLocaleCompletionPlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class RuLocaleCompletionPlugin
 *
 * @brief Fills the gaps of the official Russian translation of OJS 3.5 (keys
 *  that are shown as ##locale.key## in the interface). The plugin has no
 *  settings and is always active once installed: its only job is to register
 *  locale/ru/locale.po.
 */

namespace APP\plugins\generic\ruLocaleCompletion;

use PKP\plugins\GenericPlugin;

class RuLocaleCompletionPlugin extends GenericPlugin
{
    public function isSitePlugin()
    {
        return true;
    }

    public function getEnabled($contextId = null)
    {
        return true;
    }

    public function getCanEnable()
    {
        return false;
    }

    public function getCanDisable()
    {
        return false;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ruLocaleCompletion.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ruLocaleCompletion.description');
    }
}
