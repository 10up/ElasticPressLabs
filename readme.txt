=== ElasticPress Labs ===
Contributors:      10up
Tags:              Elasticsearch, ElasticPress, search, boolean, Co-Authors Plus
Requires at least: 5.6
Tested up to:      6.0
Stable tag:        2.0.0
Requires PHP:      7.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A developer-focused interface to enabling experimental ElasticPress plugin features.

== Description ==

This plugin provides a developer-focused interface to commonly used filters without the need of being fully accessible and skipping the need of providing a streamlined user experience. It's meant to be an easy way to solve common issues without code changes.

ElasticPress Labs acts as an ElasticPress feature and registers its methods through the [ElasticPress Feature API](http://10up.github.io/ElasticPress/tutorial-feature-api.html). In this way the features added to this plugin will be immediately available in the ElasticPress interface.

This plugin provides a simple interface to enable and disable features.

== Screenshots ==

1. ElasticPress Labs options section within ElasticPress Features settings screen, showing with `Status: Enabled`.
2. Settings to allow boolean search operators in search queries.
3. Settings to add Co-Authors Plus plugin support.
4. Settings to include or exclude meta key patterns.
5. Settings to change the version of the search algorithm between 3.4 and 3.5.

== Changelog ==

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
