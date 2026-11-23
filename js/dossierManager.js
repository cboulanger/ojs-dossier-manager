/**
 * plugins/generic/dossierManager/js/dossierManager.js
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Behaviour of the backend pages. The pages are rendered inside OJS's Vue app,
 * which replaces the DOM after loading, so all handlers are delegated to document.
 *  - data-dm-confirm on a form: confirm before submitting
 *  - data-dm-copy="inputId" on a button: copy the input's value
 *  - .dmUserSearch: search the journal's users and choose one as contributor
 *  - textarea.dmRich: HTML editor (the TinyMCE that OJS loads on backend pages)
 */
(function () {
	'use strict';

	document.addEventListener('submit', function (e) {
		var message = e.target.getAttribute && e.target.getAttribute('data-dm-confirm');
		var submitter = e.submitter;
		var buttonMessage = submitter && submitter.getAttribute('data-dm-confirm');
		if ((buttonMessage || message) && !window.confirm(buttonMessage || message)) {
			e.preventDefault();
		}
	}, true);

	document.addEventListener('click', function (e) {
		var button = e.target.closest && e.target.closest('[data-dm-copy]');
		if (!button) {
			return;
		}
		var input = document.getElementById(button.getAttribute('data-dm-copy'));
		if (!input) {
			return;
		}
		input.select();
		var done = function () {
			var label = button.textContent;
			button.textContent = button.getAttribute('data-dm-copied') || label;
			setTimeout(function () { button.textContent = label; }, 2000);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
		} else {
			document.execCommand('copy');
			done();
		}
	});

	// HTML editor for textarea.dmRich. OJS loads TinyMCE on all backend pages
	// (plugins/generic/tinymce); without it the textarea stays a plain field.
	function richParams(textarea) {
		var $ = window.jQuery;
		var pkpParams = $ && $.pkp && $.pkp.controllers && $.pkp.controllers.SiteHandler &&
			$.pkp.controllers.SiteHandler.prototype.tinymceParams_;
		var pluginData = ($ && $.pkp && $.pkp.plugins && $.pkp.plugins.generic &&
			$.pkp.plugins.generic.tinymceplugin) || {};
		var params = {
			width: '100%',
			resize: 'both',
			entity_encoding: 'raw',
			convert_urls: false,
			forced_root_block: 'p',
			browser_spellcheck: true,
			contextmenu: false,
			menubar: false,
			statusbar: false,
			skin: 'tinymce-5',
			license_key: 'gpl',
			sandbox_iframes: false,
			block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3',
			content_css: (window.pkp && window.pkp.app && window.pkp.app.tinyMceContentCSS) ||
				($ && $.pkp && $.pkp.app && $.pkp.app.tinyMceContentCSS) || undefined
		};
		if (pkpParams) {
			// Same configuration as OJS's own editors
			for (var key in pkpParams) {
				if (Object.prototype.hasOwnProperty.call(pkpParams, key)) {
					params[key] = pkpParams[key];
				}
			}
		} else if (pluginData.tinymceParams) {
			params.language = pluginData.tinymceParams.language;
			params.language_url = pluginData.tinymceParams.language_url;
		}
		// The variables are inserted as text, so the pkpTags plugin is not needed
		params.plugins = 'link lists code fullscreen';
		params.toolbar = 'bold italic underline | blocks | bullist numlist | link unlink | code fullscreen';
		params.height = (20 * (parseInt(textarea.getAttribute('rows'), 10) || 10)) + 'px';
		params.target = textarea;
		delete params.selector;
		delete params.init_instance_callback;
		delete params.setup;
		delete params.readonly;
		return params;
	}

	function initRich() {
		if (!window.tinymce) {
			return;
		}
		var textareas = document.querySelectorAll('textarea.dmRich:not([data-dm-rich])');
		Array.prototype.forEach.call(textareas, function (textarea) {
			textarea.setAttribute('data-dm-rich', '1');
			window.tinymce.init(richParams(textarea));
		});
	}

	// The Vue app replaces the DOM while loading, so initialise once the page is complete
	if (document.readyState === 'complete') {
		initRich();
	} else {
		window.addEventListener('load', initRich);
	}

	// Copy the editor's content into the textarea before the form is sent
	document.addEventListener('submit', function () {
		if (window.tinymce) {
			window.tinymce.triggerSave();
		}
	}, true);

	// User search
	var timer = null;
	document.addEventListener('input', function (e) {
		if (!e.target.classList || !e.target.classList.contains('dmUserSearch__input')) {
			return;
		}
		var box = e.target.closest('.dmUserSearch');
		var results = box.querySelector('.dmUserSearch__results');
		var term = e.target.value.trim();
		clearTimeout(timer);
		if (term.length < 2) {
			results.innerHTML = '';
			return;
		}
		timer = setTimeout(function () {
			var url = box.getAttribute('data-url');
			url += (url.indexOf('?') === -1 ? '?' : '&') + 'term=' + encodeURIComponent(term);
			fetch(url, {credentials: 'same-origin'})
				.then(function (r) { return r.json(); })
				.then(function (users) {
					results.innerHTML = '';
					users.forEach(function (user) {
						var li = document.createElement('li');
						li.tabIndex = 0;
						li.setAttribute('role', 'option');
						li.dataset.id = user.id;
						li.dataset.label = user.name + ' <' + user.email + '>';
						li.textContent = li.dataset.label + (user.affiliation ? ', ' + user.affiliation : '');
						results.appendChild(li);
					});
				});
		}, 250);
	});

	function choose(li) {
		var box = li.closest('.dmUserSearch');
		box.querySelector('.dmUserSearch__value').value = li.dataset.id;
		box.querySelector('.dmUserSearch__results').innerHTML = '';
		box.querySelector('.dmUserSearch__input').value = '';
		var selected = box.querySelector('.dmUserSearch__selected');
		selected.textContent = li.dataset.label;
		var clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'pkp_button dmUserSearch__clear';
		clear.textContent = '×';
		clear.setAttribute('aria-label', 'Remove');
		selected.appendChild(clear);
	}

	document.addEventListener('click', function (e) {
		var li = e.target.closest && e.target.closest('.dmUserSearch__results li');
		if (li) {
			choose(li);
			return;
		}
		var clear = e.target.closest && e.target.closest('.dmUserSearch__clear');
		if (clear) {
			var box = clear.closest('.dmUserSearch');
			box.querySelector('.dmUserSearch__value').value = '';
			box.querySelector('.dmUserSearch__selected').innerHTML = '';
		}
	});

	document.addEventListener('keydown', function (e) {
		var li = e.target.closest && e.target.closest('.dmUserSearch__results li');
		if (li && (e.key === 'Enter' || e.key === ' ')) {
			e.preventDefault();
			choose(li);
		}
		// Enter in the search field must not submit the form
		if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('dmUserSearch__input')) {
			e.preventDefault();
		}
	});
}());
