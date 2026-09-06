describe('saved view editor', () => {
	beforeEach(() => {
		jest.resetModules();
		document.body.innerHTML =
			'<form class="nat-view-editor"><input data-name><input data-search><input name="view"><div class="nat-view-columns"></div><p role="status"></p></form>';
		const form = document.querySelector('form');
		form.dataset.view = JSON.stringify({
			version: 1,
			columns: [
				{ key: 'score', label: 'Score', width: '', visible: true },
				{ key: 'rating', label: 'Rating', width: '', visible: true },
			],
		});
		require('../../plugin/assets/views.js');
	});
	test('button reorder preserves focus and serializes the new order', () => {
		const form = document.querySelector('form');
		form.querySelector('[data-direction="Down"]').click();
		expect(document.activeElement.closest('[data-key]').dataset.key).toBe(
			'score'
		);
		form.dispatchEvent(new Event('submit', { cancelable: true }));
		const view = JSON.parse(form.querySelector('[name="view"]').value);
		expect(view.columns.map((column) => column.key)).toEqual([
			'rating',
			'score',
		]);
		expect(view.visibility).toBe('personal');
		expect(form.querySelector('[role="status"]').textContent).toContain(
			'position 2'
		);
	});
	test('search hides nonmatching fields without discarding them', () => {
		const search = document.querySelector('[data-search]');
		search.value = 'rating';
		search.dispatchEvent(new Event('input'));
		expect(document.querySelector('[data-key="score"]').hidden).toBe(true);
		expect(document.querySelector('[data-key="rating"]').hidden).toBe(
			false
		);
	});
	test('reordering retains the active search and its matching focused row', () => {
		const search = document.querySelector('[data-search]');
		search.value = 'rating';
		search.dispatchEvent(new Event('input'));
		document
			.querySelector('[data-key="rating"] [data-direction="Up"]')
			.click();
		expect(search.value).toBe('rating');
		expect(document.querySelector('[data-key="score"]').hidden).toBe(true);
		expect(document.querySelector('[data-key="rating"]').hidden).toBe(
			false
		);
		expect(document.activeElement.closest('[data-key]').dataset.key).toBe(
			'rating'
		);
		search.value = '';
		search.dispatchEvent(new Event('input'));
		expect(document.querySelector('[data-key="score"]').hidden).toBe(false);
	});
});
