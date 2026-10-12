{**
 * plugins/themes/academicJournal/templates/frontend/components/footer.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Site-wide footer: journal details, quick links, licence, custom footer
 *  content (Settings > Website > Appearance > Page footer) and PKP credit.
 *}

	</div><!-- pkp_structure_main -->

	{if empty($isFullWidth)}
		{capture assign="sidebarCode"}{call_hook name="Templates::Common::Sidebar"}{/capture}
		{if $sidebarCode}
			<div class="pkp_structure_sidebar left" role="complementary">
				{$sidebarCode}
			</div><!-- pkp_sidebar.left -->
		{/if}
	{/if}
</div><!-- pkp_structure_content -->

<div class="pkp_structure_footer_wrapper" role="contentinfo">
	<a id="pkp_content_footer"></a>

	<div class="pkp_structure_footer">

		{if $currentContext}
			<div class="ac_footer_grid">
				<div class="ac_footer_col ac_footer_about">
					<div class="ac_footer_title">{$currentContext->getLocalizedName()|escape}</div>
					{if $currentContext->getLocalizedData('publisherInstitution') || $currentContext->getData('publisherInstitution')}
						<p class="ac_footer_publisher">
							{translate key="plugins.themes.academicJournal.publisher"}:
							{if $currentContext->getLocalizedData('publisherInstitution')}{$currentContext->getLocalizedData('publisherInstitution')|escape}{else}{$currentContext->getData('publisherInstitution')|escape}{/if}
						</p>
					{/if}
					<p class="ac_footer_issn">
						{if $currentContext->getData('onlineIssn')}<span>{translate key="plugins.themes.academicJournal.issnOnline"} {$currentContext->getData('onlineIssn')|escape}</span>{/if}
						{if $currentContext->getData('printIssn')}<span>{translate key="plugins.themes.academicJournal.issnPrint"} {$currentContext->getData('printIssn')|escape}</span>{/if}
					</p>
				</div>
				<div class="ac_footer_col">
					<div class="ac_footer_heading">{translate key="plugins.themes.academicJournal.forReaders"}</div>
					<ul>
						<li><a href="{url page="issue" op="current"}">{translate key="navigation.current"}</a></li>
						<li><a href="{url page="issue" op="archive"}">{translate key="navigation.archives"}</a></li>
						<li><a href="{url page="search"}">{translate key="common.search"}</a></li>
						<li><a href="{url page="announcement"}">{translate key="announcement.announcements"}</a></li>
					</ul>
				</div>
				<div class="ac_footer_col">
					<div class="ac_footer_heading">{translate key="plugins.themes.academicJournal.forAuthors"}</div>
					<ul>
						<li><a href="{url page="about" op="submissions"}">{translate key="about.submissions"}</a></li>
						<li><a href="{url page="about" op="editorialMasthead"}">{translate key="plugins.themes.academicJournal.editorialBoard"}</a></li>
						<li><a href="{url page="about"}">{translate key="about.aboutContext"}</a></li>
						<li><a href="{url page="about" op="contact"}">{translate key="about.contact"}</a></li>
					</ul>
				</div>
				<div class="ac_footer_col ac_footer_license">
					<div class="ac_footer_heading">{translate key="plugins.themes.academicJournal.license"}</div>
					<p>{translate key="plugins.themes.academicJournal.licenseText"}</p>
					<a class="ac_cc_badge" href="https://creativecommons.org/licenses/by/4.0/" rel="license noopener" target="_blank">CC BY 4.0</a>
				</div>
			</div>
		{/if}

		{if $pageFooter}
			<div class="pkp_footer_content">
				{$pageFooter}
			</div>
		{/if}

		<div class="ac_footer_bottom">
			<span>&copy; {$academicYear|escape}{if $currentContext} {$currentContext->getLocalizedName()|escape}{/if}</span>
			<a class="ac_pkp_credit" href="{url page="about" op="aboutThisPublishingSystem"}">{translate key="about.aboutThisPublishingSystem"}</a>
		</div>
	</div>
</div><!-- pkp_structure_footer_wrapper -->

</div><!-- pkp_structure_page -->

{load_script context="frontend"}

{call_hook name="Templates::Common::Footer::PageFooter"}
</body>
</html>
