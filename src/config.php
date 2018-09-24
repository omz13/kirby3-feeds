<?php

Kirby::plugin(
    'omz13/feeds',
    [
      'root'    => dirname( __FILE__, 2 ),
      'options' => [
        'disable'         => false,
        'cache'           => true, // enable plugin cache facility
        'debugqueryvalue' => '42',
        'cacheTTL'        => 10,
        'root'            => 'blog',
      ],

      'routes'  => [
        [
          'pattern' => 'feeds/atom.xml',
          'action'  => function () {
            if ( omz13\Feeds::isEnabled() ) {
                $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
                $root    = omz13\Feeds::getConfigurationForKey( 'root' );
                return new Kirby\Cms\Response( omz13\Feeds::getFeedAtom( $root, $dodebug ), 'application/atom+xml' );
            } else {
                header( 'HTTP/1.0 503 Service Unavailable' );
                echo 'The ATOM-based syndication feed for this site is currently not available; sorry.';
                die;
            }
          },
        ],

        [
          'pattern' => 'feeds/rss.xml',
          'action'  => function () {
            if ( omz13\Feeds::isEnabled() ) {
                $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
                $root    = omz13\Feeds::getConfigurationForKey( 'root' );
                return new Kirby\Cms\Response( omz13\Feeds::getFeedRss( $root, $dodebug ), 'application/rss+xml' );
            } else {
                header( 'HTTP/1.0 503 Service Unavailable' );
                echo 'The RSS-based syndication feed for this site is current not available; sorry.';
                die;
            }
          },
        ],

        [
          'pattern' => 'feeds/feed.json',
          'action'  => function () {

            if ( omz13\Feeds::isEnabled() ) {
                $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
                $root    = omz13\Feeds::getConfigurationForKey( 'root' );
                return new Kirby\Cms\Response( omz13\Feeds::getFeedJson( $root, $dodebug ), 'application/json' );
            } else {
                header( 'HTTP/1.0 503 Service Unavailable' );
                echo 'The JSON-based syndication feed for this site is not available; sorry.';
                die;
            }
          },
        ],
      ],

    ]
);

require_once __DIR__ . '/feeds.php';
