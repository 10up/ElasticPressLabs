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
-->

## [2.3.0] - 2024-XX-XX

This version introduces the new *External Content* feature. Check [our blog post](https://www.elasticpress.io/blog/2024/02/pew-research-center-external-files-as-a-source-for-your-search) for more info.


### Added
- New "External Content" feature. Props [@felipeelia](https://github.com/felipeelia) via [#94](https://github.com/10up/ElasticPress/pull/94).

### Changed
- Composer packages update. Props [@felipeelia](https://github.com/felipeelia) via [#95](https://github.com/10up/ElasticPress/pull/95).
- Compatibility with node v18. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#96](https://github.com/10up/ElasticPress/pull/96).


## [2.2.0] - 2023-11-01

### Added
- Compatibility with ElasticPress 5.0.0. Props [@felipeelia](https://github.com/felipeelia) and [@burhandodhy](https://github.com/burhandodhy) via [#81](https://github.com/10up/ElasticPress/pull/81) and [#85](https://github.com/10up/ElasticPress/pull/85).

### Changed
- Features do not autoactivate anymore. Props [@felipeelia](https://github.com/felipeelia) via [#88](https://github.com/10up/ElasticPress/pull/88).

### Security
- Bumped `@babel/traverse` from 7.19.3 to 7.23.2. Props [@dependabot](https://github.com/dependabot) via [#80](https://github.com/10up/ElasticPressLabs/pull/80).


## [2.1.1] - 2023-09-28

### Added
- Integrate with WP update system to alert users about new versions. Props [@felipeelia](https://github.com/felipeelia) via [#76](https://github.com/10up/ElasticPress/pull/76).

### Changed
- Update the User Indexable files (bringing from the main plugin.) Props [@MARQAS](https://github.com/MARQAS) via [#72](https://github.com/10up/ElasticPress/pull/72) and [#79](https://github.com/10up/ElasticPress/pull/79).

### Removed
- Remove old Mapping files for Users. Props [@MARQAS](https://github.com/MARQAS) via [#72](https://github.com/10up/ElasticPress/pull/72).

### Fixed
- Boolean Operator (Not) not giving the expected result. Props [@MARQAS](https://github.com/MARQAS) via [#67](https://github.com/10up/ElasticPress/pull/67).
- Fatal Error in command line. Props [@MARQAS](https://github.com/MARQAS) via [#69](https://github.com/10up/ElasticPress/pull/69).
- PHP Warnings. Props [@felipeelia](https://github.com/felipeelia) via [#71](https://github.com/10up/ElasticPress/pull/71).

### Security
- Bumped `webpack` from 5.74.0 to 5.76.1. Props [@dependabot](https://github.com/dependabot) via [#64](https://github.com/10up/ElasticPressLabs/pull/64).
- Bumped `tough-cookie` from 4.1.2 to 4.1.3. Props [@dependabot](https://github.com/dependabot) via [#78](https://github.com/10up/ElasticPressLabs/pull/78).

## [2.1.0] - 2023-03-02

### Added
- Compatibility with the [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) add-on. Props [@ecaron](https://github.com/ecaron) and [@felipeelia](https://github.com/felipeelia) via [#51](https://github.com/10up/ElasticPressLabs/pull/51).
- Users Feature (migrated from the main ElasticPress plugin). Props [@felipeelia](https://github.com/felipeelia), [@MARQAS](https://github.com/MARQAS), and [@burhandodhy](https://github.com/burhandodhy) via [#50](https://github.com/10up/ElasticPressLabs/pull/50) and [#59](https://github.com/10up/ElasticPressLabs/pull/50).
- Load PHP classes via `spl_autoload_register()`. Props [@burhandodhy](https://github.com/burhandodhy) via [#57](https://github.com/10up/ElasticPressLabs/pull/57).

## Changed
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

### [1.0.0] - 2021-02-09
### Added
- Initial plugin release.

[Unreleased]: https://github.com/10up/ElasticPressLabs/compare/trunk...develop
[2.3.0]: https://github.com/10up/ElasticPressLabs/compare/2.2.0...2.3.0
[2.2.0]: https://github.com/10up/ElasticPressLabs/compare/2.1.1...2.2.0
[2.1.1]: https://github.com/10up/ElasticPressLabs/compare/2.1.0...2.1.1
[2.1.0]: https://github.com/10up/ElasticPressLabs/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/10up/ElasticPressLabs/compare/1.2.0...2.0.0
[1.2.0]: https://github.com/10up/ElasticPressLabs/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/10up/ElasticPressLabs/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/10up/ElasticPressLabs/releases/tag/1.0.0
