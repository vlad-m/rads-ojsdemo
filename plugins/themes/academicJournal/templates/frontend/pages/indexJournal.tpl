{**
 * plugins/themes/academicJournal/templates/frontend/pages/indexJournal.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Journal homepage: hero with the journal's mission and calls to
 *  action, a statistics strip, announcements and the current issue.
 *
 * @uses $currentJournal Journal This journal
 * @uses $issue Issue Current issue
 * @uses $academicStats array Counts of published issues, articles and authors
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$currentJournal->getLocalizedName()}

<div class="page_index_journal">

	{call_hook name="Templates::Index::journal"}

	{if $academicShowHero}
		{assign var="acDescription" value=$currentContext->getLocalizedData('description')|strip_tags|trim}
		<section class="ac_hero">
			<div class="ac_hero_text">
				<p class="ac_hero_kicker">{if $academicHeroKicker}{$academicHeroKicker|escape}{else}{translate key="plugins.themes.academicJournal.kicker"}{/if}</p>
				<h2 class="ac_hero_title">{$currentContext->getLocalizedName()|escape}</h2>
				{if $academicHeroTagline}
					<p class="ac_hero_lead">{$academicHeroTagline|escape}</p>
				{elseif $acDescription}
					<p class="ac_hero_lead">{$acDescription|truncate:340:"…"|escape}</p>
				{/if}
				<div class="ac_hero_actions">
					<a class="ac_btn ac_btn_primary" href="{url page="about" op="submissions"}">{translate key="plugins.themes.academicJournal.submitManuscript"}</a>
					{if $issue}
						<a class="ac_btn ac_btn_ghost" href="{url page="issue" op="view" path=$issue->getBestIssueId()}">{translate key="plugins.themes.academicJournal.readCurrentIssue"}</a>
					{/if}
					<a class="ac_btn_link" href="{url page="about"}">{translate key="plugins.themes.academicJournal.aboutJournal"} &rarr;</a>
				</div>
			</div>
			<div class="ac_hero_visual">
				{if $homepageImage}
					<img src="{$publicFilesDir}/{$homepageImage.uploadName|escape:"url"}"{if $homepageImage.altText} alt="{$homepageImage.altText|escape}"{else} alt=""{/if}>
				{elseif $issue && $issue->getLocalizedCoverImageUrl()}
					<a href="{url page="issue" op="view" path=$issue->getBestIssueId()}" class="ac_hero_cover">
						<img src="{$issue->getLocalizedCoverImageUrl()|escape}" alt="{$issue->getLocalizedCoverImageAltText()|escape|default:$issue->getIssueIdentification()|escape}">
						<span class="ac_hero_cover_caption">{$issue->getIssueIdentification()|escape}</span>
					</a>
				{/if}
			</div>
		</section>
	{/if}

	{if $academicShowStats && $academicStats}
		<ul class="ac_stats">
			<li><strong>{$academicStats.issues|escape}</strong><span>{translate key="plugins.themes.academicJournal.stats.issues"}</span></li>
			<li><strong>{$academicStats.articles|escape}</strong><span>{translate key="plugins.themes.academicJournal.stats.articles"}</span></li>
			<li><strong>{$academicStats.authors|escape}</strong><span>{translate key="plugins.themes.academicJournal.stats.authors"}</span></li>
			<li><strong>{translate key="plugins.themes.academicJournal.stats.peerReviewValue"}</strong><span>{translate key="plugins.themes.academicJournal.stats.peerReview"}</span></li>
		</ul>
	{/if}

	{if $highlights->count()}
		{include file="frontend/components/highlights.tpl" highlights=$highlights}
	{/if}

	{if !$academicShowHero && $activeTheme && !$activeTheme->getOption('useHomepageImageAsHeader') && $homepageImage}
		<div class="homepage_image">
			<img src="{$publicFilesDir}/{$homepageImage.uploadName|escape:"url"}"{if $homepageImage.altText} alt="{$homepageImage.altText|escape}"{/if}>
		</div>
	{/if}

	{if $activeTheme && $activeTheme->getOption('showDescriptionInJournalIndex')}
		<section class="homepage_about">
			<a id="homepageAbout"></a>
			<h2>{translate key="about.aboutContext"}</h2>
			{$currentContext->getLocalizedData('description')}
		</section>
	{/if}

	{include file="frontend/objects/announcements_list.tpl" numAnnouncements=$numAnnouncementsHomepage}

	{if $issue}
		<section class="current_issue">
			<a id="homepageIssue"></a>
			<div class="ac_section_head">
				<h2>{translate key="journal.currentIssue"}</h2>
				<a href="{url page="issue" op="archive"}" class="ac_section_more">{translate key="journal.viewAllIssues"} &rarr;</a>
			</div>
			<div class="current_issue_title">
				{$issue->getIssueIdentification()|escape}
				{if $issue->getDatePublished()}
					<span class="ac_issue_date">{translate key="submissions.published"}: {$issue->getDatePublished()|date_format:$dateFormatShort}</span>
				{/if}
			</div>
			{include file="frontend/objects/issue_toc.tpl" heading="h3"}
		</section>
	{/if}

	{if $additionalHomeContent}
		<div class="additional_content">
			{$additionalHomeContent}
		</div>
	{/if}
</div><!-- .page -->

{include file="frontend/components/footer.tpl"}
