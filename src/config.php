<?php

Kirby::plugin(
    'omz13/feeds',
    [
      'root'    => dirname( __FILE__, 2 ),
      'options' => [
        'cache'           => true,  // enable plugin cache facility
        'disable'         => false, // if true, 503 any requests
        'debugqueryvalue' => '42',  // guess!
        'cacheTTL'        => 10,    // time to cache generated feed, in MINUTES
        'firehose'        => 'articles',  // the collection to use for the firehose feed, i.e. /feed/whatever
        'categories'      => [], // [ 'articles', 'embargo', 'projects' ],
      ],

      'routes'  => [
        [
          'pattern' => [
            'feedme/(:any)/(:any)',
            'feedme/(:any)',
          ],
          'action'  => function ( $category = "", $whatever = "" ) {
            if ( omz13\Feeds::isEnabled() == false ) {
              header( 'HTTP/1.0 503 Service Unavailable' );
              echo 'The syndication feed is unavailable; sorry.';
              die;
            }

            if ( $whatever == "" ) {
              // feedme/(:any) -> feedme/<firehose>/(:any)
              $whatever = strtolower( $category );
              $category = omz13\Feeds::getConfigurationForKey( 'firehose' );
              $firehose = true;
            } else {
              $firehose = false;
            }

            if ( in_array( strtolower( $whatever ), [ 'atom', 'json', 'rss' ], false ) == false ) {
              header( 'HTTP/1.0 404' );
              echo 'Feed ' . $whatever . ' is invalid; request /feeds/atom, /feeds/json, or /feeds/rss';
              die;
            }

            $category = strtolower( $category );

            if ( $firehose == false ) {
              $availableCats = omz13\Feeds::getArrayConfigurationForKey( 'categories' );

              if ( $availableCats == null ) {
                header( 'HTTP/1.0 500' );
                echo 'Feed for \'' . $category . '\' is not available; request the firehose: /feeds/atom, /feeds/json, or /feeds/rss';
                die;
              }
              assert( $category != "" );
              if ( in_array( $category, $availableCats, true ) == false ) {
                header( 'HTTP/1.0 404' );
                echo 'The syndication category feed \'' . $category . '\' does not exist; sorry.';
                die;
              }
            }//end if

            $collection = null;
            try {
              $collection = kirby()->collection( $category );
            } catch ( Kirby\Exception\NotFoundException $e ) {
              $collection = null;
            }
            if ( $collection == null ) {
              header( 'HTTP/1.0 500' );
              echo 'Oops. A collection for syndication category feed \'' . $category . '\' was not found.';
              die;
            }

            $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
            $r       = omz13\Feeds::getFeedWhatever( $whatever, $category, $firehose, $collection, $dodebug );
            if ( $r != null ) {
              $mime = [
                'atom' => 'application/atom+xml',
                'json' => 'application/json',
                'rss'  => 'application/rss+xml',
              ];
              return new Kirby\Cms\Response( $r , $mime[$whatever] );
            } else {
              header( 'HTTP/1.0 404 Not Found' );
              die;
            }
          },
        ],
      ],
    ]
);

require_once __DIR__ . '/feeds.php';
