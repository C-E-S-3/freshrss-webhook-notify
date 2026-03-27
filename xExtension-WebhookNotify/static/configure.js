/* Webhook Notify — configuration UI logic */
(function () {
	'use strict';

	var webhookIndex = document.querySelectorAll('.webhook-entry').length;
	var GH_URL_PREFIX = 'https://api.github.com/repos/';

	function updateGithubPreview(entry) {
		var input = entry.querySelector('.github-repo-input');
		var preview = entry.querySelector('.github-url-preview');
		if (input && preview) {
			var repo = input.value.trim();
			preview.textContent = 'Target: ' + GH_URL_PREFIX + (repo || 'owner/repo') + '/dispatches';
		}
	}

	function bindEntry(entry) {
		/* Type dropdown toggles data-active-type for CSS visibility */
		var sel = entry.querySelector('.webhook-type-select');
		if (sel) {
			sel.addEventListener('change', function () {
				entry.setAttribute('data-active-type', sel.value);
			});
		}

		/* Remove button */
		var rm = entry.querySelector('.remove-btn');
		if (rm) {
			rm.addEventListener('click', function () {
				entry.remove();
			});
		}

		/* Enable/disable checkbox */
		var cb = entry.querySelector('input[type="checkbox"]');
		if (cb) {
			cb.addEventListener('change', function () {
				entry.classList.toggle('disabled', !cb.checked);
			});
		}

		/* Live GitHub URL preview as repo is typed */
		var repoInput = entry.querySelector('.github-repo-input');
		if (repoInput) {
			repoInput.addEventListener('input', function () {
				updateGithubPreview(entry);
			});
		}
	}

	/* Bind existing server-rendered entries */
	var entries = document.querySelectorAll('.webhook-entry');
	for (var e = 0; e < entries.length; e++) {
		bindEntry(entries[e]);
	}

	/* "Add Webhook" button */
	var addBtn = document.getElementById('add-webhook-btn');
	if (addBtn) {
		addBtn.addEventListener('click', function () {
			var list = document.getElementById('webhook-list');
			var i = webhookIndex++;
			var div = document.createElement('div');
			div.className = 'webhook-entry';
			div.setAttribute('data-index', i);
			div.setAttribute('data-active-type', 'generic');

			div.innerHTML =
				'<button type="button" class="remove-btn" title="Remove this webhook">&times;</button>' +
				'<div class="webhook-header">' +
					'<input type="checkbox" name="webhook_enabled_' + i + '" value="1" checked />' +
					'<select name="webhook_type_' + i + '" class="webhook-type-select">' +
						'<option value="generic">Generic Webhook</option>' +
						'<option value="github">GitHub Dispatch</option>' +
					'</select>' +
				'</div>' +
				'<div class="webhook-fields">' +
					'<div class="webhook-fields-generic">' +
						'<div class="field-row">' +
							'<label>URL</label>' +
							'<input type="url" name="webhook_url_' + i + '"' +
								' placeholder="https://tracecat.example.com/api/webhooks/..."' +
								' size="70" pattern="https://.*" />' +
						'</div>' +
					'</div>' +
					'<div class="webhook-fields-github">' +
						'<div class="field-row">' +
							'<label>Repository</label>' +
							'<input type="text" name="webhook_repo_' + i + '" class="github-repo-input"' +
								' placeholder="owner/repo" size="40" />' +
						'</div>' +
						'<div class="field-row">' +
							'<label>Token</label>' +
							'<input type="password" name="webhook_token_' + i + '"' +
								' placeholder="ghp_..." size="50" />' +
						'</div>' +
						'<div class="field-row">' +
							'<label>Event Type</label>' +
							'<input type="text" name="webhook_event_type_' + i + '"' +
								' value="threat-intel-scrape" placeholder="threat-intel-scrape" size="30" />' +
						'</div>' +
						'<div class="github-url-preview">' +
							'Target: ' + GH_URL_PREFIX + 'owner/repo/dispatches' +
						'</div>' +
					'</div>' +
				'</div>';

			list.appendChild(div);
			bindEntry(div);
		});
	}
})();
