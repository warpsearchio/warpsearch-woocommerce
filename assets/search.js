(function () {
	'use strict';
	function init(root) {
		var input = root.querySelector('.warpsearch-input');
		var sort = root.querySelector('.warpsearch-sort');
		var list = root.querySelector('.warpsearch-results');
		var status = root.querySelector('.warpsearch-status');
		var more = root.querySelector('.warpsearch-more');
		var page = 1, timer = null, seq = 0;

		function load(append) {
			var my = ++seq;
			var q = input.value.trim();
			if (!q) { list.innerHTML = ''; status.textContent = ''; more.hidden = true; return; }
			var url = WarpSearchWC.endpoint + (WarpSearchWC.endpoint.indexOf('?') < 0 ? '?' : '&') +
				'q=' + encodeURIComponent(q) + '&sort=' + encodeURIComponent(sort.value) + '&page=' + page;
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
				.then(function (d) {
					if (my !== seq) { return; } // a newer request superseded this one
					if (append) { list.insertAdjacentHTML('beforeend', d.html); } else { list.innerHTML = d.html; }
					status.textContent = WarpSearchWC.results.replace('%d', d.total);
					more.hidden = page >= d.pages;
				})
				.catch(function () { status.textContent = WarpSearchWC.unavailable; });
		}
		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () { page = 1; load(false); }, 250);
		});
		sort.addEventListener('change', function () { page = 1; load(false); });
		more.addEventListener('click', function () { page++; load(true); });
		if (input.value) { load(false); }
	}
	document.querySelectorAll('[data-warpsearch]').forEach(init);
})();
