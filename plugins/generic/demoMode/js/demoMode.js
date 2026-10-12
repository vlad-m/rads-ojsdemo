/**
 * @file plugins/generic/demoMode/js/demoMode.js
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Demo mode banner and one-click demo sign-in on the login page.
 */
(function () {
	'use strict';

	var config = window.ojsDemoMode;
	if (!config) {
		return;
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	function addBanner() {
		if (document.querySelector('.ojs-demo-banner')) {
			return;
		}
		var banner = el('div', 'ojs-demo-banner' + (config.privileged ? ' is-superuser' : ''));
		banner.setAttribute('role', 'status');
		banner.appendChild(el('span', 'ojs-demo-banner__badge', 'DEMO'));
		banner.appendChild(el('span', 'ojs-demo-banner__text', config.banner));
		var close = el('button', 'ojs-demo-banner__close', '×');
		close.type = 'button';
		close.setAttribute('aria-label', 'Close');
		close.addEventListener('click', function () {
			banner.classList.add('is-hidden');
		});
		banner.appendChild(close);
		document.body.appendChild(banner);
	}

	function addLoginHelper() {
		var form = document.querySelector('form.cmp_form.login, form#login, .page_login form');
		if (!form || !config.accounts || !config.accounts.length || config.loggedIn) {
			return;
		}
		var username = form.querySelector('input[name="username"]');
		var password = form.querySelector('input[name="password"]');
		if (!username || !password) {
			return;
		}

		var box = el('div', 'ojs-demo-login');
		box.appendChild(el('div', 'ojs-demo-login__title', config.loginTitle));
		var hint = el('p', 'ojs-demo-login__hint', config.loginHint + ' ');
		hint.appendChild(el('code', '', config.password));
		box.appendChild(hint);

		var list = el('div', 'ojs-demo-login__accounts');
		config.accounts.forEach(function (account) {
			var button = el('button', 'ojs-demo-login__account');
			button.type = 'button';
			button.appendChild(el('strong', '', account.label));
			button.appendChild(el('span', '', account.username));
			button.addEventListener('click', function () {
				username.value = account.username;
				password.value = config.password;
				if (form.requestSubmit) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			});
			list.appendChild(button);
		});
		box.appendChild(list);
		form.parentNode.insertBefore(box, form);
	}

	function init() {
		addBanner();
		addLoginHelper();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
