# ElasticPress Labs

> A developer-focused interface to enabling experimental [ElasticPress plugin](https://github.com/10up/ElasticPress/) features.

[![Support Level](https://img.shields.io/badge/support-active-green.svg)](#support-level) [![Tests](https://github.com/10up/ElasticPressLabs/actions/workflows/test.yml/badge.svg)](https://github.com/10up/ElasticPressLabs/actions/workflows/test.yml) [![Linting](https://github.com/10up/ElasticPressLabs/actions/workflows/lint.yml/badge.svg)](https://github.com/10up/ElasticPressLabs/actions/workflows/lint.yml) [![Release Version](https://img.shields.io/github/release/10up/ElasticPressLabs.svg)](https://github.com/10up/ElasticPressLabs/releases/latest) ![WordPress tested up to version](https://img.shields.io/badge/WordPress-v5.8%20tested-success.svg) [![GPLv2 License](https://img.shields.io/github/license/10up/ElasticPressLabs.svg)](https://github.com/10up/ElasticPressLabs/blob/develop/LICENSE.md)

**Please note:** `trunk` is the stable branch

## Overview

This plugin provides a developer-focused interface to commonly used filters without the need of being fully accessible and skipping the need of providing a streamlined user experience. It's meant to be an easy way to solve common issues without code changes.

## Documentation

ElasticPress Labs acts as an ElasticPress feature and registers its methods through the [ElasticPress Feature API](http://10up.github.io/ElasticPress/tutorial-feature-api.html). In this way the features added to this plugin will be immediately available in the ElasticPress interface.

This plugin provides a simple interface to enable and disable features.

## Requirements

* [Elasticsearch](https://www.elastic.co) 5.2+
* [PHP](https://php.net/) 7.4+
* [WordPress](http://wordpress.org) 6.0+
* [ElasticPress plugin](https://github.com/10up/ElasticPress/) 5.0.0+

## Features

### Boolean Search Operators

Allow users to search using boolean operators such as AND, OR, NOT, and double quotes.

### Co-Authors Plus

If using the Co-Authors Plus plugin and the Protected Content feature, enable this feature to see correct results when listing posts by author name in the Admin Post List screen.

### External Content

List meta keys containing a path or a URL, and ElasticPress will index the content of that path or URL. For example, for a meta key called `meta_key` with `https://wordpress.org/news/wp-json/wp/v2/posts/16837` as its value, the JSON returned by that REST API endpoint will be indexed in a meta key called `ep_external_content_meta_key`.

### Geo Location

Allow users to search for posts based on their location. Optionally, set a Google Maps API key and easily store coordinates related to your content.

### Meta Key Pattern

Allow and deny meta fields from being indexed using regular expressions.

### Search Algorithm Version

Change the search algorithm used by your site. Current options are:

* *Default:* Use a fuzzy match approach which includes results that have misspellings, and also includes matches on only some of the words in the search. 
* *Version 3.5:* Search for the existence of all words in the search first, then return results based on how closely those words appear.
* *Version 4.0:* Search for all search terms in one field first, then prioritize them over search terms matched in different fields. Used by default on ElasticPress 4.0+. 

### Search Templates

Search templates are Elasticsearch queries stored in [ElasticPress.io](https://www.elasticpress.io/) servers used by the [Search API](https://www.elasticpress.io/documentation/article/instant-results-post-search-api/).

### Users

Improve user search relevancy and query performance.

### WooCommerce Admin Subscription Search

Integration with the WooCommerce Subscriptions plugin. 

## Screenshots

1. Settings to allow boolean search operators in search queries.
![](/.wordpress-org/screenshot-1.png)

2. Settings to add Co-Authors Plus plugin support.
![](/.wordpress-org/screenshot-2.png)

3. Settings to include or exclude meta key patterns.
![](/.wordpress-org/screenshot-3.png)

4. Settings to change the version of the search algorithm between default, 3.5, and 4.0.
![](/.wordpress-org/screenshot-4.png)

5. Settings to index external content.
![](/.wordpress-org/screenshot-5.png)

## Support Level

**Active:** 10up is actively working on this, and we expect to continue work for the foreseeable future including keeping tested up to the most recent version of WordPress.  Bug reports, feature requests, questions, and pull requests are welcome.

## Changelog

A complete listing of all notable changes to ElasticPress Labs are documented in [CHANGELOG.md](https://github.com/10up/elasticpresslabs/blob/develop/CHANGELOG.md).

## Contributing

Please read [CODE_OF_CONDUCT.md](https://github.com/10up/elasticpresslabs/blob/develop/CODE_OF_CONDUCT.md) for details on our code of conduct, [CONTRIBUTING.md](https://github.com/10up/elasticpresslabs/blob/develop/CONTRIBUTING.md) for details on the process for submitting pull requests to us, and [CREDITS.md](https://github.com/10up/elasticpresslabs/blob/develop/CREDITS.md) for a listing of maintainers of, contributors to, and libraries used by ElasticPress Labs.

## Like what you see?

<p align="center">
<a href="http://10up.com/contact/"><img src="https://10up.com/uploads/2016/10/10up-Github-Banner.png" width="850"></a>
</p>
