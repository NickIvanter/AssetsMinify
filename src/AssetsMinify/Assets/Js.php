<?php
namespace AssetsMinify\Assets;

use Assetic\Filter\JSMinFilter;
use Assetic\Asset\StringAsset;

/**
 * Js Factory.
 * Manages the scripts (JS and Coffeescript)
 *
 * @author Alessandro Carbone <ale.carbo@gmail.com>
 */
class Js extends Factory {

	protected $assets = array(),
			  $files  = array(),
			  $mtimes = array(),
			  $localized = array();

	public function setFilters() {
		$this->setFilter('JSMin', new JSMinFilter);
	}

	/**
	 * Takes all the scripts enqueued to the theme and removes them from the queue
	 */
	public function extract() {
		global $wp_scripts;

		if ( empty($wp_scripts->queue) )
			return;

		// Trigger dependency resolution
		$wp_scripts->all_deps($wp_scripts->queue);

		foreach( $wp_scripts->to_do as $key => $handle ) {

			if ( $this->manager->isFileExcluded($wp_scripts->registered[$handle]->src) )
				continue;

			$script_path = $this->guessPath($wp_scripts->registered[$handle]->src);

			// Script didn't match any case (plugin, theme or wordpress locations)
			if( $script_path === false )
				continue;

			$where = 'footer';

			if ( empty($wp_scripts->registered[$handle]->extra) && empty($wp_scripts->registered[$handle]->args) )
				$where = 'header';

			if ( empty($script_path) || !is_file($script_path) )
				continue;

			$ext = 'js';
			$parts = explode('.', $script_path);
			if ( count($parts) > 0 ) {
				$ext = $parts[ count($parts) - 1 ];
			}

			if ( !empty($wp_scripts->registered[$handle]->extra['data']) ) {
				$this->localized[$where][] = $wp_scripts->registered[$handle]->extra['data'];
			}

			$this->assets[$where][$ext]['files'][$handle] = $script_path;
			$this->assets[$where][$ext]['mtimes'][$handle] = filemtime($script_path);

			//Removes scripts from the queue so this plugin will be
			//responsible to include all the scripts except other domains ones.
			$wp_scripts->dequeue( $handle );

			//Move the handle to the done array.
			$wp_scripts->done[] = $handle;
			unset($wp_scripts->to_do[$key]);
		}
	}

	/**
	 * Takes all the JavaScript and manages the queue to compress them
	 *
	 * @param string $where The page's place to dump the scripts in (header or footer)
	 */
	public function generate($where) {
		foreach ( $this->assets[$where] as $ext => $content ) {
			$mtime = md5( json_encode($content) );
			$cachefile = "$where-$ext-$mtime.js";

			if ( !$this->cache->fs->has( $cachefile ) ) {
				$class = "AssetsMinify\\Assets\\Js\\" . ucfirst($ext);
				new $class( $content['files'], $cachefile, $this );
			}

			$key = "$ext-am-generated";
			$this->files[$where][$key] = $this->cache->getPath() . $cachefile;
			$this->mtimes[$where][$key] = filemtime($this->files[$where][$key]);
		}

		if ( empty($this->files[$where]) )
			return false;

		$mtime = md5( json_encode($this->mtimes[$where]) );

		//Saves the asseticized header scripts
		if ( !$this->cache->fs->has( "$where-$mtime.js" ) ) {
			$this->cache->fs->set( "$where-$mtime.js", $this->createAsset( $this->files[$where], $this->getFilters() )->dump() );
		}

		//Prints <script> inclusion in the page
		$this->dumpScriptData( $where );
		$async = false;
		if( $where != 'header' && get_option('am_async_flag', 1) )
			$async = true;
		$this->dump( "$where-$mtime.js", $async );
	}

	/**
	 * Prints <script> tag to include the JS
	 *
	 * @param string $filename The filename to dump
	 * @param boolean $async Tells if the script is to include with async attribute (default=true)
	 */
	protected function dump( $filename, $async = true ) {
		echo "<script type='text/javascript' src='" . $this->cache->getUrl() . $filename . "'" . ($async ? " async" : "") . "></script>";
	}

	/**
	 * Combines the script data from all minified scripts
	 *
	 * @param string $where The page's place to dump the scripts in (header or footer)
	 * @return string The script to include within the page
	 */
	protected function buildScriptData( $where ) {
		$data = '';

		if ( empty($this->localized[$where]) )
			return '';

		foreach ($this->localized[$where] as $script) {
			$data .= $script;
		}

		//var_Dump($this->localized);
		$asset = new StringAsset( $data, array(new JSMinFilter) );

		return $asset->dump();
	}

	/**
	 * Prints <script> tags with addtional script data and i10n
	 *
	 * @param string $where The page's place to dump the scripts in (header or footer)
	 */
	protected function dumpScriptData( $where ) {
		$data = $this->buildScriptData( $where );

		if ( empty($data) )
			return false;

		echo "<script type='text/javascript'>\n"; // CDATA and type='text/javascript' is not needed for HTML 5
		echo "/* <![CDATA[ */\n";
		echo "$data\n";
		echo "/* ]]> */\n";
		echo "</script>\n";
	}
}