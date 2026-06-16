/**
 * Remove crossorigin="anonymous" from <img> tags in the admin media library.
 *
 * WordPress adds crossorigin to external images in the media grid so it can use
 * them with canvas. When media is stored on a different domain (e.g. a custom
 * R2 public domain) and that domain does not send CORS headers, the images fail
 * to load. Stripping the attribute lets the browser load them without CORS.
 */
(function () {
	'use strict';

	function removeCrossOrigin(node) {
		if (node.tagName === 'IMG' && node.hasAttribute('crossorigin')) {
			node.removeAttribute('crossorigin');
		}
	}

	function processNode(node) {
		if (!node) {
			return;
		}
		removeCrossOrigin(node);
		if (node.querySelectorAll) {
			node.querySelectorAll('img[crossorigin]').forEach(removeCrossOrigin);
		}
	}

	// Process images already in the DOM.
	document.querySelectorAll('img[crossorigin]').forEach(removeCrossOrigin);

	// Watch for new images added dynamically (Backbone media grid).
	if (window.MutationObserver) {
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(processNode);
			});
		}).observe(document.body, { childList: true, subtree: true });
	}
})();
