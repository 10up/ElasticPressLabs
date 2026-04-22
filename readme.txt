=== ElasticPress Labs ===
Contributors:      10up
Tags:              Elasticsearch, ElasticPress, search, boolean, Co-Authors Plus
Requires at least: 6.0
Tested up to:      7.0
Stable tag:        2.5.1
Requires PHP:      7.4
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
5. Settings to index external content.

== Frequently Asked Questions ==

= Where do I report security bugs found in this plugin? =

Please report security bugs found in the source code of the undefined plugin through the [Patchstack Vulnerability Disclosure  Program](https://patchstack.com/database/vdp/8c7c16e5-d9f1-48d7-9d44-1ec15688e8ed).  The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Changelog ==

= 2.5.1 - 2026-XX-XX =

__Added:__

* [Semantic Search] Message about incompatibility with Autosuggest and Instant Results. Props [@felipeelia](https://github.com/felipeelia).

__Changed:__

* [Semantic Search] Adjust when search algorithms are displayed. Props [@felipeelia](https://github.com/felipeelia).
* Disable AI features after 3 failures. Props [@felipeelia](https://github.com/felipeelia).

__Fixed:__

* AI Features being automatically disabled when ES is unavailable. Props [@felipeelia](https://github.com/felipeelia).
* Vector embeddings screen: Incomplete text and wrong `<title>`. Props [@felipeelia](https://github.com/felipeelia).

__Security:__

* Updated composer and node packages. Props [@felipeelia](https://github.com/felipeelia).
* Bumped `lodash` from 4.17.21 to 4.18.1. Props [@dependabot](https://github.com/dependabot).
* Bumped `phpunit/phpunit` from 9.6.22 to 9.6.33. Props [@dependabot](https://github.com/dependabot).
* Bumped `webpack` from 5.100.2 to 5.105.0. Props [@dependabot](https://github.com/dependabot).
* Bumped `qs` from 6.14.1 to 6.14.2. Props [@dependabot](https://github.com/dependabot).
* Bumped `immutable` from 5.1.3 to 5.1.5. Props [@dependabot](https://github.com/dependabot).
* Bumped `simple-git` from 3.28.0 to 3.33.0 and `svgo` from 3.3.2 to 3.3.3. Props [@dependabot](https://github.com/dependabot).
* Bumped `flatted` from 3.3.3 to 3.4.2 and `picomatch` from 2.3.1 to 2.3.2. Props [@dependabot](https://github.com/dependabot).

__Developer:__

* Add Patchstack security-reporting FAQ. Props [@jeffpaul](https://github.com/jeffpaul).
* Set explicit `permissions` on GitHub Actions workflows. Props [@jeffpaul](https://github.com/jeffpaul).
* Add `ep_user_sync_kill` filter in Users `action_queue_meta_sync`. Props [@burhandodhy](https://github.com/burhandodhy) and [@yarovikov](https://github.com/yarovikov).

= 2.5.0 - 2025-11-05 =

__Added:__

* New Vector Embeddings, Semantic Search, and AI Summary Search features. Props [@tott](https://github.com/tott), [@felipeelia](https://github.com/felipeelia), [@psorensen](https://github.com/psorensen), [@gsarig](https://github.com/gsarig), [@ZacharyRener](https://github.com/ZacharyRener), [@burhandodhy](https://github.com/burhandodhy), and [@oscarssanchezz](https://github.com/oscarssanchezz).
* New `ep_user_pre_query_db_results` and `ep_user_query_db_sql` filters in Users `query_db` method. Props [@burhandodhy](https://github.com/burhandodhy), [@felipeelia](https://github.com/felipeelia), and [@yarovikov](https://github.com/yarovikov).
* Support for include, lower_limit_object_id, and upper_limit_object_id to User Indexable. Props [@burhandodhy](https://github.com/burhandodhy).
* Support for searching posts by Co-Author. Props [@burhandodhy](https://github.com/burhandodhy).

__Fixed:__

* Geolocation infinte loop due to cache. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).
* Autoload fatal error when the plugin is installed via composer. Props [@felipeelia](https://github.com/felipeelia), [@burhandodhy](https://github.com/burhandodhy), and [@gsarig](https://github.com/gsarig).
* Ordering user queries by meta key/value. Props [@mphillips](https://github.com/mphillips).

__Security:__

* Overwrite package @babel/runtime coming from core packages due to a vulnerability. Props [@hugosolar](https://github.com/hugosolar).
* Bumped `tar-fs` from 2.1.1 to 3.1.1. Props [@dependabot](https://github.com/dependabot).
* Bumped `http-proxy-middleware` from 2.0.7 to 2.0.9. Props [@dependabot](https://github.com/dependabot).
* Removed `tmp`. Props [@dependabot](https://github.com/dependabot).

__Developer:__

* Migrated e2e tests from Cypress to Playwright. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).
* Run e2e tests on Elasticsearch 9. Props [@felipeelia](https://github.com/felipeelia)

= 2.4.0 - 2025-03-26 =

__Added:__

* Geo Location Feature. Props [@burhandodhy](https://github.com/burhandodhy) and [@felipeelia](https://github.com/felipeelia).
* Search Templates feature. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy).

__Changed:__

* Minimum requirements to run the plugin: PHP 7.4+, WP 6.0+, and EP 5.2.0+. Props [@felipeelia](https://github.com/felipeelia).

__Fixed:__

* PHP Notice: Function _load_textdomain_just_in_time was called incorrectly. Props [@burhandodhy](https://github.com/burhandodhy).
* Editor deprecated warnings. Props [@burhandodhy](https://github.com/burhandodhy).

__Developer:__

* Fixed PR links in the changelog. Props [@felipeelia](https://github.com/felipeelia).
* Add e2e tests foundation. Props [@felipeelia](https://github.com/felipeelia).
* Fix unit tests + small tweaks in Husky and Cypress setup. Props [@felipeelia](https://github.com/felipeelia).

= 2.3.1 - 2024-12-11 =

__Added:__

* ElasticPress as a plugin dependency. Props [@jeffpaul](https://github.com/jeffpaul).

__Changed:__

* Bumped actions/upload-artifact from v3 to v4. Props [@iamdharmesh](https://github.com/iamdharmesh).
* Update versions of GitHub Actions, composer, and node packages. Props [@felipeelia](https://github.com/felipeelia).

__Fixed:__

* Textdomain in the Users feature. Props [@burhandodhy](https://github.com/burhandodhy).

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
