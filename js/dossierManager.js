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
