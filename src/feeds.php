<?php

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
// phpcs:disable SlevomatCodingStandard.Classes.UnusedPrivateElements.UnusedMethod
// phpcs:disable SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter

namespace omz13;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Toolkit\Xml;
use League\HTMLToMarkdown\HtmlConverter;

use const DATE_ATOM;
use const DATE_RSS;
use const FEEDS_CONFIGURATION_PREFIX;
use const FEEDS_VERSION;
use const JSON_UNESCAPED_SLASHES;

use function array_key_exists;
use function date;
use function define;
use function file_exists;
use function filemtime;
use function is_array;
use function json_encode;
use function kirby;
use function md5;
use function microtime;
use function strtotime;
use function time;
use function ucwords;

define( 'FEEDS_VERSION', '0.0.0' );
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

/*
  private static function getFeedWhateverByRoot( string $what, callable $generator, string $root = "", bool $debug = false ) : string {
  $p = kirby()->site()->page( $root = "" ? static::getConfigurationForKey( 'root' ) : $root )->children()->visible(); // ->flip()->limit(10)
  if ( $p->count() == 0 ) {
      return "oops";
  }
*/

/**
* @SuppressWarnings(PHPMD.CyclomaticComplexity)
*/
  public static function getFeedWhatever( string $what, string $collectionName, bool $firehose, int &$lastMod, string &$eTag, bool $debug = false ) : ?string {

    $generator = [ self::class, 'generateFeed' . ucwords( $what ) ];

    $tbeg = microtime( true );

    // echo "<!-- Getting " . $what . " for " . json_encode( $root ) . "-->\n";

    $cacheTTL = static::getConfigurationForKey( 'cacheTTL' );

    static::$debug = $debug && kirby()->option( 'debug' ) !== null && kirby()->option( 'debug' ) == true;

    // if cacheTTL disabled...
    if ( $cacheTTL == "" || $cacheTTL == "0" || $cacheTTL == false ) {
      $r = static::generateFeedWhatever( $generator, $collectionName, $firehose, $lastMod, $debug );
      if ( static::$debug == true ) {
        $r .= "<!-- Freshly generated; not cached for reuse -->\n";
      }
    } else {
      // try to read from cache; generate if expired
      $cacheCache = kirby()->cache( FEEDS_CONFIGURATION_PREFIX );

      $cacheName = FEEDS_VERSION . '-' . ( $firehose == true ? "" : $collectionName . "-" ) . $what;

      $r = $cacheCache->get( $cacheName );
      if ( $r == null ) {
        $r = static::generateFeedWhatever( $generator, $collectionName, $firehose, $lastMod, $debug );
        $cacheCache->set( $cacheName, $r, $cacheTTL );
        if ( static::$debug == true ) {
          $r .= '<!-- Freshly generated; cached into ' . $cacheName . ' for ' . $cacheTTL . " minute(s) for reuse -->\n";
        }
      } else {
        if ( static::$debug == true ) {
          $expiresAt       = $cacheCache->expires( $cacheName );
          $secondsToExpire = ( $expiresAt - time() );

          $r .= '<!-- Retrieved ' . $cacheName . ' from cache ; expires in ' . $secondsToExpire . " seconds -->\n";
        }
      }
    }//end if

    $tend = microtime( true );
    if ( static::$debug == true ) {
      $elapsed = ( $tend - $tbeg );
      $r      .= '<!-- That all took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
    }

    $eTag = 'z13' . md5( $r );
    return $r;
  }//end getFeedWhatever()

  // G E N E R A T E - F E E D

  private static function generateFeedWhatever( callable $feedPagesRunner, string $collectionName, bool $firehose, int &$lastMod, bool $debug = false ) : ?string {
    $pp = kirby()->collection( $collectionName );
    if ( $pp == null ) {
      return null;
    }

    $lastMod = 1;

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
    $tnow = microtime( true );

    $feedme = kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "atom";

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
    $r .= "  <updated>" . date( 'Y-m-d\TH:i:s', (int) $tnow ) . "Z</updated>\n";
    $r .= "  <rights>" . kirby()->site()->content()->get( 'copyright' ) . "</rights>\n";
    $r .= "  <generator uri=\"https://github.com/omz13/\">omz13/feeds</generator>\n";

    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToAtomFeed' ], $lastMod );
    $r .= "</feed>\n";
    $r .= "<!-- Atom Feed generated using https://github.com/omz13/kirby3-feeds -->\n";

    return $r;
  }//end generateFeedAtom()

  private static function generateFeedJson( Pages $p, ?string $collectionName, string $feedTitle, int &$lastMod, bool $debug ) : string {
    $r = "{\n";

    $r .= "\"version\": \"https://jsonfeed.org/version/1\",\n";
    $r .= "\"user_comment\": \"This feed allows you to read the posts from this site in any feed reader that supports the JSON Feed format. To add this feed to your reader, copy the following URL — " . kirby()->site()->url() . "/feeds/feed.json — and add it your reader.\",\n";
    $r .= "\"title\": \"" . $feedTitle . "\",\n";

    $r .= "\"home_page_url\": \"" . kirby()->site()->url() . "\",\n";
    $r .= "\"feed_url\": \"" . kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "json\",\n";

    $r .= "\"items\": [\n";

    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToJsonFeed' ], $lastMod );

    $r .= "]\n";
    $r .= "}\n";

    return $r;
  }//end generateFeedJson()

  private static function generateFeedRss( Pages $p, ?string $collectionName, string $feedTitle, int &$lastMod, bool $debug ) : string {
    $tnow = microtime( true );

    $r  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $r .= "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";

    $r .= "  <channel>\n";
    $r .= "  <atom:link href=\"" . kirby()->site()->url() . "/feeds/" . ( $collectionName != null ? $collectionName . "/" : "" ) . "rss\" rel=\"self\" type=\"application/rss+xml\" />\n";
    $r .= "    <title>" . $feedTitle . "</title>\n";
    $r .= "    <link>" . kirby()->site()->url() . "/</link>\n";
    $r .= "    <description>" . kirby()->site()->content()->get( 'description' ) . "</description>\n";
//    $r .= "    <language>" . kirby()->site()->language() . "</language>\n";
    $r .= "    <copyright>" . kirby()->site()->content()->get( 'copyright' ) . "</copyright>\n";
    $r .= "    <lastBuildDate>" . date( DATE_RSS, (int) $tnow ) . "</lastBuildDate>\n";

    $ttl = static::getConfigurationForKey( 'cacheTTL' );

    if ( $ttl != 0 ) {
      $r .= "    <ttl>" . $ttl . "</ttl>\n";
    }

    // static::addPagesToRssFeed( $p, $r );
    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToRssFeed' ], $lastMod );
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

    foreach ( $sortedpages as $p ) {
      // static::addComment( $r, 'crunching ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [s=' . $p->status() . '] [d=' . $p->depth() . ']' );

      // calculate lastmod
      $whenMod = 0; // default to unix epoch (jan-1-1970)
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

      $whenPub = 0; // $p->date()

      if ( $p->content()->has( 'date' ) ) {
        $t       = $p->content()->get( 'date' );
        $whenPub = strtotime( $t );
      }

      if ( $whenPub == false ) {
        $whenPub = $whenMod;
      }

      if ( $whenPub > $lastMod ) {
        $lastMod = $whenPub;
      }

      $runner( $p, $whenPub, $whenMod, $r, ( $numPage == 1 ? true : false ) );
      $numPage -= 1;
    }//end foreach
  }//end addPagesToFeedWhatever()

  private static function addPageToAtomFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {
    $r .= "  <entry>\n";
    $r .= '    <title>' . $p->content()->get( 'title' ) . "</title>\n";
    $r .= "    <id>" . $p->url() . "</id>\n";

    $r .= '    <updated>' . date( 'Y-m-d\TH:i:s', $whenMod ) . "Z</updated>\n";
    $r .= '    <published>' . date( 'Y-m-d\TH:i:s' , $whenPub ) . "Z</published>\n";

    $r .= "    <link href=\"" . $p->url() . "\" />\n";
    $r .= "    <content type=\"html\" xml:lang=\"en\" xml:base=\"" . kirby()->site()->url() . "/\">\n<![CDATA[";
    $c  = $p->text()->kirbytext()->value();
    $r .= $c;
    $r .= "]]>\n";
    $r .= "    </content>\n";

    $r .= "    <author><name>Staff Writer</name><email>johndoe@example.com</email></author>\n";
    $r .= "  </entry>\n";
  }//end addPageToAtomFeed()

  private static function addPageToJsonFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {

    // $converter = new HtmlConverter( [ 'remove_nodes' => 'aardvark' ] );
    $converter = new HtmlConverter;
    $html      = $p->text()->kirbytext()->value();
    $markdown  = $converter->convert( $html );

    $j = json_encode(
        [
          'id'             => $p->url(),
          'url'            => $p->url(),
          'title'          => $p->content()->get( 'title' )->value(),
          'content_html'   => $html,
          'content_text'   => $markdown,
          'date_published' => date( DATE_ATOM, $whenPub ),
          'date_modified'  => date( DATE_ATOM, $whenMod ),
        ],
        JSON_UNESCAPED_SLASHES
    );

    $r .= $j;

    if ( $isLastPage != true ) {
      $r .= ",\n";
    }
  }//end addPageToJsonFeed()

  private static function addPageToRssFeed( Page $p, int $whenPub, int $whenMod, string &$r, ?bool $isLastPage = false ) : void {
    $r .= "  <item>\n";
    $r .= "    <title>" . $p->content()->get( 'title' ) . "</title>\n";
    $r .= "    <link>" . $p->url() . "</link>\n";
    $r .= "    <description>\n";

    $c = $p->text()->kirbytext()->value();

    $r .= Xml::encode( $c ) . "\n";

    $r .= "    </description>\n";

    // author
    // category
    // enclosure
    $r .= "    <guid isPermaLink=\"true\">" . $p->url() . "</guid>\n";
    $r .= "    <pubDate>" . date( DATE_RSS, $whenMod ) . "</pubDate>\n";

    $r .= "  </item>\n";
  }//end addPageToRssFeed()

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

  public function getNameOfClass() : string {
    return static::class;
  }//end getNameOfClass()
}//end class
