=== ElasticPress Labs ===
Contributors:      10up
Tags:              Elasticsearch, ElasticPress, search, boolean, Co-Authors Plus
Requires at least: 6.2
Tested up to:      7.1
Stable tag:        2.6.0
Requires PHP:      7.4
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A developer-focused interface to enabling experimental ElasticPress plugin features.

== Description ==

This plugin provides a developer-focused interface to commonly used filters without the need of being fully accessible and skipping the need of providing a streamlined user experience. It's meant to be an easy way to solve common issues without code changes.

ElasticPress Labs acts as an ElasticPress feature and registers its methods through the [ElasticPress Feature API](http://10up.github.io/ElasticPress/tutorial-feature-api.html). In this way the features added to this plugin will be immediately available in the ElasticPress interface.

This plugin provides a simple interface to enable and disable features.

== Screenshots ==

1. Vector Embeddings: generate embeddings with OpenAI or an external process.
2. Vector Embeddings settings: choose which posts are embedded and which fields are used.
3. Semantic Search: search by meaning, using kNN queries.
4. AI Search Summary: show an AI-generated summary of the search results.
5. Boolean Search Operators: allow boolean operators in search queries.
6. Search Algorithm Version: choose between default, 3.5, 4.0, and the kNN algorithms added by Semantic Search.
7. Meta Key Pattern: include or exclude meta keys with regular expressions.
8. External Content: index the content of paths or URLs stored in meta keys.
9. Geo Location: search posts based on their location.
10. Users: improve user search relevancy and query performance.
11. Search Templates: use Elasticsearch queries stored in ElasticPress.io.
12. Search Templates management: create and edit the templates stored in ElasticPress.io.
13. Co-Authors Plus: support for the Co-Authors Plus plugin.
14. WooCommerce Admin Subscription Search: search subscriptions in the admin.

== Frequently Asked Questions ==

= Where do I report security bugs found in this plugin? =

Please report security bugs found in the source code of the ElasticPress Labs plugin through the [Patchstack Vulnerability Disclosure  Program](https://patchstack.com/database/vdp/8c7c16e5-d9f1-48d7-9d44-1ec15688e8ed).  The Patchstack team will assist you with verification, CVE assignment, and notify the developers of this plugin.

== Changelog ==

= 2.6.0 - 2026-XX-XX =

**Note that this release bumps the minimum required WordPress version to 6.2.** AI Chat and the "Test connection" buttons need ElasticPress 5.4.0 or later (AI Chat also needs WordPress 7.0). On older ElasticPress versions, AI Chat reports that it needs 5.4.0 and stays off, and the "Test connection" buttons do not show. Vector Embeddings works on older versions too, but looks up the stored vectors of a batch with one request per post.

__Added:__

* [AI Chat] New AI Chat feature: answers questions from the site's content with cited sources, answer checks, chat bots, and a conversation history. Requires WordPress 7.0 and ElasticPress 5.4. Props [@felipeelia](https://github.com/felipeelia), [@tott](https://github.com/tott), and [@MomoFadaly](https://github.com/MomoFadaly).
* [AI Chat] "AI-generated query" search method, where a model writes the search query, checked before it runs. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] [AI Search Summary] "Test connection" buttons. Requires ElasticPress 5.4. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Reuse the vectors of unchanged chunks when a post is indexed again, and only embed new or changed chunks. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Add the missing fields to existing indices when the feature is activated or updated, without a full sync. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Semantic Search] The best matching chunks of each post are returned as `$post->matched_chunks`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* Upgrade routines that run once per new ElasticPress Labs version, and the `wp elasticpress-labs run-upgrades` WP-CLI command. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).

__Changed:__

* Bumped the minimum required WordPress version to 6.2, the same as ElasticPress. Props [@felipeelia](https://github.com/felipeelia).
* [Vector Embeddings] Content is split into chunks at paragraph and section boundaries, with a context line (post title and heading path) at the start of each chunk, and each chunk's text and type are stored. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] The embedded text keeps tables (as Markdown), headings, and the separation between elements, decodes HTML entities instead of removing them, and leaves out scripts and styles. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] The `ep_embeddings_is_embeddable` filter receives the computed value instead of `null`, and a callback that returns `null` now leaves the post out. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Semantic Search] Post search results no longer include the Vector Embeddings chunks in `_source`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).

__Fixed:__

* [Vector Embeddings] Indexing Criteria (taxonomy and meta rules) were not applied in automatic mode. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Password-protected posts were embedded. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Posts indexed while the feature was temporarily disabled after repeated failures lost their stored vectors. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Memory use during a sync of posts with blocks that use the Interactivity API. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Array meta values were embedded as the word "Array". Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] Shortcodes in meta values ran when a post was indexed. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* Requests to AI services stayed in memory for the whole process, for the query log, even when query logging was off. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).

__Security:__

* The API key of AI requests is no longer shown in the query log or stored with failed queries. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* Bumped `follow-redirects` from 1.16.0 to 1.16.1, `simple-git` from 3.33.0 to 4.0.2, and `@wordpress/env` from 11.17.0 to 12.0.0. Props [@dependabot](https://github.com/dependabot).

__Developer:__

* [AI Chat] New hooks, among them `ep_chat_register_gate`, `ep_chat_capabilities`, `ep_chat_sync_deadline`, `ep_chat_register_abilities`, and `ep_chat_pipeline_exception`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Vector Embeddings] New `ep_embeddings_tables_as_markdown`, `ep_embeddings_inline_tags`, `ep_embeddings_chunking_strategy`, `ep_embeddings_chunk_context`, `ep_embeddings_store_chunk_text`, and `ep_embeddings_reuse_group_size` filters, and a second argument for `ep_embeddings_chunk`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Semantic Search] New `ep_semantic_search_matched_chunks` and `ep_semantic_search_matched_chunk_types` filters. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* [Search Algorithm] Search algorithms with a public `$hidden` property set to `true` stay registered but are not offered on the Search Algorithm feature, and the new `ep_labs_search_algorithm_hidden` filter hides or shows one. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* New `ep_labs_connection_test_url_allowed` and `ep_labs_connection_test_interval` filters. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* The e2e setup builds ElasticPress with the Node version in its `.nvmrc`. Props [@felipeelia](https://github.com/felipeelia).
* The e2e WP-CLI wrapper no longer loads the wp-env config, and calls api.wordpress.org, on every command. Props [@felipeelia](https://github.com/felipeelia).

= 2.5.2 - 2026-10-14 =

__Fixed:__

* Avoid loading ElasticPress translations before the `init` action when the Co-Authors Plus feature is registered. Props [@rickalee](https://github.com/rickalee).
* [Vector Embeddings] Chunk overlap only applied to the first chunks of a text. Props [@felipeelia](https://github.com/felipeelia).
* [Search Templates] Fatal error on the Search Templates screen when the request for the default template fails. Props [@felipeelia](https://github.com/felipeelia).

__Security:__

* [Vector Embeddings] Only users who can manage ElasticPress can save the Vector Embeddings settings, which are now validated against the settings the screen saves. Props [@cazy8](https://github.com/cazy8), [@jeffpaul](https://github.com/jeffpaul), and [@felipeelia](https://github.com/felipeelia).
* Updated Node.js to v24 and npm packages, including `10up-toolkit` 7. Props [@ZacharyRener](https://github.com/ZacharyRener).
* Pinned `fast-uri` to 3.1.8 (CVE-2026-86472). Props [@rickalee](https://github.com/rickalee).
* Bumped `postcss` from 8.5.6 to 8.5.14. Props [@dependabot](https://github.com/dependabot).
* Bumped `wp-coding-standards/wpcs` from 3.1.0 to 3.4.1. Props [@dependabot](https://github.com/dependabot).
* Bumped `ws`, `js-yaml`, `brace-expansion`, `http-proxy-middleware`, `immutable`, `svgo`, `compression`, `proxy-addr`, `websocket-driver`, and other npm packages. Props [@dependabot](https://github.com/dependabot).

__Developer:__

* Fixed failing unit tests. Props [@ZacharyRener](https://github.com/ZacharyRener).

= 2.5.1 - 2026-05-11 =

__Added:__

* [Semantic Search] Message about incompatibility with Autosuggest and Instant Results. Props [@felipeelia](https://github.com/felipeelia).

__Changed:__

* [Semantic Search] Adjust when search algorithms are displayed. Props [@felipeelia](https://github.com/felipeelia).
* Disable AI features after 3 failures. Props [@felipeelia](https://github.com/felipeelia).
* Avoid excessive database writes when logging AI feature failures. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott).
* Move Co-Authors Plus and WooCommerce Subscription Search settings to the third-party plugins section. Props [@burhandodhy](https://github.com/burhandodhy).

__Fixed:__

* AI Features being automatically disabled when ES is unavailable. Props [@felipeelia](https://github.com/felipeelia).
* Vector embeddings screen: Incomplete text and wrong `<title>`. Props [@felipeelia](https://github.com/felipeelia).
* Vertical spacing between embedding field checkboxes. Props [@burhandodhy](https://github.com/burhandodhy).
* Typo in `useVectorEmbeddingSettings`. Props [@burhandodhy](https://github.com/burhandodhy).
* `version_compare()` deprecation when passing null. Props [@burhandodhy](https://github.com/burhandodhy).
* Removed obsolete TinyMCE editor stylesheet and unused `script_loader_tag` filter. Props [@burhandodhy](https://github.com/burhandodhy).
* Deprecated `RangeControl` default size, wrap checkboxes in `VStack`, and rename `enablefieldsIndexing` to `enableFieldsIndexing`. Props [@burhandodhy](https://github.com/burhandodhy).

__Security:__

* Updated composer and node packages. Props [@felipeelia](https://github.com/felipeelia).
* npm audit dependency updates. Props [@felipeelia](https://github.com/felipeelia).
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
