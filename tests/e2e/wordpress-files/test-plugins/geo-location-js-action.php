<?php
/**
 * Plugin Name: GeoLocation - Use JS Action
 * Version:     1.0.0
 * Author:      10up Inc.
 * License:     GPLv2 or later
 *
 * @package ElasticPress_Tests_E2e
 */

/**
 * Use the epLabs.GeoLocation.currentPositionError action to display an error message.
 */
add_action(
	'wp_footer',
	function (): void {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const displayError = (error) => {
					const errorElement = document.createElement('div');
					errorElement.classList.add('ep-geo-location-error');
					errorElement.textContent = error.message;
					document.body.appendChild(errorElement);
				}

				wp.hooks.addAction('epLabs.GeoLocation.currentPositionError', 'ep-test', displayError);
			});
		</script>
		<?php
	}
);
