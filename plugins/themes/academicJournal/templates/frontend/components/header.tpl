{**
 * plugins/themes/academicJournal/templates/frontend/components/header.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Site-wide header: utility bar (ISSN, languages), masthead with the
 *  journal title or logo and the primary navigation. Keeps the markup hooks
 *  (classes/ids) that the parent theme's JavaScript relies on.
 *}
{strip}
	{assign var="showingLogo" value=true}
	{if !$displayPageHeaderLogo}
		{assign var="showingLogo" value=false}
	{/if}
{/strip}
<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
{if !$pageTitleTranslated}{capture assign="pageTitleTranslated"}{translate key=$pageTitle}{/capture}{/if}
{include file="frontend/components/headerHead.tpl"}
<body class="pkp_page_{$requestedPage|escape|default:"index"} pkp_op_{$requestedOp|escape|default:"index"}{if $showingLogo && !$academicLogoBeside} has_site_logo{/if}{if $showingLogo && $academicLogoBeside} has_brand_logo{/if} academic_theme" dir="{$currentLocaleLangDir|escape|default:"ltr"}">

	<div class="pkp_structure_page">

		<header class="pkp_structure_head" id="headerNavigationContainer" role="banner">
			{include file="frontend/components/skipLinks.tpl"}

			{* Utility bar *}
			<div class="ac_topbar">
				<div class="ac_topbar_inner">
					<div class="ac_topbar_meta">
						{if $currentContext}
							{if $currentContext->getData('onlineIssn')}
								<span class="ac_issn">{translate key="plugins.themes.academicJournal.issnOnline"}&nbsp;{$currentContext->getData('onlineIssn')|escape}</span>
							{/if}
							{if $currentContext->getData('printIssn')}
								<span class="ac_issn">{translate key="plugins.themes.academicJournal.issnPrint"}&nbsp;{$currentContext->getData('printIssn')|escape}</span>
							{/if}
							<span class="ac_oa"><span class="ac_oa_icon" aria-hidden="true"></span>{translate key="plugins.themes.academicJournal.openAccess"}</span>
						{/if}
					</div>
					{if $currentContext}
						{assign var="acLocales" value=$academicLocaleNames|default:$currentContext->getSupportedLocaleNames()}
					{else}
						{assign var="acLocales" value=[]}
					{/if}
					{if $acLocales|@count > 1}
						{if $acLocales|@count > 4}
							<details class="ac_lang_menu">
								<summary aria-label="{translate|escape key="common.language"}">
									<span class="ac_lang_globe" aria-hidden="true"></span>
									{$acLocales[$currentLocale]|default:$currentLocale|escape}
								</summary>
								<ul>
									{foreach from=$acLocales key=acLocaleKey item=acLocaleName}
										<li{if $acLocaleKey == $currentLocale} class="current"{/if}>
											<a href="{url router=PKP\core\PKPApplication::ROUTE_PAGE page="user" op="setLocale" path=$acLocaleKey source=$smarty.server.SERVER_NAME|cat:$smarty.server.REQUEST_URI}" lang="{$acLocaleKey|replace:"_":"-"|escape}"{if $acLocaleKey == $currentLocale} aria-current="true"{/if}>{$acLocaleName|escape}</a>
										</li>
									{/foreach}
								</ul>
							</details>
						{else}
						<ul class="ac_lang" aria-label="{translate|escape key="common.language"}">
							{foreach from=$acLocales key=acLocaleKey item=acLocaleName}
								<li{if $acLocaleKey == $currentLocale} class="current"{/if}>
									<a href="{url router=PKP\core\PKPApplication::ROUTE_PAGE page="user" op="setLocale" path=$acLocaleKey source=$smarty.server.SERVER_NAME|cat:$smarty.server.REQUEST_URI}" lang="{$acLocaleKey|replace:"_":"-"|escape}"{if $acLocaleKey == $currentLocale} aria-current="true"{/if}>
										{$acLocaleKey|regex_replace:"/_.*$/":""|upper|escape}
									</a>
								</li>
							{/foreach}
						</ul>
						{/if}
					{/if}
				</div>
			</div>

			<div class="pkp_head_wrapper">

				<div class="pkp_site_name_wrapper">
					<button class="pkp_site_nav_toggle">
						<span>Open Menu</span>
					</button>
					{if !$requestedPage || $requestedPage === 'index'}
						<h1 class="pkp_screen_reader">
							{if $currentContext}
								{$displayPageHeaderTitle|escape}
							{else}
								{$siteTitle|escape}
							{/if}
						</h1>
					{/if}
					<div class="pkp_site_name">
					{capture assign="homeUrl"}
						{url page="index" router=PKP\core\PKPApplication::ROUTE_PAGE}
					{/capture}
					{if $displayPageHeaderLogo && !$academicLogoBeside}
						<a href="{$homeUrl}" class="is_img">
							<img src="{$publicFilesDir}/{$displayPageHeaderLogo.uploadName|escape:"url"}" width="{$displayPageHeaderLogo.width|escape}" height="{$displayPageHeaderLogo.height|escape}" {if $displayPageHeaderLogo.altText != ''}alt="{$displayPageHeaderLogo.altText|escape}"{/if} />
						</a>
					{elseif $displayPageHeaderTitle || $currentContext}
						<a href="{$homeUrl}" class="is_text">
							<span class="ac_seal" aria-hidden="true">{if $currentContext && $currentContext->getLocalizedData('acronym')}{$currentContext->getLocalizedData('acronym')|escape|truncate:4:""}{else}{$displayPageHeaderTitle|escape|truncate:1:""}{/if}</span>
							<span class="ac_title_text">
								<span class="ac_title">{$displayPageHeaderTitle|escape}</span>
								{if $currentContext && $currentContext->getLocalizedData('abbreviation')}
									<span class="ac_subtitle">{$currentContext->getLocalizedData('abbreviation')|escape}</span>
								{/if}
							</span>
						</a>
					{else}
						<a href="{$homeUrl}" class="is_img">
							<img src="{$baseUrl}/templates/images/structure/logo.png" alt="{$applicationName|escape}" title="{$applicationName|escape}" width="180" height="90" />
						</a>
					{/if}
					</div>
					{if $displayPageHeaderLogo && $academicLogoBeside}
						<a href="{$homeUrl}" class="ac_brand_logo">
							<img src="{$publicFilesDir}/{$displayPageHeaderLogo.uploadName|escape:"url"}" width="{$displayPageHeaderLogo.width|escape}" height="{$displayPageHeaderLogo.height|escape}" alt="{$displayPageHeaderLogo.altText|escape|default:$displayPageHeaderTitle|escape}" />
						</a>
					{/if}
				</div>

				{capture assign="primaryMenu"}
					{load_menu name="primary" id="navigationPrimary" ulClass="pkp_navigation_primary"}
				{/capture}

				<nav class="pkp_site_nav_menu" aria-label="{translate|escape key="common.navigation.site"}">
					<a id="siteNav"></a>
					<div class="pkp_navigation_primary_row">
						<div class="pkp_navigation_primary_wrapper">
							{$primaryMenu}

							{if $academicCtaUrl && $academicCtaLabel}
								<div class="ac_nav_cta_wrapper">
									<a class="ac_nav_cta" href="{$academicCtaUrl|escape}" target="_blank" rel="noopener">{$academicCtaLabel|escape}</a>
								</div>
							{/if}

							{if $currentContext && $requestedPage !== 'search'}
								<div class="pkp_navigation_search_wrapper">
									<a href="{url page="search"}" class="pkp_search pkp_search_desktop">
										<span class="fa fa-search" aria-hidden="true"></span>
										{translate key="common.search"}
									</a>
								</div>
							{/if}
						</div>
					</div>
					<div class="pkp_navigation_user_wrapper" id="navigationUserWrapper">
						{load_menu name="user" id="navigationUser" ulClass="pkp_navigation_user" liClass="profile"}
					</div>
				</nav>
			</div><!-- .pkp_head_wrapper -->
		</header><!-- .pkp_structure_head -->

		{if $isFullWidth}
			{assign var=hasSidebar value=0}
		{/if}
		<div class="pkp_structure_content{if $hasSidebar} has_sidebar{/if}">
			<div class="pkp_structure_main" role="main">
				<a id="pkp_content_main"></a>
