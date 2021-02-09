# Development

## Setting up a local development environment

It's highly recommended to follow the instructions on the page
[Tutorial: Development](http://10up.github.io/ElasticPress/tutorial-development.html) to
set up your local development environment.

## Adding a new feature

ElasticPress Labs follows the ElasticPress
[Feature API](http://10up.github.io/ElasticPress/tutorial-feature-api.html),
that is, to add a new feature you should add a file in the folder
`includes/classes/Feature` and extend the Feature class. Here is a code example:

```php
<?php
/**
 * Example Feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Example class.
 */
class Example extends \ElasticPress\Feature {
	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'example_feature';

		$this->title = esc_html__( 'Example Feature', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [
			'my_setting' => '',
		];

		parent::__construct();
	}

	/**
	 * Output feature box summary.
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Example Feature Title.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'Example Feature description.', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Setup your feature functionality.
	 * Use this method to hook your feature functionality to ElasticPress or WordPress.
	 */
	public function setup() {}

	/**
	 * Display field settings on the Dashboard.
	 */
	public function output_feature_box_settings() {
		$settings = $this->get_settings();

		if ( ! $settings ) {
			$settings = [];
		}

		$settings = wp_parse_args( $settings, $this->default_settings );

		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status">
				<label for="feature_my_feature_setting">
					<?php esc_html_e( 'Your custom field', 'elasticpress-labs' ); ?>
				</label>
			</div>
			<div class="input-wrap">
				<input
					type="text"
					class="setting-field"
					id="feature_my_feature_setting"
					value="<?php echo empty( $settings['my_feature_setting'] ) ? '' : esc_attr( $settings['my_feature_setting'] ); ?>"
					data-field-name="my_feature_setting">
				<p class="field-description">
					<?php esc_html_e( 'Your custom field description.', 'elasticpress-labs' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
```

Doing that your new feature will be available inside the
ElasticPress Labs feature to enable or disable.

## Release instructions

1. Branch: Starting from develop, cut a release branch named
`release/X.Y.Z` for your changes.
2. Version bump: Bump the version number in `elasticpressLabs.php`,
`readme.txt`, `package-lock.json`, `package.json`, and `ElasticPressLabs.pot`
if it does not already reflect the version being released.
3. Changelog: Add/update the changelog in `readme.txt`.
4. Translations: Update the .pot.
5. Readme updates: Make any other readme changes as necessary in `README.md`.
6. Tests: Run the tests with the command `npm run test`.
7. Build: Run the command `npm run build-release`.
8. Merge: Make a non-fast-forward merge from your release branch to `develop`, then do the same for `develop` into `master` (`git checkout master && git merge --no-ff develop`). `master` contains the stable development version.
9. Push: Push your `master` branch to GitLab (e.g. `git push origin master`).
