/**
 * @file plugins/themes/academicJournal/js/academic.js
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Small progressive enhancements for the Academic Journal theme.
 */
(function () {
	'use strict';

	// Open external links in article references in a new tab.
	document.querySelectorAll('.obj_article_details .references a[href^="http"]').forEach(function (link) {
		link.setAttribute('target', '_blank');
		link.setAttribute('rel', 'noopener noreferrer');
	});
})();
