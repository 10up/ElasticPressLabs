=== ElasticPress Labs ===
Contributors:      10up
Tags:              Elasticsearch, ElasticPress, search, boolean, Co-Authors Plus
Requires at least: 5.6
Tested up to:      6.4
Stable tag:        2.3.0
Requires PHP:      7.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A developer-focused interface to enabling experimental ElasticPress plugin features.

== Description ==

This plugin provides a developer-focused interface to commonly used filters without the need of being fully accessible and skipping the need of providing a streamlined user experience. It's meant to be an easy way to solve common issues without code changes.

ElasticPress Labs acts as an ElasticPress feature and registers its methods through the [ElasticPress Feature API](http://10up.github.io/ElasticPress/tutorial-feature-api.html). In this way the features added to this plugin will be immediately available in the ElasticPress interface.

This plugin provides a simple interface to enable and disable features.

== Screenshots ==

1. Settings to allow boolean search operators in search queries.
2. Settings to add Co-Authors Plus plugin support.
3. Settings to include or exclude meta key patterns.
4. Settings to change the version of the search algorithm between 3.4 and 3.5.

== Changelog ==


= 2.3.0 - 2024-03-04 =

This version introduces the new *External Content* feature. Check [our blog post](https://www.elasticpress.io/blog/2024/03/pew-research-center-external-files-as-a-source-for-your-search) for more info.

__Added:__

* New "External Content" feature. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).

__Changed:__

* Composer packages update. Props [@felipeelia](https://github.com/felipeelia).
* Compatibility with node v18. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).

= 2.2.0 - 2023-11-01 =

__Added:__

* Compatibility with ElasticPress 5.0.0. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).

__Changed:__

* Features do not autoactivate anymore. Props [@felipeelia](https://github.com/felipeelia).

__Security:__

* Bumped `@babel/traverse` from 7.19.3 to 7.23.2. Props [@dependabot](https://github.com/dependabot).


= 2.1.1 - 2023-09-28 =

__Added:__

* Integrate with WP update system to alert users about new versions. Props [@felipeelia](https://github.com/felipeelia).

__Changed:__

* Update the User Indexable files (bringing from the main plugin.) Props [@MARQAS](https://github.com/MARQAS).

__Removed:__

* Remove old Mapping files for Users. Props [@MARQAS](https://github.com/MARQAS).

__Fixed:__

* Boolean Operator (Not) not giving the expected result. Props [@MARQAS](https://github.com/MARQAS).
* Fatal Error in command line. Props [@MARQAS](https://github.com/MARQAS).
* PHP Warnings. Props [@felipeelia](https://github.com/felipeelia).

__Security:__

* Bumped `webpack` from 5.74.0 to 5.76.1. Props [@dependabot](https://github.com/dependabot).
* Bumped `tough-cookie` from 4.1.2 to 4.1.3. Props [@dependabot](https://github.com/dependabot).


= 2.1.0 - 2023-03-02 =

__Added:__

* Compatibility with the [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) add-on. Props [@ecaron](https://github.com/ecaron) and [@felipeelia](https://github.com/felipeelia).
* Users Feature (migrated from the main ElasticPress plugin). Props [@felipeelia](https://github.com/felipeelia), [@MARQAS](https://github.com/MARQAS), and [@burhandodhy](https://github.com/burhandodhy).
* Load PHP classes via `spl_autoload_register()`. Props [@burhandodhy](https://github.com/burhandodhy).

__Changed:__

* Code standards are now applied to the test suite too. Props [@felipeelia](https://github.com/felipeelia).
* Hide subfeatures if the required plugins are not activated. Props [@burhandodhy](https://github.com/burhandodhy).

__Fixed:__

* Adjusted the method used to determine if classes are loaded. Props [@ecaron](https://github.com/ecaron).
* Ensure feature classes are only loaded once. Props [@ecaron](https://github.com/ecaron).
* PHP Lint on PHP 8. Props [@felipeelia](https://github.com/felipeelia).

__Security:__

* Bumped `loader-utils` from 2.0.2 to 2.0.4. Props [@dependabot](https://github.com/dependabot).
* Bumped `json5` from 1.0.1 to 1.0.2. Props [@dependabot](https://github.com/dependabot).

= 2.0.0 - 2022-10-17 =

__Added:__

* Documentation updates. Props [@jeffpaul](https://github.com/jeffpaul).

__Changed:__

* Minimum requirements to run the plugin: ES (5.2-7.10), PHP 7.0+, WP 5.6+, and EP 4.3.0+. Props [@felipeelia](https://github.com/felipeelia).
* Assets are now built using 10up Toolkit. Props [@felipeelia](https://github.com/felipeelia).
* Search algorithm selection now makes use of ElasticPress classes. Props [@felipeelia](https://github.com/felipeelia).
* Small Refactor of BooleanSearchOperators and update of an ElasticPress filter usage. Props [@felipeelia](https://github.com/felipeelia).
* Meta Key Pattern: settings fields renamed and HTML fix. Props [@felipeelia](https://github.com/felipeelia).
* Co-authors Plus description and small refactor. Props [@felipeelia](https://github.com/felipeelia).

__Fixed:__

* Boolean Search not working. Props [@burhandodhy](https://github.com/burhandodhy).
* Link to Sync Page in the Meta Key Pattern subfeature. Props [@MARQAS](https://github.com/MARQAS) and [@felipeelia](https://github.com/felipeelia).
* Undefined notice. Props [@oscarssanchez](https://github.com/oscarssanchez).
* Composer v2 compatibility and unit tests. Props [@felipeelia](https://github.com/felipeelia).
* Changes made in the main feature not being saved. Props [@felipeelia](https://github.com/felipeelia).
* Notices related to undefined array indexes. Props [@felipeelia](https://github.com/felipeelia).

__Removed:__

* Unused CSS file and JavaScript code. Props [@felipeelia](https://github.com/felipeelia).

= 1.2.0 - 2021-09-01 =

__Added:__
* Boolean Search Operators Feature. Props [@moraleida](https://github.com/moraleida), [@Rahmon](https://github.com/Rahmon), and [@felipeelia](https://github.com/felipeelia).

= 1.1.0 - 2021-07-27 =

__Added:__

* Integration with [Co-Authors Plus](https://wordpress.org/plugins/co-authors-plus/). Props [@dinhtungdu](https://github.com/dinhtungdu), [@Rahmon](https://github.com/Rahmon), and [@mbanusic](https://github.com/mbanusic).

= 1.0.0 - 2021-02-09 =
* Initial plugin release.
