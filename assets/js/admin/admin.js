/**
 * As ElasticPress does not have any method to intercept the feature save process,
 * we change the window.fetch object to make the page refresh after the AJAX call.
 */
const featuresEl = document.querySelector('.ep-features');
if (featuresEl) {
	const originalFetch = window.fetch;
	const newFetch = async (input, options) => {
		const response = await originalFetch(input, options);
		window.location.reload();
		return response; // just to avoid a console error.
	};
	const onSubmit = (event) => {
		const form = event.target;
		window.fetch = form.feature.value === 'elasticpress_labs' ? newFetch : originalFetch;
	};
	featuresEl.addEventListener('submit', onSubmit, { capture: true });
}
