# Kirby3 feeds

 ![License](https://img.shields.io/github/license/mashape/apistatus.svg) ![Kirby Version](https://img.shields.io/badge/Kirby-3%2B-black.svg) [![Issues](https://img.shields.io/github/issues/omz13/kirby3-feeds.svg)](https://github.com/omz13/kirby3-feeds/issues)

**Requirement:** Kirby 3.0.0-RC3 or better

## Coffee, Beer, etc.

This started as a simple plugin and morphed into something quite complex to cope with a combination of trying to get something sensible out of Kirby while also trying to generate something acceptable within the constraints of the different syndication formats.

This plugin is free but if you use it in a commercial project to show your support you are welcome to:
- [make a donation 🍻](https://www.paypal.me/omz13/10) or
- [buy me ☕☕](https://buymeacoff.ee/omz13) or
- [buy a Kirby license using this affiliate link](https://a.paddle.com/v2/click/1129/36191?link=1170)

## Documentation

### Purpose

For a kirby3 site, this plugin (_omz13/feeds_)  generates syndication feeds in RSS, ATOM, and JSON formats based on a Kirby3 "collection". If a page is given in a collection it will be included in a syndication feed; therefore, ensure that the collection filters "published" or "draft" per your requirements because this plugin does no filtering whatsoever (because you may want to do something like have a feed for drafts). You are in control of what does or does not get put into a feed (and this can be different from what is publically shown on a site).

- Kirby3 "collection" to atom/json/rss-feed methodology.
- Generates a [RSS 2.0](https://validator.w3.org/feed/docs/rss2.html) syndication feed (at `/feeds/rss`) based on a default collection, c.f. `firehose` in _Configuration_.
- Generates an [ATOM](https://validator.w3.org/feed/docs/atom.html) syndication feed (at `/feeds/atom`) based on a default collection.
- Generates a [JSON 1](https://jsonfeed.org/version/1) syndication feed (at `/feeds/json`) based on a default collection.
- Generates sub-setted syndication feeds at `feeds/<category>/atom|json|rss`) where `<category>` is mapped to a kirby collection,  c.f. `categories` in _Configuration_.
- A feed will have a maximum of 60 items.
- To mitigate server load, and to return a speedy response, the generated feeds are cached in the server for a determined amount of time, c.f. `cacheTTL` in _Configuration_.
- Supports HTTP conditional get (with strong validation): i.e. respects `If-Modified-Since` and  `If-None-Match` headers and will return a `304 Not Modified` response if appropriate (saves your bandwidth).
- The following data is derrived as follows from fields in the pages (and their use depends on the feed format used):
  - the author(s) to be attributed is taken from the `author` field: this field can be either a structured field or a text field, but it should be the email address of the author (and will be used to map to a kirby user for their details). If an author can not be mapped to a user, a default is applied, c.f. `author` in _Configuration_.
  - the title from `title`, a string.
  - the modified date from, in order of preference: `updatedat`, `date`; note: if neither of these can be resolved, the the page's underlying file's modified-at attribute is used.
  - the published date from in order of preference: `date`, `updatedat`; note: if neither of these can be resolved, the the page's underlying file's modified-at attribute is used.
- For ATOM and JSON feeds, per author details are supplemented by their user (in order of preference) `website` or `twitter` or `instagram` data mapping to `uri`.
- If an item has multiple authors:
  - For RSS and JSON feeds, the author will be given a concatenation of the authors' names (e.g. if the authors are Ford and Zaphod, they would be attributed as "Ford and Zaphod").
  - For RSS, each author is additionally listed individually in `<dc:creator>`.
  - For JSON, each author's details are given in `authors` per [brentsimmons/JSONFeed#120](https://github.com/brentsimmons/JSONFeed/pull/120). This is all _highly experimental_ so may break things - your feedback is appreciated.
- For a RSS feed, also provides `<dc:date>` for each item, and `<dc:rights>` for the channel.
- For a JSON feed, in addition to providing html content in `content_html` a markdown version is also provied in `content_text`; note that the markdown version is generated from the html representation and is not simply the raw (markdown + kirbytext) page content.
- For debugging purposes, the generated sitemap can include additional information as comments; c.f. `debugqueryvalue` in _Configuration_. The output may still work with a reader, but it is intended for _debugging_ not _consuming_.

Caveat:
- Withdrawn pages (i.e. with a method `issunset` that returns `true`) can be excluded by appropriate configuration within a collection.
- Pages under an embargo (i.e. with a method `isunderembargo` that returns `true`) can similarly be managed.
- May contains bugs because there is a lot of nasty code to deal with edge cases in the various syndication specifications; where possible it tries to generate things as best it can (i.e. sensible assumptions).

### Client Support

RSS feeds are generally well supported by clients; the ATOM feed offers some extra technical niceties, but client support varies; the JSON format is very new, and client support varies. Here are details of a few clients that I have used:

In terms of client for OS X:
- [Readkit](https://readkitapp.com), does RSS and ATOM, but not JSON; it is über-fussy with the way it resolves names to hosts (and will just moan about "invalid feed" instead of really saying what it thinks is wrong).
- [ReederApp](http://reederapp.com) supoorts all three formats.
- [Leaf](https://rockysandstudio.com) supports RSS and ATOM - note: not updated since Nov 2017.
- [NetNewsWire](https://ranchero.com/netnewswire/) - supoorts all three formats - note: v5 has gone back to Brent, so very _alpha_ at the moment.
- [NewsExplorer](https://betamagic.nl/products/newsexplorer.html) - supoorts all three formats.
- [Vienna](http://www.vienna-rss.com) does RSS and ATOM but not JSON.

In terms of iOS:
- Readkit - see OS X
- ReederApp - see OS X
- NewsExplorer (does Apple TV too!)

In terms of generic services/clients:
- [FeedBin](https://feedbin.com/)
- [Feedly](https://feedly.com/) supports RSS
- [FeedWrangler](https://feedwrangler.net/)
- [NewsBlur](https://www.newsblur.com) supports all three formats.

In terms of Windows clients: sorry, I have no idea as inhabit an OS X / iOS world.

#### Related plugins

For a plugin that provides the methods `issunset` and `isunderembargo`, kindly see [omz13/kirby3-suncyclepages](https://github.com/omz13/kirby3-suncyclepages).

#### Roadmap

The non-binding list of planned features and implementation notes are:

- [x] MVP
- [ ] Linkblogs
- [ ] Splitting
- [x] Source by collection
- [ ] Languages
- [x] Author into item stream
- [ ] Category into item stream
- [x] Generate feed discovery headers
- [x] dc for RSS

### Installation

Pick one of the following per your epistemological model:

- `composer require omz13/kirby3-feeds`; the plugin will automagically appear in `site/plugins`.
- Download a zip of the latest release - [master.zip](https://github.com/omz13/kirby3-feeds/archive/master.zip) - and copy the contents to your `site/plugins/kirby3-feeds`.
- `git submodule add https://github.com/omz13/kirby3-feeds.git site/plugins/kirby3-feeds`.

For the record: installation by composer is cool; supporting installation by zip and submodule was an absolute pain, especially as I am an installation by composer person, so do feel guilted into getting me Coffee, Beer, etc., because this is for _your_ benefit and _not mine_ (and yes, I would have have preferred to spend my time somewhere warm and sunny instead of being hunched over a keyboard while the snow falls outside and the thermometer shows no inclination to get above 0C).

### Configuration

There are four aspects that need configuration:

- `site/config/config.php`
- `content/site.txt` via blueprint (optional)
- Feed discovery
- Collections

#### `site/config/config.php`

In your site's `site/config/config.php` the following entries prefixed with `omz13.feeds.` can be used:

- `disable` - optional - boolean - default `false` - a boolean which, if true, to disable serving syndication feeds. Requests to such pages will receive a `503 Service Unavailable`.
- `cacheTTL` - optional - integer - default `10` - the number of minutes that a feed should be cached before being regenerated; if explicitly set to zero, the cache is disabled. If not specified a default of 10 minutes is assumed.
- `firehose` - optional - string - default `'articles'` - the name of the kirby collection to be used for the 'firehose' feed /feeds/atom|json|rss.
- `categories` - optional - array - default `[ 'articles' ] ` - the names of the kirby collections that can be accessed using the uri `/feeds/<category>/atom|json|rss`. If an empty array is specifed (`[]`) then this feature is disabled.
- `debugqueryvalue` - optional - string - the value for the query parameter `debug` to return a feed with debugging information (as comments within response). The global kirby `debug` configuration must also be true for this to work. Be aware that the debugging information will show, if applicable, details of any pages that have been excluded (so if you are using this in production and you don't want things to leak, set `debugqueryvalue` to something random). Furthermore, the site debug flag needs to be set too (i.e. the `debug` flag in `site/config.php`).
- `author` - optional - string - default `'Staff Writer'` - the name to be used for each item in a feed when either the author is unknown or the syndication format does not allow an author name (looking at you RSS2).

#### `content/site.txt` (via blueprint `site/blueprints/site.yml`)

The plugin can be explicitly disabled in `content/site.txt` by having an entry for `feeds` and setting this to `false`. This could be achieved through the panel by adding the following into `site/blueprints/site.yml`:

```yaml
type:          fields
fields:
  feeds:
    label:     Syndication
    type:      toggle
    default:   off
    text:
      - RSS disabled
      - RSS enabled
```

If any entry for `feeds` is not present in `content/site.txt`, it is assumed that the plugin is not be be disabled (i.e. failsafe to `false`).

#### Feed discovery

At a minimum you need to add appropriate feed-discovery links to your site's homepage.

This plugin provides two snippets to dynamically generate the necessary meta data:

- `feeds-header` - to provide the links for all feeds (firehose and category-based)
- `firehose-feeds-header` - to provide the links for the firehose feed only.

Or for those who prefer to use pageMethods, the equivalent to the above are:
- `headFeeds`
- `headFirehoseFeeds`

Example:

In `site/snippets/header.php` - or whatever generates your `<head>` data - simply add `<?php snippet('feeds-header') ?>` or `<?= $page->headFeeds() ?>` in the best applicable place.

If the plugin is disabled, these snippets will not generate the appropriate feed-discovery links.

#### Collections

For the default (firehose) syndication feed, you need to ensure that you have a collection and have configured it. Typically this would be an 'articles' collection, and an example would be:

```php
<?php

return function ($site) {
    return $site->find('blog')->children()->listed()->flip();
};
```

If using [omz13/suncyclepages](https://github.com/omz13/kirby3-suncyclepages) for embargo and withdraw, this would then be:

```php
<?php

return function ($site) {
	    return $site->find('blog')->children()->listed()->isunderembargo(false)->issunset(false)->flip();
};
```

### Use

For example, for the [Kirby Starter Kit](https://github.com/k-next/starterkit), the following would be applicable:

```php
<?php

return [
  'omz13.feeds.cacheTTL' => 60,
  'omz13.feeds.firehose' => 'articles',
  ],
];
```

_For experimental purposes this plugin implements a single-level pseudo-namespace. You can mix discrete vs array options, but try not to, and be aware that priority is given to the array variant. The above discrete configuration would therefore become:_

```php
<?php

return [
  'omz13.feeds' => [
    'cacheTTL' => 60,
    'firehose' => 'articles',
  ],
];
```

See Kirby3's [issue #761](https://github.com/k-next/kirby/issues/761) for more about namespaced options.

## Disclaimer

This plugin is provided "as is" with no guarantee. Use it at your own risk and always test it yourself before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/omz13/kirby3-feeds/issues/new).
