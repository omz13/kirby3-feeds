<?php

Kirby::plugin(
    'omz13/feeds',
    [
      'root'    => dirname( __FILE__, 2 ),
      'options' => [
        'disable'                       => false,
    //  'disableJSON'                   => false,
    //  'disableATOM'                   => false,
        'cache'                         => true, // enable plugin cache facility
        'debugqueryvalue'               => '42',
        'cacheTTL'                      => 10,
        'includePageWhenTemplateIs'     => ['article'],
        'root'                          => ['blog'],
    //        'includeUnlistedWhenSlugIs'     => [],
    //        'excludePageWhenTemplateIs'     => [],
    //        'excludePageWhenSlugIs'         => [],
        'excludeChildrenWhenTemplateIs' => [],
    //        'disableImages'                 => false,
      ],

      'routes'  => [
        [
          'pattern' => 'feeds/atom',
          'action'  => function () {
            if ( omz13\Feeds::isEnabled() ) {
                $dodebug = omz13\Feeds::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
                return new Kirby\Cms\Response( omz13\Feeds::getAtomFeed( kirby()->site()->pages(), $dodebug ), 'application/xml' );
            } else {
                header( 'HTTP/1.0 404 Not Found' );
                echo 'The atom feed for this site is not available; sorry.';
                die;
            }
          },
        ],

        [
          'pattern' => 'feeds/json',
          'action'  => function () {
            return new Kirby\Cms\Response( omz13\Feeds::getJsonFeed( kirby()->site()->pages(), $dodebug ), 'json' );
          },
        ],
      ],
    ]
);

require_once __DIR__ . '/feeds.php';
