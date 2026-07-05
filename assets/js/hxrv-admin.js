/**
 * HXRV admin — copy / download helpers for the export view.
 * Download is built client-side from the textarea content via Blob,
 * so no server streaming is involved.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var source = document.getElementById('hxrv-export-md');
		var copyBtn = document.getElementById('hxrv-copy-md');
		var dlBtn = document.getElementById('hxrv-download-md');

		if (!source) return;

		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var done = function () {
					var original = copyBtn.textContent;
					copyBtn.textContent = copyBtn.getAttribute('data-copied') || 'Copied!';
					setTimeout(function () { copyBtn.textContent = original; }, 1600);
				};

				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(source.value).then(done);
				} else {
					// http:// local environments: clipboard API unavailable.
					source.focus();
					source.select();
					try { document.execCommand('copy'); } catch (e) { /* noop */ }
					window.getSelection && window.getSelection().removeAllRanges();
					done();
				}
			});
		}

		if (dlBtn) {
			dlBtn.addEventListener('click', function () {
				var blob = new Blob([source.value], { type: 'text/markdown;charset=utf-8' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = dlBtn.getAttribute('data-filename') || 'hxrv-review.md';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			});
		}
	});
})();
