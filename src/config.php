<?php

Kirby::plugin(
    'omz13/feeds',
    [
      'root'        => dirname( __FILE__, 2 ),
      'options'     => [
        'cache'           => true,            // enable plugin cache facility
        'disable'         => false,           // if true, 503 any requests
        'debugqueryvalue' => '42',            // guess!
        'cacheTTL'        => 10,              // time to cache generated feed, in MINUTES
        'firehose'        => 'articles',      // the collection to use for the firehose feed, i.e. /feed/whatever
        'categories'      => [],              // list of collections to use for /feed/<category>/whatever, if empty, disabled
        'author'          => 'Staff Writer',  // default author name
      ],

      'snippets'    => [
        'feeds-header'          => __DIR__ . '/snippets-feeds-header.php',
        'firehose-feeds-header' => __DIR__ . '/snippets-firehose-feeds-header.php',
      ],

      'routes'      => [
        [
          'pattern' => [
            '(:any)/feeds/(:any)', // <category> / "feed" / whatever     = category in format whatever
            'feeds/(:any)/(:any)', // "feed" / <category> / <whatever>   = category in format whatever
            'feeds/(:any)',        // "feed" / <whatever>                = firehose in format whatever
    //      '(:any)/([r][s][s])', // <category> / <whatever>
          ],
          'action'  => function ( $pa = "", $pb = "" ) {
            return omz13\Feeds::runRoutesFeeds( $pa, $pb );
          },
        ],
      ],

      'pageMethods' => [
        'headFeeds'         => function () {
          return omz13\Feeds::snippetsFeedHeader( true );
        },
        'headFirehoseFeeds' => function () {
          return omz13\Feeds::snippetsFeedHeader( false );
        },
      ],
    ]
);

require_once __DIR__ . '/feeds.php';
