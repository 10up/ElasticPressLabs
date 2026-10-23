# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/).

## [Unreleased]

<!--
### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security
### Developer
-->

## [2.6.0] - 2026-XX-XX

**Note that this release bumps the minimum required WordPress version to 6.2.** Some features need ElasticPress 5.4.0 or later:
* AI Chat (it also needs WordPress 7.0) and the "Test connection" buttons. On older ElasticPress versions, their settings still save, but the buttons and the grouped fields of the settings screens do not show.
* Vector Embeddings looks up the stored vectors of a whole batch of posts in one request. On older ElasticPress versions, it still reuses them, but with one request per post.

### Added
* [AI Chat] New AI Chat feature: answers questions from the site's content with cited sources, answer checks, chat bots, and a conversation history. Requires WordPress 7.0 and ElasticPress 5.4. Props [@felipeelia](https://github.com/felipeelia), [@tott](https://github.com/tott), and [@MomoFadaly](https://github.com/MomoFadaly) via [#231](https://github.com/10up/ElasticPressLabs/pull/231).
* [AI Chat] "AI-generated query" search method, where a model writes the search query, checked before it runs. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#232](https://github.com/10up/ElasticPressLabs/pull/232).
* [Vector Embeddings] [AI Search Summary] "Test connection" buttons. Requires ElasticPress 5.4. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#229](https://github.com/10up/ElasticPressLabs/pull/229).
* [Vector Embeddings] Reuse the vectors of unchanged chunks when a post is indexed again, and only embed new or changed chunks. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#226](https://github.com/10up/ElasticPressLabs/pull/226).
* [Vector Embeddings] Add the missing fields to existing indices when the feature is activated or updated, without a full sync. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#223](https://github.com/10up/ElasticPressLabs/pull/223).
* [Semantic Search] The best matching chunks of each post are returned as `$post->matched_chunks`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#227](https://github.com/10up/ElasticPressLabs/pull/227).
* Upgrade routines that run once per new ElasticPress Labs version, and the `wp elasticpress-labs run-upgrades` WP-CLI command. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#223](https://github.com/10up/ElasticPressLabs/pull/223).

### Changed
* Bumped the minimum required WordPress version to 6.2, the same as ElasticPress. Props [@felipeelia](https://github.com/felipeelia) via [#235](https://github.com/10up/ElasticPressLabs/pull/235).
* [Vector Embeddings] Content is split into chunks at paragraph and section boundaries, with a context line (post title and heading path) at the start of each chunk, and each chunk's text and type are stored. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#225](https://github.com/10up/ElasticPressLabs/pull/225).
* [Vector Embeddings] The embedded text keeps tables (as Markdown), headings, and the separation between elements, decodes HTML entities instead of removing them, and leaves out scripts and styles. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#224](https://github.com/10up/ElasticPressLabs/pull/224).
* [Vector Embeddings] The `ep_embeddings_is_embeddable` filter receives the computed value instead of `null`, and a callback that returns `null` now leaves the post out. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#221](https://github.com/10up/ElasticPressLabs/pull/221).
* [Semantic Search] Post search results no longer include the Vector Embeddings chunks in `_source`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#227](https://github.com/10up/ElasticPressLabs/pull/227).

### Fixed
* [Vector Embeddings] Indexing Criteria (taxonomy and meta rules) were not applied in automatic mode. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#221](https://github.com/10up/ElasticPressLabs/pull/221).
* [Vector Embeddings] Password-protected posts were embedded. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#221](https://github.com/10up/ElasticPressLabs/pull/221).
* [Vector Embeddings] Posts indexed while the feature was temporarily disabled after repeated failures lost their stored vectors. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#228](https://github.com/10up/ElasticPressLabs/pull/228).
* [Vector Embeddings] Memory use during a sync of posts with blocks that use the Interactivity API. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#225](https://github.com/10up/ElasticPressLabs/pull/225).
* [Vector Embeddings] Array meta values were embedded as the word "Array". Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#225](https://github.com/10up/ElasticPressLabs/pull/225).
* [Vector Embeddings] Shortcodes in meta values ran when a post was indexed. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#225](https://github.com/10up/ElasticPressLabs/pull/225).
* Requests to AI services stayed in memory for the whole process, for the query log, even when query logging was off. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#220](https://github.com/10up/ElasticPressLabs/pull/220).

### Security
* The API key of AI requests is no longer shown in the query log or stored with failed queries. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#220](https://github.com/10up/ElasticPressLabs/pull/220).
* Bumped `follow-redirects` from 1.16.0 to 1.16.1, `simple-git` from 3.33.0 to 4.0.2, and `@wordpress/env` from 11.17.0 to 12.0.0. Props [@dependabot](https://github.com/dependabot) via [#234](https://github.com/10up/ElasticPressLabs/pull/234).

### Developer
* [AI Chat] New hooks, among them `ep_chat_register_gate`, `ep_chat_capabilities`, `ep_chat_sync_deadline`, `ep_chat_register_abilities`, and `ep_chat_pipeline_exception`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#231](https://github.com/10up/ElasticPressLabs/pull/231).
* [Vector Embeddings] New `ep_embeddings_tables_as_markdown`, `ep_embeddings_inline_tags`, `ep_embeddings_chunking_strategy`, `ep_embeddings_chunk_context`, `ep_embeddings_store_chunk_text`, and `ep_embeddings_reuse_group_size` filters, and a second argument for `ep_embeddings_chunk`. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#224](https://github.com/10up/ElasticPressLabs/pull/224), [#225](https://github.com/10up/ElasticPressLabs/pull/225), and [#226](https://github.com/10up/ElasticPressLabs/pull/226).
* [Semantic Search] New `ep_semantic_search_matched_chunks` and `ep_semantic_search_matched_chunk_types` filters. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#227](https://github.com/10up/ElasticPressLabs/pull/227).
* [Search Algorithm] Search algorithms with a public `$hidden` property set to `true` stay registered but are not offered on the Search Algorithm feature, and the new `ep_labs_search_algorithm_hidden` filter hides or shows one. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#222](https://github.com/10up/ElasticPressLabs/pull/222).
* New `ep_labs_connection_test_url_allowed` and `ep_labs_connection_test_interval` filters. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#229](https://github.com/10up/ElasticPressLabs/pull/229).
* The e2e setup builds ElasticPress with the Node version in its `.nvmrc`. Props [@felipeelia](https://github.com/felipeelia) via [#233](https://github.com/10up/ElasticPressLabs/pull/233).
* The e2e WP-CLI wrapper no longer loads the wp-env config, and calls api.wordpress.org, on every command. Props [@felipeelia](https://github.com/felipeelia) via [#236](https://github.com/10up/ElasticPressLabs/pull/236).

## [2.5.2] - 2026-10-14

### Fixed
* Avoid loading ElasticPress translations before the `init` action when the Co-Authors Plus feature is registered. Props [@rickalee](https://github.com/rickalee) via [#211](https://github.com/10up/ElasticPressLabs/pull/211).
* [Vector Embeddings] Chunk overlap only applied to the first chunks of a text. Props [@felipeelia](https://github.com/felipeelia) via [#208](https://github.com/10up/ElasticPressLabs/pull/208).
* [Search Templates] Fatal error on the Search Templates screen when the request for the default template fails. Props [@felipeelia](https://github.com/felipeelia) via [#219](https://github.com/10up/ElasticPressLabs/pull/219).

### Security
* [Vector Embeddings] Only users who can manage ElasticPress can save the Vector Embeddings settings, which are now validated against the settings the screen saves. Props [@cazy8](https://github.com/cazy8), [@jeffpaul](https://github.com/jeffpaul), and [@felipeelia](https://github.com/felipeelia) via [GHSA-2p6m-2xvh-9m2p](https://github.com/10up/ElasticPressLabs/security/advisories/GHSA-2p6m-2xvh-9m2p).
* Updated Node.js to v24 and npm packages, including `10up-toolkit` 7. Props [@ZacharyRener](https://github.com/ZacharyRener) via [#214](https://github.com/10up/ElasticPressLabs/pull/214).
* Pinned `fast-uri` to 3.1.8 (CVE-2026-86472). Props [@rickalee](https://github.com/rickalee) via [#213](https://github.com/10up/ElasticPressLabs/pull/213).
* Bumped `postcss` from 8.5.6 to 8.5.14. Props [@dependabot](https://github.com/dependabot) via [#200](https://github.com/10up/ElasticPressLabs/pull/200).
* Bumped `wp-coding-standards/wpcs` from 3.1.0 to 3.4.1. Props [@dependabot](https://github.com/dependabot) via [#207](https://github.com/10up/ElasticPressLabs/pull/207).
* Bumped `ws`, `js-yaml`, `brace-expansion`, `http-proxy-middleware`, `immutable`, `svgo`, `compression`, `proxy-addr`, `websocket-driver`, and other npm packages. Props [@dependabot](https://github.com/dependabot) via [#215](https://github.com/10up/ElasticPressLabs/pull/215).

### Developer
* Fixed failing unit tests. Props [@ZacharyRener](https://github.com/ZacharyRener) via [#206](https://github.com/10up/ElasticPressLabs/pull/206).

## [2.5.1] - 2026-05-11

### Added
* [Semantic Search] Message about incompatibility with Autosuggest and Instant Results. Props [@felipeelia](https://github.com/felipeelia) via [#171](https://github.com/10up/ElasticPressLabs/pull/171).

### Changed
* [Semantic Search] Adjust when search algorithms are displayed. Props [@felipeelia](https://github.com/felipeelia) via [#188](https://github.com/10up/ElasticPressLabs/pull/188).
* Disable AI features after 3 failures. Props [@felipeelia](https://github.com/felipeelia) via [#176](https://github.com/10up/ElasticPressLabs/pull/176).
* Avoid excessive database writes when logging AI feature failures. Props [@felipeelia](https://github.com/felipeelia) and [@tott](https://github.com/tott) via [#201](https://github.com/10up/ElasticPressLabs/pull/201).
* Move Co-Authors Plus and WooCommerce Subscription Search settings to the third-party plugins section. Props [@burhandodhy](https://github.com/burhandodhy) via [#195](https://github.com/10up/ElasticPressLabs/pull/195).

### Deprecated
### Removed
### Fixed
* AI Features being automatically disabled when ES is unavailable. Props [@felipeelia](https://github.com/felipeelia) via [#168](https://github.com/10up/ElasticPressLabs/pull/168).
* Vector embeddings screen: Incomplete text and wrong `<title>`. Props [@felipeelia](https://github.com/felipeelia) via [#169](https://github.com/10up/ElasticPressLabs/pull/169).
* Vertical spacing between embedding field checkboxes. Props [@burhandodhy](https://github.com/burhandodhy) via [#192](https://github.com/10up/ElasticPressLabs/pull/192).
* Typo in `useVectorEmbeddingSettings`. Props [@burhandodhy](https://github.com/burhandodhy) via [#193](https://github.com/10up/ElasticPressLabs/pull/193).
* `version_compare()` deprecation when passing null. Props [@burhandodhy](https://github.com/burhandodhy) via [#194](https://github.com/10up/ElasticPressLabs/pull/194).
* Removed obsolete TinyMCE editor stylesheet and unused `script_loader_tag` filter. Props [@burhandodhy](https://github.com/burhandodhy) via [#197](https://github.com/10up/ElasticPressLabs/pull/197).
* Deprecated `RangeControl` default size, wrap checkboxes in `VStack`, and rename `enablefieldsIndexing` to `enableFieldsIndexing`. Props [@burhandodhy](https://github.com/burhandodhy) via [#199](https://github.com/10up/ElasticPressLabs/pull/199).

### Security
* Updated composer and node packages. Props [@felipeelia](https://github.com/felipeelia) via [#170](https://github.com/10up/ElasticPressLabs/pull/170).
* npm audit dependency updates. Props [@felipeelia](https://github.com/felipeelia) via [#191](https://github.com/10up/ElasticPressLabs/pull/191).
* Bumped `lodash` from 4.17.21 to 4.18.1. Props [@dependabot](https://github.com/dependabot) via [#175](https://github.com/10up/ElasticPressLabs/pull/175) and [#189](https://github.com/10up/ElasticPressLabs/pull/189).
* Bumped `phpunit/phpunit` from 9.6.22 to 9.6.33. Props [@dependabot](https://github.com/dependabot) via [#177](https://github.com/10up/ElasticPressLabs/pull/177).
* Bumped `webpack` from 5.100.2 to 5.105.0. Props [@dependabot](https://github.com/dependabot) via [#179](https://github.com/10up/ElasticPressLabs/pull/179).
* Bumped `qs` from 6.14.1 to 6.14.2. Props [@dependabot](https://github.com/dependabot) via [#182](https://github.com/10up/ElasticPressLabs/pull/182).
* Bumped `immutable` from 5.1.3 to 5.1.5. Props [@dependabot](https://github.com/dependabot) via [#185](https://github.com/10up/ElasticPressLabs/pull/185).
* Bumped `simple-git` from 3.28.0 to 3.33.0 and `svgo` from 3.3.2 to 3.3.3. Props [@dependabot](https://github.com/dependabot) via [#186](https://github.com/10up/ElasticPressLabs/pull/186).
* Bumped `flatted` from 3.3.3 to 3.4.2 and `picomatch` from 2.3.1 to 2.3.2. Props [@dependabot](https://github.com/dependabot) via [#187](https://github.com/10up/ElasticPressLabs/pull/187).

### Developer
* Add Patchstack security-reporting FAQ. Props [@jeffpaul](https://github.com/jeffpaul) via [#174](https://github.com/10up/ElasticPressLabs/pull/174).
* Set explicit `permissions` on GitHub Actions workflows. Props [@jeffpaul](https://github.com/jeffpaul) via [#178](https://github.com/10up/ElasticPressLabs/pull/178).
* Add `ep_user_sync_kill` filter in Users `action_queue_meta_sync`. Props [@burhandodhy](https://github.com/burhandodhy) and [@yarovikov](https://github.com/yarovikov) via [#183](https://github.com/10up/ElasticPressLabs/pull/183).
## [2.5.0] - 2025-11-05

### Added
- New Vector Embeddings, Semantic Search, and AI Summary Search features. Props [@tott](https://github.com/tott), [@felipeelia](https://github.com/felipeelia), [@psorensen](https://github.com/psorensen), [@gsarig](https://github.com/gsarig), [@ZacharyRener](https://github.com/ZacharyRener), [@burhandodhy](https://github.com/burhandodhy), and [@oscarssanchezz](https://github.com/oscarssanchezz) via [#126](https://github.com/10up/ElasticPressLabs/pull/126), [#158](https://github.com/10up/ElasticPressLabs/pull/158), [#160](https://github.com/10up/ElasticPressLabs/pull/160), and [#161](https://github.com/10up/ElasticPressLabs/pull/161).
- New `ep_user_pre_query_db_results` and `ep_user_query_db_sql` filters in Users `query_db` method. Props [@burhandodhy](https://github.com/burhandodhy), [@felipeelia](https://github.com/felipeelia), and [@yarovikov](https://github.com/yarovikov) via [#141](https://github.com/10up/ElasticPressLabs/pull/141).
- Support for include, lower_limit_object_id, and upper_limit_object_id to User Indexable. Props [@burhandodhy](https://github.com/burhandodhy) via [#144](https://github.com/10up/ElasticPressLabs/pull/144).
- Support for searching posts by Co-Author. Props [@burhandodhy](https://github.com/burhandodhy) via [#143](https://github.com/10up/ElasticPressLabs/pull/143).

### Fixed
- Geolocation infinte loop due to cache. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#147](https://github.com/10up/ElasticPressLabs/pull/147).
- Autoload fatal error when the plugin is installed via composer. Props [@felipeelia](https://github.com/felipeelia), [@burhandodhy](https://github.com/burhandodhy), and [@gsarig](https://github.com/gsarig) via [#145](https://github.com/10up/ElasticPressLabs/pull/145).
- Ordering user queries by meta key/value. Props [@mphillips](https://github.com/mphillips) via [#148](https://github.com/10up/ElasticPressLabs/pull/148).

### Security
- Overwrite package @babel/runtime coming from core packages due to a vulnerability. Props [@hugosolar](https://github.com/hugosolar) via [#152](https://github.com/10up/ElasticPressLabs/pull/152).
- Bumped `tar-fs` from 2.1.1 to 3.1.1. Props [@dependabot](https://github.com/dependabot) via [#139](https://github.com/10up/ElasticPressLabs/pull/139), [#150](https://github.com/10up/ElasticPressLabs/pull/150), and [#155](https://github.com/10up/ElasticPressLabs/pull/155).
- Bumped `http-proxy-middleware` from 2.0.7 to 2.0.9. Props [@dependabot](https://github.com/dependabot) via [#142](https://github.com/10up/ElasticPressLabs/pull/142).
- Removed `tmp`. Props [@dependabot](https://github.com/dependabot) via [#154](https://github.com/10up/ElasticPressLabs/pull/154).

### Developer
- Migrated e2e tests from Cypress to Playwright. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#153](https://github.com/10up/ElasticPressLabs/pull/153).
- Run e2e tests on Elasticsearch 9. Props [@felipeelia](https://github.com/felipeelia) via [#161](https://github.com/10up/ElasticPressLabs/pull/161).

## [2.4.0] - 2025-03-26

- New minimum versions (see [#122](https://github.com/10up/ElasticPressLabs/pull/122)) are:
	||Min|Max|
	|---|:---:|:---:|
	|ElasticPress|5.2.0|latest|
	|WordPress|6.0|latest|
	|PHP|7.4|latest|

### Added
* Geo Location Feature. Props [@burhandodhy](https://github.com/burhandodhy) and [@felipeelia](https://github.com/felipeelia) via [#123](https://github.com/10up/ElasticPressLabs/pull/123).
* Search Templates feature. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#118](https://github.com/10up/ElasticPressLabs/pull/118), [#135](https://github.com/10up/ElasticPressLabs/pull/135), and [#136](https://github.com/10up/ElasticPressLabs/pull/136).

### Changed
* Minimum requirements to run the plugin: PHP 7.4+, WP 6.0+, and EP 5.2.0+. Props [@felipeelia](https://github.com/felipeelia) via [#122](https://github.com/10up/ElasticPressLabs/pull/122).

### Fixed
* PHP Notice: Function _load_textdomain_just_in_time was called incorrectly. Props [@burhandodhy](https://github.com/burhandodhy) via [#125](https://github.com/10up/ElasticPressLabs/pull/125) and [#132](https://github.com/10up/ElasticPressLabs/pull/132).
* Editor deprecated warnings. Props [@burhandodhy](https://github.com/burhandodhy) via [#133](https://github.com/10up/ElasticPressLabs/pull/133).

### Developer
* Fixed PR links in the changelog. Props [@felipeelia](https://github.com/felipeelia) via [#115](https://github.com/10up/ElasticPressLabs/pull/115).
* Add e2e tests foundation. Props [@felipeelia](https://github.com/felipeelia) via [#119](https://github.com/10up/ElasticPressLabs/pull/119).
* Fix unit tests + small tweaks in Husky and Cypress setup. Props [@felipeelia](https://github.com/felipeelia) via [#120](https://github.com/10up/ElasticPressLabs/pull/120).

## [2.3.1] - 2024-12-11

### Added
- ElasticPress as a plugin dependency. Props [@jeffpaul](https://github.com/jeffpaul) via [#104](https://github.com/10up/ElasticPressLabs/pull/104).

### Changed
- Bumped actions/upload-artifact from v3 to v4. Props [@iamdharmesh](https://github.com/iamdharmesh) via [#106](https://github.com/10up/ElasticPressLabs/pull/106).
- Update versions of GitHub Actions, composer, and node packages. Props [@felipeelia](https://github.com/felipeelia) via [#110](https://github.com/10up/ElasticPressLabs/pull/110) and [#111](https://github.com/10up/ElasticPressLabs/pull/111).

### Fixed
- Textdomain in the Users feature. Props [@burhandodhy](https://github.com/burhandodhy) via [#114](https://github.com/10up/ElasticPressLabs/pull/114).

## [2.3.0] - 2024-03-04

This version introduces the new *External Content* feature. Check [our blog post](https://www.elasticpress.io/blog/2024/03/pew-research-center-external-files-as-a-source-for-your-search) for more info.


### Added
- New "External Content" feature. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#94](https://github.com/10up/ElasticPressLabs/pull/94) and [#99](https://github.com/10up/ElasticPressLabs/pull/99).

### Changed
- Composer packages update. Props [@felipeelia](https://github.com/felipeelia) via [#95](https://github.com/10up/ElasticPressLabs/pull/95).
- Compatibility with node v18. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#96](https://github.com/10up/ElasticPressLabs/pull/96).


## [2.2.0] - 2023-11-01

### Added
- Compatibility with ElasticPress 5.0.0. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#81](https://github.com/10up/ElasticPressLabs/pull/81) and [#85](https://github.com/10up/ElasticPressLabs/pull/85).

### Changed
- Features do not autoactivate anymore. Props [@felipeelia](https://github.com/felipeelia) via [#88](https://github.com/10up/ElasticPressLabs/pull/88).

### Security
- Bumped `@babel/traverse` from 7.19.3 to 7.23.2. Props [@dependabot](https://github.com/dependabot) via [#80](https://github.com/10up/ElasticPressLabs/pull/80).


## [2.1.1] - 2023-09-28

### Added
- Integrate with WP update system to alert users about new versions. Props [@felipeelia](https://github.com/felipeelia) via [#76](https://github.com/10up/ElasticPressLabs/pull/76).

### Changed
- Update the User Indexable files (bringing from the main plugin.) Props [@MARQAS](https://github.com/MARQAS) via [#72](https://github.com/10up/ElasticPressLabs/pull/72) and [#79](https://github.com/10up/ElasticPressLabs/pull/79).

### Removed
- Remove old Mapping files for Users. Props [@MARQAS](https://github.com/MARQAS) via [#72](https://github.com/10up/ElasticPressLabs/pull/72).

### Fixed
- Boolean Operator (Not) not giving the expected result. Props [@MARQAS](https://github.com/MARQAS) via [#67](https://github.com/10up/ElasticPressLabs/pull/67).
- Fatal Error in command line. Props [@MARQAS](https://github.com/MARQAS) via [#69](https://github.com/10up/ElasticPressLabs/pull/69).
- PHP Warnings. Props [@felipeelia](https://github.com/felipeelia) via [#71](https://github.com/10up/ElasticPressLabs/pull/71).

### Security
- Bumped `webpack` from 5.74.0 to 5.76.1. Props [@dependabot](https://github.com/dependabot) via [#64](https://github.com/10up/ElasticPressLabs/pull/64).
- Bumped `tough-cookie` from 4.1.2 to 4.1.3. Props [@dependabot](https://github.com/dependabot) via [#78](https://github.com/10up/ElasticPressLabs/pull/78).

## [2.1.0] - 2023-03-02

### Added
- Compatibility with the [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) add-on. Props [@ecaron](https://github.com/ecaron) and [@felipeelia](https://github.com/felipeelia) via [#51](https://github.com/10up/ElasticPressLabs/pull/51).
- Users Feature (migrated from the main ElasticPress plugin). Props [@felipeelia](https://github.com/felipeelia), [@MARQAS](https://github.com/MARQAS), and [@burhandodhy](https://github.com/burhandodhy) via [#50](https://github.com/10up/ElasticPressLabs/pull/50) and [#59](https://github.com/10up/ElasticPressLabs/pull/50).
- Load PHP classes via `spl_autoload_register()`. Props [@burhandodhy](https://github.com/burhandodhy) via [#57](https://github.com/10up/ElasticPressLabs/pull/57).

### Changed
- Code standards are now applied to the test suite too. Props [@felipeelia](https://github.com/felipeelia) via [#54](https://github.com/10up/ElasticPressLabs/pull/54).
- Hide subfeatures if the required plugins are not activated. Props [@burhandodhy](https://github.com/burhandodhy) via [#56](https://github.com/10up/ElasticPressLabs/pull/56).

### Fixed
- Adjusted the method used to determine if classes are loaded. Props [@ecaron](https://github.com/ecaron) via [#51](https://github.com/10up/ElasticPressLabs/pull/51).
- Ensure feature classes are only loaded once. Props [@ecaron](https://github.com/ecaron) via [#43](https://github.com/10up/ElasticPressLabs/pull/43).
- PHP Lint on PHP 8. Props [@felipeelia](https://github.com/felipeelia) via [#49](https://github.com/10up/ElasticPressLabs/pull/49).

### Security
- Bumped `loader-utils` from 2.0.2 to 2.0.4. Props [@dependabot](https://github.com/dependabot) via [#46](https://github.com/10up/ElasticPressLabs/pull/46).
- Bumped `json5` from 1.0.1 to 1.0.2. Props [@dependabot](https://github.com/dependabot) via [#47](https://github.com/10up/ElasticPressLabs/pull/46).

## [2.0.0] - 2022-10-17

### Added
- Documentation updates. Props [@jeffpaul](https://github.com/jeffpaul) via [#10](https://github.com/10up/ElasticPressLabs/pull/10).

### Changed
- Minimum requirements to run the plugin: ES (5.2-7.10), PHP 7.0+, WP 5.6+, and EP 4.3.0+. Props [@felipeelia](https://github.com/felipeelia) via [#29](https://github.com/10up/ElasticPressLabs/pull/29) and [#30](https://github.com/10up/ElasticPressLabs/pull/30).
- Assets are now built using 10up Toolkit. Props [@felipeelia](https://github.com/felipeelia) via [#28](https://github.com/10up/ElasticPressLabs/pull/28).
- Search algorithm selection now makes use of ElasticPress classes. Props [@felipeelia](https://github.com/felipeelia) via [#31](https://github.com/10up/ElasticPressLabs/pull/31).
- Small Refactor of BooleanSearchOperators and update of an ElasticPress filter usage. Props [@felipeelia](https://github.com/felipeelia) via [#33](https://github.com/10up/ElasticPressLabs/pull/33).
- Meta Key Pattern: settings fields renamed and HTML fix. Props [@felipeelia](https://github.com/felipeelia) via [#34](https://github.com/10up/ElasticPressLabs/pull/34).
- Co-authors Plus description and small refactor. Props [@felipeelia](https://github.com/felipeelia) via [#35](https://github.com/10up/ElasticPressLabs/pull/35).

### Fixed
- Boolean Search not working. Props [@burhandodhy](https://github.com/burhandodhy) via [#41](https://github.com/10up/ElasticPressLabs/pull/41).
- Link to Sync Page in the Meta Key Pattern subfeature. Props [@MARQAS](https://github.com/MARQAS) and [@felipeelia](https://github.com/felipeelia) via [#42](https://github.com/10up/ElasticPressLabs/pull/42).
- Undefined notice. Props [@oscarssanchez](https://github.com/oscarssanchez) via [#11](https://github.com/10up/ElasticPressLabs/pull/11).
- Composer v2 compatibility and unit tests. Props [@felipeelia](https://github.com/felipeelia) via [#22](https://github.com/10up/ElasticPressLabs/pull/22).
- Changes made in the main feature not being saved. Props [@felipeelia](https://github.com/felipeelia) via [#21](https://github.com/10up/ElasticPressLabs/pull/21).
- Notices related to undefined array indexes. Props [@felipeelia](https://github.com/felipeelia) via [#36](https://github.com/10up/ElasticPressLabs/pull/36).

### Removed
- Unused CSS file and JavaScript code. Props [@felipeelia](https://github.com/felipeelia) via [#38](https://github.com/10up/ElasticPressLabs/pull/38).

## [1.2.0] - 2021-09-01
### Added
- Boolean Search Operators Feature. Props [@moraleida](https://github.com/moraleida), [@Rahmon](https://github.com/Rahmon), and [@felipeelia](https://github.com/felipeelia) via [#7](https://github.com/10up/ElasticPressLabs/pull/7).

## [1.1.0] - 2021-07-27
### Added
- Integration with [Co-Authors Plus](https://wordpress.org/plugins/co-authors-plus/). Props [@dinhtungdu](https://github.com/dinhtungdu), [@Rahmon](https://github.com/Rahmon), and [@mbanusic](https://github.com/mbanusic) via [#4](https://github.com/10up/ElasticPressLabs/pull/4).

## [1.0.0] - 2021-02-09
### Added
- Initial plugin release.

[Unreleased]: https://github.com/10up/ElasticPressLabs/compare/trunk...develop
[2.6.0]: https://github.com/10up/ElasticPressLabs/compare/2.5.2...2.6.0
[2.5.2]: https://github.com/10up/ElasticPressLabs/compare/2.5.1...2.5.2
[2.5.1]: https://github.com/10up/ElasticPressLabs/compare/2.5.0...2.5.1
[2.5.0]: https://github.com/10up/ElasticPressLabs/compare/2.4.0...2.5.0
[2.4.0]: https://github.com/10up/ElasticPressLabs/compare/2.3.1...2.4.0
[2.3.1]: https://github.com/10up/ElasticPressLabs/compare/2.3.0...2.3.1
[2.3.0]: https://github.com/10up/ElasticPressLabs/compare/2.2.0...2.3.0
[2.2.0]: https://github.com/10up/ElasticPressLabs/compare/2.1.1...2.2.0
[2.1.1]: https://github.com/10up/ElasticPressLabs/compare/2.1.0...2.1.1
[2.1.0]: https://github.com/10up/ElasticPressLabs/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/10up/ElasticPressLabs/compare/1.2.0...2.0.0
[1.2.0]: https://github.com/10up/ElasticPressLabs/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/10up/ElasticPressLabs/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/10up/ElasticPressLabs/releases/tag/1.0.0
