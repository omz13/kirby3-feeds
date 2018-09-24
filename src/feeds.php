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

define( 'FEEDS_VERSION', '0.0.0' );
define( 'FEEDS_CONFIGURATION_PREFIX', 'omz13.feeds' );

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings("unused")
 */
class Feeds
{
  private static $debug;
  private static $optionCACHE; // cache TTL in *minutes*; if zero or null, no cache

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

  private static function pickupOptions() : void {
    static::$optionCACHE = static::getConfigurationForKey( 'cacheTTL' );
//    static::$optionIPWTI = static::getArrayConfigurationForKey( 'includePageWhenTemplateIs' );
//    static::$optionXCWTI = static::getArrayConfigurationForKey( 'excludeChildrenWhenTemplateIs' );
//    static::$optionSPWTI = static::getArrayConfigurationForKey( 'skipPageWhenTemplateIs' );
  }//end pickupOptions()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
   */
  private static function getFeedWhatever( string $what, callable $generator, string $root, bool $debug ) : string {
    if ( $root == null ) {
      $p = kirby()->site()->page( 'blog' )->children()->visible(); // ->flip()->limit(10)
      if ( $p->count() == 0 ) {
        return "oops";
      }
    } else {
      $p = kirby()->site()->page( $root )->children()->visible(); // ->flip()->limit(10)
    }

    $tbeg = microtime( true );

    // echo "<!-- Getting " . $what . " for " . json_encode( $root ) . "-->\n";

    static::$optionCACHE = static::getConfigurationForKey( 'cacheTTL' );

    static::$debug = $debug && kirby()->option( 'debug' ) !== null && kirby()->option( 'debug' ) == true;

    // if cacheTTL disabled...
    if ( static::$optionCACHE == "" || static::$optionCACHE == "0" || static::$optionCACHE == false ) {
      $r = static::generateFeedWhatever( $generator, $p, $debug );
      if ( static::$debug == true ) {
        $r .= "<!-- Freshly generated; not cached for reuse -->\n";
      }
    } else {
      // try to read from cache; generate if expired
      $cacheCache = kirby()->cache( FEEDS_CONFIGURATION_PREFIX );

      // build list of options
      $ops  = json_encode( $root );
      $ops .= '-' . json_encode( $debug );

      $cacheName = FEEDS_VERSION . '-' . $what . '-' . md5( $ops );

      $r = $cacheCache->get( $cacheName );
      if ( $r == null ) {
        $r = static::generateFeedWhatever( $generator, $p, $debug );
        $cacheCache->set( $cacheName, $r, static::$optionCACHE );
        if ( static::$debug == true ) {
          $r .= '<!-- Freshly generated; cached into ' . $cacheName . ' for ' . static::$optionCACHE . " minute(s) for reuse -->\n";
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

    return $r;
  }//end getFeedWhatever()

  // G E T - F E E D

  public static function getFeedAtom( string $root, bool $debug = false ) : string {
    return static::getFeedWhatever( 'atom', [ self::class, 'generateFeedAtom'] , $root, $debug );
  }//end getFeedAtom()

  public static function getFeedJson( string $root, bool $debug = false ) : string {
    return static::getFeedWhatever( 'json', [ self::class, 'generateFeedJson'] , $root, $debug );
  }//end getFeedJson()

  public static function getFeedRss( string $root, bool $debug = false ) : string {
    return static::getFeedWhatever( 'rss', [ self::class, 'generateFeedRss'] , $root, $debug );
  }//end getFeedRss()

  // G E N E R A T E - F E E D

  private static function generateFeedWhatever( callable $feedPagesRunner, Pages $p, bool $debug = false ) : string {
    static::pickupOptions();

    $tbeg = microtime( true );

    $r = $feedPagesRunner( $p, $debug );

    $tend = microtime( true );
    if ( $debug == true ) {
      $elapsed = ( $tend - $tbeg );

      $r .= '<!-- v' . static::version() . " -->\n";
      $r .= '<!-- Generation took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
      $r .= '<!-- Generated at ' . date( DATE_ATOM, (int) $tend ) . " -->\n";
    }
    return $r;
  }//end generateFeedWhatever()

  private static function generateFeedAtom( Pages $p, bool $debug ) : string {
    $tnow = microtime( true );

    $r  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $r .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";

    if ( $debug ) {
      $r .= "  <title>ATOM " . kirby()->site()->content()->get( 'title' ) . "</title>\n";
    } else {
      $r .= "  <title>" . kirby()->site()->content()->get( 'title' ) . "</title>\n";
    }

    $r .= "  <subtitle>" . kirby()->site()->content()->get( 'description' ) . "</subtitle>\n";

//  $r .= "    <link href=\"" . kirby()->site()->url() . "/feeds/atom\" rel=\"self\" type=\"application/atom+xml\" />\n";
    $r .= "  <link href=\"" . kirby()->site()->url() . "/feeds/atom.xml\" rel=\"self\" type=\"application/atom+xml\" />\n";
    $r .= "  <link href=\"" . kirby()->site()->url() . "/\" rel=\"alternate\" type=\"text/html\" />\n";
    $r .= "  <id>" . kirby()->site()->url() . "/feeds/atom.xml</id>\n";
    $r .= "  <updated>" . date( 'Y-m-d\TH:i:s', (int) $tnow ) . "Z</updated>\n";
    $r .= "  <rights>" . kirby()->site()->content()->get( 'copyright' ) . "</rights>\n";

    $r .= "  <generator>Blood, Sweat, and Tears</generator>\n";

    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToAtomFeed' ] );
    $r .= "</feed>\n";
    $r .= "<!-- Atom Feed generated using https://github.com/omz13/kirby3-feeds -->\n";

    return $r;
  }//end generateFeedAtom()

  private static function generateFeedJson( Pages $p, bool $debug ) : string {
    $r = "{\n";

    $r .= "\"version\": \"https://jsonfeed.org/version/1\",\n";
    $r .= "\"user_comment\": \"This feed allows you to read the posts from this site in any feed reader that supports the JSON Feed format. To add this feed to your reader, copy the following URL — " . kirby()->site()->url() . "/feeds/feed.json — and add it your reader.\",\n";
    if ( $debug ) {
      $r .= "\"title\": \"JSON " . kirby()->site()->content()->get( 'title' ) . "\",\n";
    } else {
      $r .= "\"title\": \"" . kirby()->site()->content()->get( 'title' ) . "\",\n";
    }

    $r .= "\"home_page_url\": \"" . kirby()->site()->url() . "\",\n";
    $r .= "\"feed_url\": \"" . kirby()->site()->url() . "/feeds/feed.json\",\n";

    $r .= "\"items\": [\n";

    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToJsonFeed' ] );

    $r .= "]\n";
    $r .= "}\n";

    return $r;
  }//end generateFeedJson()

  private static function generateFeedRss( Pages $p, bool $debug ) : string {
    $tnow = microtime( true );

    $r  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $r .= "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";

    $r .= "  <channel>\n";
    $r .= "  <atom:link href=\"" . kirby()->site()->url() . "/feeds/rss.xml\" rel=\"self\" type=\"application/rss+xml\" />\n";
    if ( $debug ) {
      $r .= "    <title>RSS " . kirby()->site()->content()->get( 'title' ) . "</title>\n";
    } else {
      $r .= "    <title>" . kirby()->site()->content()->get( 'title' ) . "</title>\n";
    }
    $r .= "    <link>" . kirby()->site()->url() . "/</link>\n";
    $r .= "    <description>" . kirby()->site()->content()->get( 'description' ) . "</description>\n";
//    $r .= "    <language>" . kirby()->site()->language() . "</language>\n";
    $r .= "    <copyright>" . kirby()->site()->content()->get( 'copyright' ) . "</copyright>\n";
    $r .= "    <lastBuildDate>" . date( DATE_RSS, (int) $tnow ) . "</lastBuildDate>\n";

    if ( static::$optionCACHE != 0 ) {
      $r .= "    <ttl>" . static::$optionCACHE . "</ttl>\n";
    } else {
      $r .= "    <ttl>60</ttl>\n";
    }

    // static::addPagesToRssFeed( $p, $r );
    static::addPagesToFeedWhatever( $p, $r, [ self::class, 'addPageToRssFeed' ] );
    $r .= "  </channel>\n";
    $r .= "</rss>\n";

    return $r;
  }//end generateFeedRss()

  // A D D - P A G E S - T O - F E E D

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
   */
  private static function addPagesToFeedWhatever( Pages $pages, string &$r, callable $runner ) : void {
    $sortedpages = $pages->visible()->sortBy( 'date', 'asc' )->flip()->limit( 60 );

    $numPage = $sortedpages->count();

    foreach ( $sortedpages as $p ) {
      static::addComment( $r, 'crunching ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [s=' . $p->status() . '] [d=' . $p->depth() . ']' );

      if ( $p->hasMethod( 'issunset' ) ) {
        if ( $p->issunset() ) {
          static::addComment( $r, 'excluding ' . $p->url() . ' because isSunset' );
          continue;
        }
      }

      // exclude because, if supported,  the page is under embargo
      if ( $p->hasMethod( 'isunderembargo' ) ) {
        if ( $p->isunderembargo() ) {
          static::addComment( $r, 'excluding ' . $p->url() . ' because isUnderembargo' );
          continue;
        }
      }

      // exclude because page content field 'excludefromfeeds':
      if ( $p->content()->get( 'excludefromfeeds' ) == 'true' ) {
        static::addComment( $r, 'excluding ' . $p->url() . ' because excludefromfeeds' );
        continue;
      }

      // calculate lastmod
      $modat = 0; // default to unix epoch (jan-1-1970)
      if ( $p->content()->has( 'updatedat' ) ) {
        $t     = $p->content()->get( 'updatedat' );
        $modat = strtotime( $t );
      } else {
        if ( $p->content()->has( 'date' ) ) {
          $t     = $p->content()->get( 'date' );
          $modat = strtotime( $t );
        } else {
          if ( file_exists( $p->contentFile() ) ) {
            $modat = filemtime( $p->contentFile() );
          }
        }
      }//end if

      if ( $modat == false ) {
        $modat = 0;
      }

      $pubat = 0;

      if ( $p->content()->has( 'date' ) ) {
        $t     = $p->content()->get( 'date' );
        $pubat = strtotime( $t );
      }
      if ( $pubat == false ) {
        $pubat = $modat;
      }

      $numPage -= 1;
      $runner( $p, $modat, $r, ( $numPage == 1 ? true : false ) );
    }//end foreach
  }//end addPagesToFeedWhatever()

  private static function addPageToAtomFeed( Page $p, int $lastmod, string &$r, ?bool $isLastPage = false ) : void {
    $r .= "  <entry>\n";
    $r .= '    <title>' . $p->content()->get( 'title' ) . "</title>\n";
    $r .= "    <id>" . $p->url() . "</id>\n";

    $r .= '    <updated>' . date( 'Y-m-d\TH:i:s', $lastmod ) . "Z</updated>\n";
    $r .= '    <published>' . date( 'Y-m-d\TH:i:s' , $lastmod ) . "Z</published>\n";

    $r .= "    <link href=\"" . $p->url() . "\" />\n";
    $r .= "    <content type=\"html\" xml:lang=\"en\" xml:base=\"" . kirby()->site()->url() . "/\">\n<![CDATA[";
    $c  = $p->text()->kirbytext()->value();
    $r .= $c;
    $r .= "]]>\n";
    $r .= "    </content>\n";

    $r .= "    <author><name>Staff Writer</name><email>johndoe@example.com</email></author>\n";
    $r .= "  </entry>\n";
  }//end addPageToAtomFeed()

  private static function addPageToJsonFeed( Page $p, int $lastmod, string &$r, ?bool $isLastPage = false ) : void {

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
          'date_published' => date( DATE_ATOM, (int) $p->date()->value() ),
          'date_modified'  => date( DATE_ATOM, $lastmod ),
        ],
        JSON_UNESCAPED_SLASHES
    );

    $r .= $j;

    if ( $isLastPage != true ) {
      $r .= ",\n";
    }
  }//end addPageToJsonFeed()

  private static function addPageToRssFeed( Page $p, int $lastmod, string &$r, ?bool $isLastPage = false ) : void {
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
    $r .= "    <pubDate>" . date( DATE_RSS, $lastmod ) . "</pubDate>\n";

    $r .= "  </item>\n";
  }//end addPageToRssFeed()

  private static function addComment( string &$r, string $m ) : void {
    if ( static::$debug == true ) {
      $r .= '<!-- ' . $m . " -->\n";
    }
  }//end addComment()

  public function getNameOfClass() : string {
    return static::class;
  }//end getNameOfClass()
}//end class
