<?php

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
// phpcs:disable SlevomatCodingStandard.Classes.UnusedPrivateElements.UnusedMethod
// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
// phpcs:disable SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingTraversableParameterTypeHintSpecification

namespace omz13;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Cms\User;
use Kirby\Toolkit\Xml;
use League\HTMLToMarkdown\HtmlConverter;

use const DATE_ATOM;
use const FEEDS_CONFIGURATION_PREFIX;
use const FEEDS_VERSION;
use const JSON_UNESCAPED_SLASHES;

use function array_key_exists;
use function array_merge;
use function array_push;
use function assert;
use function count;
use function date;
use function define;
use function explode;
use function file_exists;
use function filemtime;
use function get;
use function header;
use function in_array;
use function is_array;
use function json_encode;
use function kirby;
use function md5;
use function microtime;
use function next;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function strtotime;
use function time;
use function ucwords;

define( 'FEEDS_VERSION', '1.0.0' );
define( 'FEEDS_CONFIGURATION_PREFIX', 'omz13.feeds' );

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings("unused")
 */
class Feeds
{
  private static $debug;

  public static function version() : string {
    return FEEDS_VERSION;
  }//end version()

  public static function ping() : string {
    return static::class . ' pong ' . static::version();
  }//end ping()

  public static function isEnabled() : bool {
    if ( self::getConfigurationForKey( 'disable' ) == 'true' ) {
      return false;
    }

    if ( kirby()->site()->content()->get( 'feeds' ) == 'false' ) {
      return false;
    }

    return true;
  }//end isEnabled()

  public static function getArrayConfigurationForKey( string $key ) : ?array {
    // Try to pick up configuration when provided in an array (vendor.plugin.array(key=>value))
    $o = kirby()->option( FEEDS_CONFIGURATION_PREFIX );
    if ( $o != null && is_array( $o ) && array_key_exists( $key, $o ) ) {
      return $o[$key];
    }

    // try to pick up configuration as a discrete (vendor.plugin.key=>value)
    $o = kirby()->option( FEEDS_CONFIGURATION_PREFIX . '.' . $key );
    if ( $o != null ) {
      return $o;
    }

    // this should not be reached... because plugin should define defaults for all its options...
    return null;
  }//end getArrayConfigurationForKey()

  public static function getConfigurationForKey( string $key ) : string {
    // Try to pick up configuration when provided in an array (vendor.plugin.array(key=>value))
    $o = kirby()->option( FEEDS_CONFIGURATION_PREFIX );
    if ( $o != null && is_array( $o ) && array_key_exists( $key, $o ) ) {
      return $o[$key];
    }

    // try to pick up configuration as a discrete (vendor.plugin.key=>value)
    $o = kirby()->option( FEEDS_CONFIGURATION_PREFIX . '.' . $key );
    if ( $o != null ) {
      return $o;
    }

    // this should not be reached... because plugin should define defaults for all its options...
    return "";
  }//end getConfigurationForKey()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
  * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
  */
  public static function runRoutesFeeds( string $pa, string $pb ) : Response {
    if ( static::isEnabled() == false ) {
      return new Response(
          'Syndication feed is unavailable; sorry',
          null,
          503
      );
    }

    if ( $pa == "feeds" && $pb != "" ) {
        // '(:any)/feed/(:any)' = <category> / "feed" / whatever = category in format whatever
        $firehose = false;
        $category = $pa;
        $whatever = $pb;
    } else {
      if ( $pb == "" ) {
        // 'feed/(:any)' = "feed" / <whatever> = firehose in format whatever
        $whatever = strtolower( $pa );
        $category = static::getConfigurationForKey( 'firehose' );
        $firehose = true;
      } else {
        // 'feed/(:any)/(:any)', // "feed" / <category> / <whatever>   = category in format whatever
        $category = $pa;
        $whatever = $pb;
        $firehose = false;
      }
    }//end if

    if ( in_array( strtolower( $whatever ), [ 'atom', 'json', 'rss' ], true ) == false ) {
      return new Response(
          'Feed type ' . $whatever . ' is invalid; atom, json, or rss required.',
          null,
          404
      );
    }

    $category = strtolower( $category );

    if ( $firehose == false ) {
      $availableCats = static::getArrayConfigurationForKey( 'categories' );

      if ( $availableCats == null ) {
        return new Response(
            'Category-based syndication feeds are not available; sorry.',
            null,
            404
        );
      }
      assert( $category != "" );
      if ( in_array( $category, $availableCats, true ) == false ) {
        return new Response(
            'A syndication feed for category \'' . $category . '\' does not exist; sorry.',
            null,
            404
        );
      }
    }//end if

    if ( kirby()->collections()->has( $category ) == false ) {
      header( 'HTTP/1.0 500' );
      return new Response(
          'Oops. Syndication feed configuration error - collection \'' . $category . '\' not found but needed for ' . ( $firehose ? "firehose" : "category-based" ) . " feed." ,
          null,
          404
      );
    }

    $h = kirby()->request()->headers();

    if ( array_key_exists( "If-None-Match", $h ) ) {
      $eTag = $h['If-None-Match'];
      if ( strpos( $eTag, 'z13' ) != 0 || strlen( $eTag ) != 35 ) {
        return new Response(
            'Malformed If-None-Match \'' . $h['If-None-Match'] . '\'',
            null,
            400
        );
      }
    } else {
      $eTag = "";
    }

    if ( array_key_exists( "If-Modified-Since", $h ) ) {
      $lastMod = strtotime( $h['If-Modified-Since'] );
      if ( $lastMod == null ) {
        return new Response(
            'Malformed If-Modified-Since \'' . $h['If-Modified-Since'] . '\'',
            null,
            400
        );
      }
    } else {
      $lastMod = 0;
    }

    $dodebug = static::getConfigurationForKey( 'debugqueryvalue' ) == get( 'debug' );
    $r       = static::getFeedWhatever( $whatever, $category, $firehose, $lastMod, $eTag, $dodebug );

    if ( $lastMod != 0 ) {
      //header( 'last-modified: ' . date( DATE_RSS, $lastMod ) );
      // date_default_timezone_set('GMT');
      header( 'last-modified: ' . date( 'D, M j G:i:s', $lastMod ) . " GMT" );
    }
    if ( $eTag != "" ) {
      header( 'etag: "' . $eTag . '"' );
    }

    if ( $r != null ) {
      $mime = [
        'atom' => 'text/xml', // application/atom+xml
        'json' => 'application/json',
        'rss'  => 'application/rss+xml',  // application/rss+xml
      ];
      return new Response( $r , $mime[$whatever] );
    } else {
      return new Response(
          "",
          null,
          $lastMod == 0 || $eTag == 0 ? 304 : 404
      );
    }//end if
  }//end runRoutesFeeds()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
   */
  private static function getFeedWhatever( string $what, string $collectionName, bool $firehose, int &$lastMod, string &$eTag, bool $debug = false ) : ?string {

    $generator = [ self::class, 'generateFeed' . ucwords( $what ) ];

    $tbeg = microtime( true );

    // echo "<!-- Getting " . $what . " for " . json_encode( $root ) . "-->\n";

    $cacheTTL = (int) static::getConfigurationForKey( 'cacheTTL' );

    static::$debug = $debug && kirby()->option( 'debug' ) !== null && kirby()->option( 'debug' ) == true;

    // if cacheTTL disabled...
    if ( $cacheTTL == "" || $cacheTTL == "0" || $cacheTTL == false ) {
      $r = static::generateFeedWhatever( $generator, $collectionName, $firehose, $lastMod, $eTag, $debug );
      if ( static::$debug == true ) {
        $r .= "<!-- Freshly generated; not cached for reuse -->\n";
      }
    } else {
      // try to read from cache; generate if expired
      $cacheCache = kirby()->cache( FEEDS_CONFIGURATION_PREFIX );
      $cacheName  = FEEDS_VERSION . '-' . ( $firehose == true ? "" : $collectionName . "-" ) . $what;
      if ( static::$debug == true ) {
        $cacheName .= "-debug";
      }

      if ( $eTag != "" ) {
        $magick = $cacheCache->get( $cacheName . "@et" );
        if ( $magick != null ) {
          if ( $eTag == $magick ) {
            $lastMod = $cacheCache->get( $cacheName . "@lm" );
            return null;
          }
        }
      }

      if ( $lastMod != 0 ) {
        $highwater = $cacheCache->get( $cacheName . "@lm" );
        if ( $highwater == null ) {
          $highwater = static::getCollectionLastMod( $collectionName );
        }
        if ( $highwater <= $lastMod ) {
          $eTag    = $cacheCache->get( $cacheName . "@et" );
          $lastMod = $cacheCache->get( $cacheName . "@lm" );
          return null;
        }
      }

      $r = $cacheCache->get( $cacheName );

      if ( $r == null ) {
        $highwater = 0;
        $magick    = "";
        $r         = static::generateFeedWhatever( $generator, $collectionName, $firehose, $highwater, $magick, $debug );

        $cacheCache->set( $cacheName . "@lm", $highwater, $cacheTTL );
        $cacheCache->set( $cacheName . "@et", $magick, $cacheTTL );
        $cacheCache->set( $cacheName , $r, $cacheTTL );

        if ( static::$debug == true ) {
          $r .= '<!-- Freshly generated; cached into ' . $cacheName . ' for ' . $cacheTTL . " minute(s) for reuse -->\n";
        }

        if ( $eTag == $magick ) {
          $r = null;
        }

        if ( $highwater <= $lastMod ) {
          $r = null;
        }

        $lastMod = $highwater;
        $eTag    = $magick;
      } else {
        if ( static::$debug == true ) {
          $expiresAt       = $cacheCache->expires( $cacheName );
          $secondsToExpire = ( $expiresAt - time() );

          $r .= '<!-- Retrieved ' . $cacheName . ' from cache ; expires in ' . $secondsToExpire . " seconds -->\n";
        }

        $lastMod = $cacheCache->get( $cacheName . "@lm" );

        $eTag = $cacheCache->get( $cacheName . "@et" );
      }//end if
    }//end if

    $tend = microtime( true );
    if ( static::$debug == true ) {
      $elapsed = ( $tend - $tbeg );
      $r      .= '<!-- That all took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
    }
    return $r;
  }//end getFeedWhatever()

  // G E N E R A T E - F E E D

  private static function getCollectionLastMod( string $collectionName ) : int {
    $pp = kirby()->collection( $collectionName );
    if ( $pp == null ) {
      return 0;
    }

    assert( $pp != null );
    $sortedpages = $pp->sortBy( 'date', 'asc' )->flip()->limit( 60 );

    $highwater = 0;
    $whenMod   = 0;
    $whenPub   = 0;
    foreach ( $sortedpages as $p ) {
      static::getDatesFromPage( $p, $whenMod, $whenPub );
      if ( $whenMod > $highwater ) {
        $highwater = $whenMod;
      }
    }
    return $highwater;
  }//end getCollectionLastMod()

  private static function getDatesFromPage( Page $p, int &$whenMod, int &$whenPub ) : void {
    // calculate lastmod
    if ( $p->content()->has( 'updatedat' ) ) {
      $t       = $p->content()->get( 'updatedat' );
      $whenMod = strtotime( $t );
    } else {
      if ( $p->content()->has( 'date' ) ) {
        $t       = $p->content()->get( 'date' );
        $whenMod = strtotime( $t );
      } else {
        if ( file_exists( $p->contentFile() ) ) {
          $whenMod = filemtime( $p->contentFile() );
        }
      }
    }//end if

    if ( $whenMod == false ) {
      $whenMod = 0;
    }

    if ( $p->content()->has( 'date' ) ) {
      $t       = $p->content()->get( 'date' );
      $whenPub = strtotime( $t );
    }

    if ( $whenPub == false ) {
      $whenPub = $whenMod;
    }
  }//end getDatesFromPage()

  private static function generateFeedWhatever( callable $feedPagesRunner, string $collectionName, bool $firehose, int &$lastMod, string &$eTag, bool $debug = false ) : ?string {
    $pp = kirby()->collection( $collectionName );
    if ( $pp == null ) {
      return null;
    }

    $lastMod = 0;

    $tbeg = microtime( true );

    $feedTitle = kirby()->site()->content()->get( 'title' );
    if ( $collectionName != null && $firehose == false ) {
      // not firehose, so:
      $feedTitle .= ' - ' . ucwords( $collectionName );
    }

    if ( $debug == true ) {
      $feedTitle .= ' - ' . ucwords( $feedPagesRunner[1] ); // append runner  name
    }

    $r = $feedPagesRunner( $pp, ( $firehose == false ? $collectionName : null ) , $feedTitle, $lastMod, $debug );

    $eTag = 'z13' . md5( $r );

    $tend = microtime( true );

    if ( $debug == true ) {
      $elapsed = ( $tend - $tbeg );

      $r .= '<!-- v' . static::version() . " -->\n";
      $r .= '<!-- Generation took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
      $r .= '<!-- Generated at ' . date( DATE_ATOM, (int) $tend ) . " -->\n";
    }

    return $r;
  }//end generateFeedWhatever()

  private static function generateFeedAtom( Pages $p, ?string $collectionName, string $feedTitle, int &$lastMod, bool $debug ) : string {

    $feedme = kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "atom";

    $x = "";
    static::addPagesToFeedWhatever( $p, $x, [ self::class, 'addPageToAtomFeed' ], $lastMod );

    $r  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $r .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";

    $r .= "  <title>" . $feedTitle . "</title>\n";

    $r .= "  <subtitle>" . kirby()->site()->content()->get( 'description' ) . "</subtitle>\n";

    if ( $collectionName != null ) {
      $r .= "  <category term=\" . $collectionName . \" />\n";
    }

    $r .= "  <link href=\"" . $feedme . "\" rel=\"self\" type=\"application/atom+xml\" />\n";
    $r .= "  <link href=\"" . kirby()->site()->url() . "/\" rel=\"alternate\" type=\"text/html\" />\n";
    $r .= "  <id>" . $feedme . "</id>\n";
    $r .= "  <updated>" . date( 'Y-m-d\TH:i:s', (int) $lastMod ) . "Z</updated>\n";
    $r .= "  <rights>" . kirby()->site()->content()->get( 'copyright' ) . "</rights>\n";
    $r .= "  <generator uri=\"https://github.com/omz13/kirby3-feeds\">" . strtolower( str_replace( '\\', '-', static::getNameOfClass() ) ) . "</generator>\n";

    $r .= $x;

    $r .= "</feed>\n";
    // $r .= "<!-- Atom Feed generated using https://github.com/omz13/kirby3-feeds -->\n";

    return $r;
  }//end generateFeedAtom()

  private static function generateFeedJson( Pages $p, ?string $collectionName, string $feedTitle, int &$lastMod, bool $debug ) : string {
    $r = "{\n";

    $r .= "\"version\": \"https://jsonfeed.org/version/1\",\n";
    $r .= "\"user_comment\": \"This feed allows you to read the posts from this site in any feed reader that supports the JSON Feed format. To add this feed to your reader, copy the following URL — " . kirby()->site()->url() . "/feeds/feed.json — and add it your reader.\",\n";
    $r .= "\"title\": \"" . $feedTitle . "\",\n";

    $r .= "\"home_page_url\": \"" . kirby()->site()->url() . "\",\n";
    $r .= "\"feed_url\": \"" . kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "json\",\n";

    // no "author" here ; specify at item level.

    // "icon" (ideal 512x512).
    // "favicon" (ideal 64 x 64).

    $r .= "\"items\": [\n";

    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToJsonFeed' ], $lastMod );

    $r .= "]\n";
    $r .= "}\n";

    return $r;
  }//end generateFeedJson()

  private static function generateFeedRss( Pages $p, ?string $collectionName, string $feedTitle, int &$lastMod, bool $debug ) : string {

    $x = "";
    static::addPagesToFeedWhatever( $p, $x, [ self::class, 'addPageToRssFeed' ], $lastMod );

    $r  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $r .= "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" >\n";

    $r .= "  <channel>\n";
    $r .= "    <atom:link href=\"" . kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "rss\" rel=\"self\" type=\"application/rss+xml\" />\n";
    $r .= "    <title>" . $feedTitle . "</title>\n";
    $r .= "    <link>" . kirby()->site()->url() . "/</link>\n";
    $r .= "    <description>" . kirby()->site()->content()->get( 'description' ) . "</description>\n";
//    $r .= "    <language>" . kirby()->site()->language() . "</language>\n";
    $r .= "    <copyright>" . kirby()->site()->content()->get( 'copyright' ) . "</copyright>\n";
    $r .= "    <dc:rights>" . kirby()->site()->content()->get( 'copyright' ) . "</dc:rights>\n";
    $r .= "    <generator>" . strtolower( str_replace( '\\', '-', static::getNameOfClass() ) ) . "</generator>\n";
    $r .= "    <lastBuildDate>" . date( 'D, M j G:i:s', (int) $lastMod ) . " GMT</lastBuildDate>\n";

    $ttl = static::getConfigurationForKey( 'cacheTTL' );

    if ( $ttl != 0 ) {
      $r .= "    <ttl>" . $ttl . "</ttl>\n";
    }

    $r .= $x;

    $r .= "  </channel>\n";
    $r .= "</rss>\n";

    return $r;
  }//end generateFeedRss()

  // A D D - P A G E S - T O - F E E D

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
   */
  private static function addPagesToFeedWhatever( Pages $pages, string &$r, callable $runner, int &$lastMod ) : void {
    $sortedpages = $pages->visible()->sortBy( 'date', 'asc' )->flip()->limit( 60 );

    $numPage = $sortedpages->count();

    $whenMod = 0;
    $whenPub = 0;

    foreach ( $sortedpages as $p ) {
      // static::addComment( $r, 'crunching ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [s=' . $p->status() . '] [d=' . $p->depth() . ']' );

      static::getDatesFromPage( $p, $whenMod, $whenPub );

      if ( $whenPub > $lastMod ) {
        $lastMod = $whenPub;
      }

      $runner( $p, $whenPub, $whenMod, $r, ( $numPage == 1 ? true : false ) );
      $numPage -= 1;
    }//end foreach
  }//end addPagesToFeedWhatever()

  private static function addPageToAtomFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {
    $r .= "  <entry>\n";
    $r .= '    <title>' . Xml::encode( $p->content()->get( 'title' ) ) . "</title>\n";
    $r .= "    <id>" . $p->url() . "</id>\n";

    $r .= '    <updated>' . date( 'Y-m-d\TH:i:s', $whenMod ) . "Z</updated>\n";
    $r .= '    <published>' . date( 'Y-m-d\TH:i:s' , $whenPub ) . "Z</published>\n";

    $r .= "    <link href=\"" . $p->url() . "\" />\n";
    $r .= "    <content type=\"html\" xml:lang=\"en\" xml:base=\"" . kirby()->site()->url() . "/\"><![CDATA[";
    $c  = $p->text()->kirbytext()->value();
    $r .= $c;
    $r .= "]]>\n";
    $r .= "    </content>\n";
    // $r .= "    <author><name>Staff Writer</name><email>johndoe@example.com</email></author>\n";

    $countA  = 0;
    $authors = $p->author();
    if ( $authors != null && $authors != "" ) {
      $a = $authors->toArray()['author'];
      if ( $a != null && $a[0] == '-' ) {
        // structured field
        foreach ( $authors->yaml() as $author ) {
          $countA += static::addUserToStreamForAtom( $r, $author );
        }
      } else {
        $countA += static::addUserToStreamForAtom( $r, $a );
      }
    }

    if ( $countA == 0 ) {
      $r .= "    <author>\n";
      $r .= "      <name>" . static::getConfigurationForKey( 'author' ) . "</name>\n";
      $r .= "    </author>\n";
    }

    $r .= "  </entry>\n";
  }//end addPageToAtomFeed()

  private static function addUserToStreamForAtom( string &$r, string $authorEmail ) : int {
    $user = kirby()->users()->find( $authorEmail );
    if ( $user != null ) {
      $r .= "    <author>\n";
      $r .= "      <name>" . Xml::encode( static::getNameForKirbyUser( $user ) ) . "</name>\n";

      $s = static::getUriForKirbyUser( $user );
      if ( $s != "" ) {
        $r .= "      <uri>" . $s . "</uri>\n";
      }
      $r .= "    </author>\n";
      return 1;
    }
    return 0;
  }//end addUserToStreamForAtom()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  */
  private static function addPageToJsonFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {

    // $converter = new HtmlConverter( [ 'remove_nodes' => 'aardvark' ] );
    $converter = new HtmlConverter;
    $html      = $p->text()->kirbytext()->value();
    $markdown  = $converter->convert( $html );

    $i = [
      'id'             => $p->url(),
      'url'            => $p->url(),
      'title'          => $p->content()->get( 'title' )->value(),
      'content_html'   => $html,
      'content_text'   => $markdown,
      'date_published' => date( DATE_ATOM, $whenPub ),
      'date_modified'  => date( DATE_ATOM, $whenMod ),
    ];

    $authors = $p->author();

    $aofa = [];
    if ( $authors != null && $authors != "" ) {
      $a = $authors->toArray()['author'];
      if ( $a != null && $a[0] == '-' ) {
        // structured field
        foreach ( $authors->yaml() as $author ) {
          static::addUserToArrayForJson( $author, $aofa );
        }
      } else {
        // singleton
        $user = kirby()->users()->find( $a );
        static::addUserToArrayForJson( $user, $aofa );
      }
    }

    if ( $aofa != [] ) {
      if ( count( $aofa ) == 1 ) {
        $i = array_merge( $i, [ 'author' => $aofa[0] ] );
      } else {
        // "authors" per https://github.com/brentsimmons/JSONFeed/pull/120
        $i = array_merge( $i, [ 'authors' => $aofa ] );

        // construct concatenated byline
        $byline = "";
        $count  = 0;
        foreach ( $aofa as $a ) {
          if ( next( $aofa ) == false ) {
            $byline .= " and ";
          } else {
            if ( $count > 0 ) {
              $byline .= ", ";
            }
          }
          $count  += 1;
          $byline .= $a['name'];
        }
        $i = array_merge( $i, [ 'author' => [ 'name' => $byline ] ] );
      }//end if
    } else {
      $i = array_merge( $i, [ 'author' => [ 'name' => static::getConfigurationForKey( 'author' ), 'uri' => kirby()->site()->url() ] ] );
    }//end if

    $r .= json_encode(
        $i,
        JSON_UNESCAPED_SLASHES
    );

    if ( $isLastPage != true ) {
      $r .= ",\n";
    }
  }//end addPageToJsonFeed()

  private static function addUserToArrayForJson( ?string $authorEmail, array &$a ) : void {
    if ( $authorEmail != null && $authorEmail != "" ) {
      $user = kirby()->users()->find( $authorEmail );
      if ( $user != null ) {
        $u = [ 'name' => static::getNameForKirbyUser( $user ) ];

        $s = static::getUriForKirbyUser( $user );
        if ( $s != null && $s != "" ) {
          $u = array_merge( $u, [ 'uri' => $s ] );
        }
        array_push( $a, $u );
      }
    }
  }//end addUserToArrayForJson()

  private static function addPageToRssFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {
    $r .= "  <item>\n";
    $r .= "    <title>" . Xml::encode( $p->content()->get( 'title' ) ) . "</title>\n";
    $r .= "    <link>" . $p->url() . "</link>\n";
    $r .= "    <description>\n";

    $c = $p->text()->kirbytext()->value();

    $r .= Xml::encode( $c ) . "\n";

    $r .= "    </description>\n";

    $countA  = 0;
    $authors = $p->author();
    if ( $authors != null && $authors != "" ) {
      $a = $authors->toArray()['author'];
      if ( $a != null && $a[0] == '-' ) {
        // structured field
        foreach ( $authors->yaml() as $author ) {
          $countA += static::addUserToStreamRss( $r, $author );
        }
      } else {
        // singleton
        $countA += static::addUserToStreamRss( $r, $a );
      }
    }

    if ( $countA == 0 ) {
      $r .= "      <dc:creator>" . Xml::encode( static::getConfigurationForKey( 'author' ) ) . "</dc:creator>\n";
    }

    // category
    // enclosure
    $r .= "    <guid isPermaLink=\"true\">" . $p->url() . "</guid>\n";
    $r .= "    <pubDate>" . date( 'D, M j G:i:s', $whenMod ) . " GMT</pubDate>\n";
    $r .= "    <dc:date>" . date( 'Y-m-d\TH:i:s', $whenMod ) . "Z</dc:date>\n";

    $r .= "  </item>\n";
  }//end addPageToRssFeed()

  private static function addUserToStreamRss( string &$r, string $authorEmail ) : int {
    $user = kirby()->users()->find( $authorEmail );
    if ( $user != null ) {
      $r .= "      <dc:creator>" . Xml::encode( static::getNameForKirbyUser( $user ) ) . "</dc:creator>\n";
      return 1;
    }
    return 0;
  }//end addUserToStreamRss()

  private static function getNameForKirbyUser( User $user ) : string {
    assert( $user != null );
    $username = (string) $user->name();
    if ( $username == null ) {
      $emailParts = explode( "@", (string) $user->email(), 2 );
      $username   = ucwords( $emailParts[0] );
    }
    return $username;
  }//end getNameForKirbyUser()

  private static function getUriForKirbyUser( User $user ) : string {
    assert( $user != null );
    if ( $user->website()->value() != null ) {
      return $user->website()->value();
    }

    if ( $user->twitter()->value() != null ) {
      return "https://twitter.com/" . str_replace( '@', '', $user->twitter()->value() );
    }

    if ( $user->instagram()->value() != null ) {
      return "https://instagram.com/" . str_replace( '@', '', $user->instagram()->value() );
    }

    return "";
  }//end getUriForKirbyUser()

  private static function addComment( string &$r, string $m ) : void {
    if ( static::$debug == true ) {
      $r .= '<!-- ' . $m . " -->\n";
    }
  }//end addComment()

  private static function addCommentJson( string &$r, string $m ) : void {
    if ( static::$debug == true ) {
      $r .= '\"_comment\": \"' . $m . "\"\n";
    }
  }//end addCommentJson()

  public static function snippetsFeedHeader( bool $includeAll = false ) : string {
    if ( static::isEnabled() == false ) {
      return "<!-- no syndication feeds because disabled; sorry -->\n";
    }

    $t = kirby()->site()->content()->get( 'title' );

    // firehose
    $r  = "<link rel=\"alternate\" type=\"application/atom+xml\" title=\"ATOM feed for " . $t . "\" href=\"" . kirby()->url() . "/feeds/atom\" />\n";
    $r .= "<link rel=\"alternate\" type=\"application/json\" title=\"JSON feed for " . $t . "\" href=\"" . kirby()->url() . "/feeds/json\" />\n";
    $r .= "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"RSS2 feed for " . $t . "\" href=\"" . kirby()->url() . "/feeds/rss\" />\n";

    if ( $includeAll == true ) {
      $availableCats = static::getArrayConfigurationForKey( 'categories' );
      if ( $availableCats == null || $availableCats == "" ) {
        $r .= "<!-- no category-based feeds available -->\n";
      } else {
        foreach ( $availableCats as $category ) {
          $r .= "<link rel=\"alternate\" type=\"application/atom+xml\" title=\"ATOM feed for " . $t . " - " . ucwords( $category ) . "\" href=\"" . kirby()->url() . "/feeds/" . $category . "/atom\" />\n";
          $r .= "<link rel=\"alternate\" type=\"application/json\" title=\"JSON feed for " . $t . " - " . ucwords( $category ) . "\" href=\"" . kirby()->url() . "/feeds/" . $category . "/json\" />\n";
          $r .= "<link rel=\"alternate\" type=\"application/rss+xml\" title=\"RSS2 feed for " . $t . " - " . ucwords( $category ) . "\" href=\"" . kirby()->url() . "/feeds/" . $category . "/rss\" />\n";
        }
      }
    }

    return $r;
  }//end snippetsFeedHeader()

  public static function getNameOfClass() : string {
    return static::class;
  }//end getNameOfClass()
}//end class
