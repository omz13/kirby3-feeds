<?php

Kirby::plugin(
    'omz13/feeds',
    [
      'root'    => dirname( __FILE__, 2 ),
      'options' => [
        'cache'           => true,          // enable plugin cache facility
        'disable'         => false,         // if true, 503 any requests
        'debugqueryvalue' => '42',          // guess!
        'cacheTTL'        => 10,            // time to cache generated feed, in MINUTES
        'firehose'        => 'articles',    // the collection to use for the firehose feed, i.e. /feed/whatever
        'categories'      => [],            // list of collections to use for /feed/<category>/whatever, if empty, disabled
      ],

      'routes'  => [
        [
          'pattern' => [
            '(:any)/feed/(:any)', // <category> / "feed" / whatever     = category in format whatever
            'feed/(:any)/(:any)', // "feed" / <category> / <whatever>   = category in format whatever
            'feed/(:any)',        // "feed" / <whatever>                = firehose in format whatever
    //      '(:any)/([r][s][s])', // <category> / <whatever>
          ],
          'action'  => function ( $pa = "", $pb = "" ) {
            if ( omz13\Feeds::isEnabled() == false ) {
              header( 'HTTP/1.0 503 Service Unavailable' );
              echo 'Syndication feed is unavailable; sorry.';
              die;
            }

            if ( $pa == "feed" && $pb != "" ) {
                // '(:any)/feed/(:any)' = <category> / "feed" / whatever = category in format whatever
                $firehose = false;
                $category = $pa;
                $whatever = $pb;
            } else {
              if ( $pb == "" ) {
                // 'feed/(:any)' = "feed" / <whatever> = firehose in format whatever
                $whatever = strtolower( $pa );
                $category = omz13\Feeds::getConfigurationForKey( 'firehose' );
                $firehose = true;
              } else {
                // 'feed/(:any)/(:any)', // "feed" / <category> / <whatever>   = category in format whatever
                $category = $pa;
                $whatever = $pb;
                $firehose = false;
              }
            }//end if

            if ( in_array( strtolower( $whatever ), [ 'atom', 'json', 'rss' ], true ) == false ) {
              header( 'HTTP/1.0 404' );
              echo 'Feed type ' . $whatever . ' is invalid; atom, json, or rss required.';
              die;
            }

            $category = strtolower( $category );

            if ( $firehose == false ) {
              $availableCats = omz13\Feeds::getArrayConfigurationForKey( 'categories' );

              if ( $availableCats == null ) {
                header( 'HTTP/1.0 404' );
                echo 'Category-based syndication feeds are not available; sorry.';
                die;
              }
              assert( $category != "" );
              if ( in_array( $category, $availableCats, true ) == false ) {
                header( 'HTTP/1.0 404' );
                echo 'A syndication feed for category \'' . $category . '\' does not exist; sorry.';
                die;
              }
            }//end if

            if ( kirby()->collections()->has( $category ) == false ) {
              header( 'HTTP/1.0 500' );
              echo 'Oops. Syndication feed configuration error - collection \'' . $category . '\' not found but needed for ' . ( $firehose ? "firehose" : "category-based" ) . " feed.";
              die;
            }

            $h = kirby()->request()->headers();

            if ( array_key_exists( "If-None-Match", $h ) ) {
              header( 'HTTP/1.0 304' );
              $eTag = $h['If-None-Match'];
              if ( strpos( $eTag, 'z13' ) != 0 ) {
                header( 'HTTP/1.0 400 Bad Request' );
                echo 'Malformed If-None-Match \'' . $h['If-None-Match'] . '\'';
                die;
              }
            } else {
              $eTag = "";
            }

            if ( array_key_exists( "If-Modified-Since", $h ) ) {
              $lastMod = strtotime( $h['If-Modified-Since'] );
              if ( $lastMod == null ) {
                header( 'HTTP/1.0 400 Bad Request' );
                echo 'Malformed If-Modified-Since \'' . $h['If-Modified-Since'] . '\'';
                die;
              }
            } else {
              $lastMod = 0;
            }

            $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
            $r       = omz13\Feeds::getFeedWhatever( $whatever, $category, $firehose, $lastMod, $eTag, $dodebug );

            if ( $lastMod != 0 ) {
              header( 'last-modified: ' . date( DATE_RSS, $lastMod ) );
            }
            if ( $eTag != "" ) {
              header( 'etag: ' . $eTag );
            }

            if ( $r != null ) {
              $mime = [
                'atom' => 'application/atom+xml',
                'json' => 'application/json',
                'rss'  => 'application/rss+xml',
              ];
              return new Kirby\Cms\Response( $r , $mime[$whatever] );
            } else {
              if ( $lastMod == 0 || $eTag == 0 ) {
                header( 'HTTP/1.0 304 Not Modified' );
              } else {
                header( 'HTTP/1.0 404 Not Found' );
              }
              die;
            }//end if
          },
        ],
      ],
    ]
);

require_once __DIR__ . '/feeds.php';
