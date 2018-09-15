<?php

namespace omz13;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Exception\LogicException;
use Kirby\Toolkit\Xml;

use const DATE_ATOM;
use const FEEDS_CONFIGURATION_PREFIX;
use const FEEDS_VERSION;

use function array_key_exists;
use function date;
use function define;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function in_array;
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
 */
class Feeds
{
  private static $debug;
  private static $optionCACHE; // cache TTL in *minutes*; if zero or null, no cache
  private static $optionIPWTI; // include unlisted when slug is
  private static $optionXCWTI; // include unlisted when slug is
  private static $optionSPWTI; // skip page when template is
  private static $optionNOIMG = true;
  public static $version      = FEEDS_VERSION;

  public static function ping() : string {
    return static::class . ' pong ' . static::$version;
  }//end ping()

  public static function isEnabled() : bool {
    if ( self::getConfigurationForKey( 'disable' ) == 'true' ) {
      return false;
    }

    if ( kirby()->site()->content()->get( 'xmlsitemap' ) == 'false' ) {
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

  public static function getStylesheet() : string {
    $f = file_get_contents( __DIR__ . '/../assets/xmlsitemap.xsl' );
    if ( $f == null ) {
      throw new LogicException( 'Failed to read sitemap.xsl' );
    }

    return $f;
  }//end getStylesheet()

  private static function pickupOptions() : void {
    static::$optionCACHE = static::getConfigurationForKey( 'cacheTTL' );
    static::$optionIPWTI = static::getArrayConfigurationForKey( 'includePageWhenTemplateIs' );
    static::$optionXCWTI = static::getArrayConfigurationForKey( 'excludeChildrenWhenTemplateIs' );
    static::$optionSPWTI = static::getArrayConfigurationForKey( 'skipPageWhenTemplateIs' );
  }//end pickupOptions()

  /**
   * @SuppressWarnings("Complexity")
   */
  public static function getJsonFeed( Pages $p, bool $debug = false ) : string {
    return '[]';
  }//end getJsonFeed()

  public static function getAtomFeed( Pages $p, bool $debug = false ) : string {
    static::$debug = $debug && kirby()->option( 'debug' ) !== null && kirby()->option( 'debug' ) == true;
    static::pickupOptions();

    $tbeg = microtime( true );

    // if cacheTTL disabled...
    if ( empty( static::$optionCACHE ) ) {
      $r = static::generateAtomFeed( $p, $debug );
      if ( static::$debug == true ) {
        $r .= "<!-- Freshly generated; not cached for reuse -->\n";
      }
    } else {
      // try to read from cache; generate if expired
      $cacheCache = kirby()->cache( FEEDS_CONFIGURATION_PREFIX );

      // build list of options
      $ops  = json_encode( static::$optionCACHE );
      $ops .= '-' . json_encode( static::$optionIPWTI );
      $ops .= '-' . json_encode( static::$optionXCWTI );
      $ops .= '-' . json_encode( static::$optionSPWTI );
      $ops .= '-' . json_encode( $debug );

      $cacheName = FEEDS_VERSION . '-atom-' . md5( $ops );

      $r = $cacheCache->get( $cacheName );
      if ( $r == null ) {
        $r = static::generateAtomFeed( $p, $debug );
        $cacheCache->set( $cacheName, $r, static::$optionCACHE );
        if ( static::$debug == true ) {
          $r .= '<!-- Freshly generated; cached into ' . md5( $ops ) . ' for ' . static::$optionCACHE . " minute(s) for reuse -->\n";
        }
      } else {
        if ( static::$debug == true ) {
          $expiresAt       = $cacheCache->expires( $cacheName );
          $secondsToExpire = ( $expiresAt - time() );

          $r .= '<!-- Retrieved as ' . md5( $ops ) . ' from cache ; expires in ' . $secondsToExpire . " seconds -->\n";
        }
      }
    }//end if

    $tend = microtime( true );
    if ( static::$debug == true ) {
      $elapsed = ( $tend - $tbeg );
      $r      .= '<!-- That all took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
    }

    return $r;
  }//end getAtomFeed()

  private static function generateAtomFeed( Pages $p, bool $debug = false ) : string {
    static::pickupOptions();
    $tbeg = microtime( true );

    $r  = '';
    $r .= "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $r .= "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";

    $r .= "    <title>" . kirby()->site()->content()->get( 'title' ) . "</title>\n";
    $r .= "    <subtitle>" . kirby()->site()->content()->get( 'description' ) . "</subtitle>\n";
    $r .= "    <link rel=\"alternate\" type=\"text/html\" href=\"" . kirby()->site()->url() . "\"/>\n";
    $r .= "    <link rel=\"self\" type=\"application/atom+xml\" href=\"" . kirby()->site()->url() . "/feeds/main\"/>\n";
    $r .= "    <id>" . kirby()->site()->url() . "/feeds/atom</id>\n";
    $r .= "    <updated>" . date( 'Y-m-d\TH:i:s', (int) $tbeg ) . "Z</updated>\n";
    $r .= "    <rights>" . kirby()->site()->content()->get( 'copyright' ) . "</rights>\n";

    if ( $debug == true ) {
      $r .= '<!--     includePageWhenTemplateIs = ' . json_encode( static::$optionIPWTI ) . " -->\n";
      $r .= '<!-- excludeChildrenWhenTemplateIs = ' . json_encode( static::$optionXCWTI ) . " -->\n";
      $r .= '<!--        skipPageWhenTemplateIs = ' . json_encode( static::$optionSPWTI ) . " -->\n";
    }

    static::addPagesToAtomFeed( $p, $r );
    $r .= "</feed>\n";
    $r .= "<!-- Atom Feed generated using https://github.com/omz13/kirby3-feeds -->\n";

    $tend = microtime( true );
    if ( $debug == true ) {
      $elapsed = ( $tend - $tbeg );

      $r .= '<!-- v' . static::$version . " -->\n";
      $r .= '<!-- Generation took ' . ( 1000 * $elapsed ) . " microseconds -->\n";
      $r .= '<!-- Generated at ' . date( DATE_ATOM, (int) $tend ) . " -->\n";
    }

    return $r;
  }//end generateAtomFeed()

  /**
  * @SuppressWarnings(PHPMD.CyclomaticComplexity)
  * @SuppressWarnings(PHPMD.NPathComplexity)
  * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
   */
  private static function addPagesToAtomFeed( Pages $pages, string &$r ) : void {
    $sortedpages = $pages->sortBy( 'url', 'asc' );
    foreach ( $sortedpages as $p ) {
      static::addComment( $r, 'crunching ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [s=' . $p->status() . '] [d=' . $p->depth() . ']' );

      // exclude because template not in the inclusion list:
      if ( isset( static::$optionIPWTI ) && in_array( $p->intendedTemplate(), static::$optionIPWTI ) == false && in_array( $p->intendedTemplate(), static::$optionSPWTI ) == false ) {
        static::addComment( $r, 'excluding ' . $p->url() . ' because not in set includePageWhenTemplateIs (' . $p->intendedTemplate() . ')' );
        continue;
      }

      if ( $p->status() == 'unlisted' && ! $p->isHomePage() ) {
        static::addComment( $r, 'excluding ' . $p->url() . ' because unlisted' );
        continue;
      }

      // exclude because, if supported, the page is sunset:
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

      if ( isset( static::$optionSPWTI ) && in_array( $p->intendedTemplate(), static::$optionSPWTI ) == true ) {
        static::addComment( $r, 'skipping entry for ' . $p->url() . ' because skipPageWhenTemplateIs (' . $p->intendedTemplate() . ')' );
      } else {
        $r .= "<entry>\n";
        $r .= '<title>' . $p->content()->get( 'title' ) . "</title>\n";
        $r .= "<link href=\"" . $p->url() . "\"/>\n";
        $r .= "<link rel=\"alternate\" type=\"text/html\" href=\"" . $p->url() . "\"/>\n";
        $r .= "<id>urn:uuid:1225c695-cfb8-4ebb-aaaa-80da344efa6a</id>\n";
        $r .= "<author><name>John Doe</name><email>johndoe@example.com</email></author>\n";

        // priority for determining the last modified date: updatedat, then date, then filestamp
        $lastmod = 0; // default to unix epoch (jan-1-1970)
        if ( $p->content()->has( 'updatedat' ) ) {
          $t       = $p->content()->get( 'updatedat' );
          $lastmod = strtotime( $t );
        } else {
          if ( $p->content()->has( 'date' ) ) {
            $t       = $p->content()->get( 'date' );
            $lastmod = strtotime( $t );
          } else {
            if ( file_exists( $p->contentFile() ) ) {
              $lastmod = filemtime( $p->contentFile() );
            }
          }
        }//end if

        // phpstan picked up that Parameter #2 $timestamp of function date expects int, int|false given.
        // this might happen if strtotime or filemtime above fails.
        // so a big thumbs-up to phpstan.
        if ( $lastmod == false ) {
          $lastmod = 0;
        }

        // set modified date to be last date vis-a-vis when file modified /content embargo time / content date
        $r .= '  <updated>' . date( 'Y-m-d\TH:i:s', $lastmod ) . "Z</updated>\n";
        $r .= '  <published>' . date( 'Y-m-d\TH:i:s', $lastmod ) . "Z</updated>\n";

        if ( static::$optionNOIMG != true ) {
          static::addImagesFromPageToSitemap( $p, $r );
        }

        // TODO:   <author>

        $r .= "  <content type=\"html\" xml:base=\"" . kirby()->site()->url() . "/linked/\" xml:lang=\"en\"><![CDATA[\n";

        $r .= $p->content()->get( 'text' )->kirbytext();

        $r .= "]]></content>\n";

        $r .= "</entry>\n";
      }//end if

      if ( $p->children() !== null ) {
        // jump into the children, unless the current page's template is in the exclude-its-children set
        if ( isset( static::$optionXCWTI ) && in_array( $p->intendedTemplate(), static::$optionXCWTI ) ) {
          static::addComment( $r, 'ignoring children of ' . $p->url() . ' because excludeChildrenWhenTemplateIs (' . $p->intendedTemplate() . ')' );
          if ( static::$optionNOIMG != true ) {
            static::addImagesToAtomFeed( $p->children(), $r );
          }
        } else {
          static::addPagesToAtomFeed( $p->children(), $r );
        }
      }//end if
    }//end foreach
  }//end addPagesToAtomFeed()

  private static function addComment( string &$r, string $m ) : void {
    if ( static::$debug == true ) {
      $r .= '<!-- ' . $m . " -->\n";
    }
  }//end addComment()

  private static function addImagesFromPageToAtomFeed( Page $page, string &$r ) : void {
    foreach ( $page->images() as $i ) {
      $r .= "  <image:image>\n";
      $r .= '    <image:loc>' . $i->url() . "</image:loc>\n";
      $r .= "  </image:image>\n";
    }
  }//end addImagesFromPageToAtomFeed()

  private static function addImagesToAtomFeed( Pages $pages, string &$r ) : void {
    foreach ( $pages as $p ) {
      static::addComment( $r, 'imagining ' . $p->url() . ' [it=' . $p->intendedTemplate() . '] [d=' . $p->depth() . ']' );
      static::addImagesFromPageToSitemap( $p, $r );
    }
  }//end addImagesToAtomFeed()

  public function getNameOfClass() : string {
    return static::class;
  }//end getNameOfClass()
}//end class
