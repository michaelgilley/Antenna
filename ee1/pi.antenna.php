<?php

/**
 * Antenna Plugin
 * Copyright Matt Weinberg, www.VectorMediaGroup.com
 */

$plugin_info = array(
	'pi_name'			=> 'Antenna',
	'pi_version'		=> '1.6.2',
	'pi_author'			=> 'Matt Weinberg',
	'pi_author_url'		=> 'http://www.VectorMediaGroup.com',
	'pi_description'	=> 'Returns the embed code and various pieces of metadata for YouTube Videos',
	'pi_usage'			=> Antenna::usage()
);

/**
 * The Antenna plugin will generate the YouTube or Vimeo embed
 * code for a single YouTube or Vimeo clip. It will also give
 * you back the Author, their URL, the video title,
 * and a thumbnail.
 *
 * @package Antenna
 */
class Antenna {
	public $return_data = '';
	public $cache_name = 'antenna_urls';
	public $refresh_cache = 20160;			// in minutes
	public $cache_expired = FALSE;
	
	/**
	 * PHP4/EE compatibility
	 *
	 * @return void
	 */
	public function Antenna() 
	{
		$this->__construct();
	}
	
	/**
	 * Takes a YouTube or Vimeo url and parameters
	 * does a cURL request and returns the 
	 * the embed code and metadata to EE 
	 *
	 * @return void
	 */
	public function __construct()
	{
		global $TMPL, $FNS;

		$tagdata = $TMPL->tagdata;

		//Check to see if it's a one-off tag or a pair
		$mode = ($tagdata) ? "pair" : "single";
		
		$plugin_vars = array(
			"title"         =>  "video_title",
			"html"          =>  "embed_code",
			"author_name"   =>  "video_author",
			"author_url"    =>  "video_author_url",
			"thumbnail_url" =>  "video_thumbnail"
		);
		
		$video_data = array();

		foreach ($plugin_vars as $var) {
			$video_data[$var] = false;
		}

		//Deal with the parameters
		$video_url = ($TMPL->fetch_param('url')) ?  html_entity_decode($TMPL->fetch_param('url')) : false;
		$max_width = ($TMPL->fetch_param('max_width')) ? "&maxwidth=" . $TMPL->fetch_param('max_width') : "";
		$max_height = ($TMPL->fetch_param('max_height')) ? "&maxheight=" . $TMPL->fetch_param('max_height') : "";
		
		//Some optional Vimeo parameters
		$vimeo_byline = ($TMPL->fetch_param('vimeo_byline') == "false") ? "&byline=false" : "";
		$vimeo_title = ($TMPL->fetch_param('vimeo_title') == "false") ? "&title=false" : "";
		$vimeo_autoplay = ($TMPL->fetch_param('vimeo_autoplay') == "true") ? "&autoplay=true" : "";

		// If it's not YouTube or Vimeo, bail
		if (strpos($video_url, "youtube.com/") !== FALSE) {
			$url = "http://www.youtube.com/oembed?format=json&url=";
		} else if (strpos($video_url, "vimeo.com/") !== FALSE) {
			$url = "http://vimeo.com/api/oembed.json?url=";
		} else {
			$tagdata = $FNS->var_swap($tagdata, $video_data);
			$this->return_data = $tagdata;
			return;
		}

		$url .= urlencode($video_url) . $max_width . $max_height . $vimeo_byline . $vimeo_title . $vimeo_autoplay;

		// checking if url has been cached
		$cached_url = $this->_check_cache($url);
		
		if ($this->cache_expired OR ! $cached_url)
		{
			//Create the info and header variables
			list($video_info, $video_header) = $this->curl($url);

			if (!$video_info || $video_header != "200") 
			{
				$tagdata = $this->EE->functions->var_swap($tagdata, $video_data);
				$this->return_data = $tagdata;
				return;
			}
			
			// write the data to cache
			$this->_write_cache($video_info, $url);
		}
		else
		{
			$video_info = $cached_url;
		}

		//Decode the cURL data
		$video_info = json_decode($video_info);

		//Handle a single tag
		if ($mode == "single") 
		{
			$this->return_data = $video_info->html;
			return;
		}

		//Handle multiple tag with tagdata
		foreach ($plugin_vars as $key => $var) 
		{
			if (isset($video_info->$key)) 
			{
				$video_data[$var] = $video_info->$key;
			}
		}

		$tagdata = $FNS->prep_conditionals($tagdata, $video_data);

		foreach ($video_data as $key => $value) 
		{
			$tagdata = $TMPL->swap_var_single($key, $value, $tagdata);
		}

		$this->return_data = $tagdata;

		return;
	}
	
	/**
	 * Generates a CURL request to the YouTube URL
	 * to make sure that 
	 *
	 * @param string $vid_url The YouTube URL
	 * @return void
	 */
	public function curl($vid_url) {
	    $curl = curl_init();
	
		// Our cURL options
		$options = array(
			CURLOPT_URL =>  $vid_url, 
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_CONNECTTIMEOUT => 10,
		); 
		
		curl_setopt_array($curl, $options);

		$video_info = curl_exec($curl);
		$video_header = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		//Close the request
		curl_close($curl);

		return array($video_info, $video_header);
	}
	
	/**
	 * Check Cache
	 *
	 * Check for cached data
	 *
	 * @access	public
	 * @param	string
	 * @param	bool	Allow pulling of stale cache file
	 * @return	mixed - string if pulling from cache, FALSE if not
	 */
	function _check_cache($url)
	{	
		// Check for cache directory
		
		$dir = APPPATH.'cache/'.$this->cache_name.'/';
		
		if ( ! @is_dir($dir))
		{
			return FALSE;
		}
		
		// Check for cache file
		
        $file = $dir.md5($url);
		
		if ( ! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
		{
			return FALSE;
		}
		       
		flock($fp, LOCK_SH);
                    
		$cache = @fread($fp, filesize($file));
                    
		flock($fp, LOCK_UN);
        
		fclose($fp);

        // Grab the timestamp from the first line

		$eol = strpos($cache, "\n");
		
		$timestamp = substr($cache, 0, $eol);
		$cache = trim((substr($cache, $eol)));
		
		if ( time() > ($timestamp + ($this->refresh_cache * 60)) )
		{
			$this->cache_expired = TRUE;
		}
		
        return $cache;
	}
	
	/**
	 * Write Cache
	 *
	 * Write the cached data
	 *
	 * @access	public
	 * @param	string
	 * @return	void
	 */
	function _write_cache($data, $url)
	{
		// Check for cache directory
		
		$dir = APPPATH.'cache/'.$this->cache_name.'/';

		if ( ! @is_dir($dir))
		{
			if ( ! @mkdir($dir, 0777))
			{
				return FALSE;
			}
			
			@chmod($dir, 0777);            
		}
		
		// add a timestamp to the top of the file
		$data = time()."\n".$data;
		
		
		// Write the cached data
		
		$file = $dir.md5($url);
	
		if ( ! $fp = @fopen($file, 'wb'))
		{
			return FALSE;
		}

		flock($fp, LOCK_EX);
		fwrite($fp, $data);
		flock($fp, LOCK_UN);
		fclose($fp);
        
		@chmod($file, 0777);
	}
	
	/**
	 * ExpressionEngine plugins require this for displaying
	 * usage in the control panel
	 *
	 * @return void
	 */
    public function usage() 
	{
		ob_start();
?>
Antenna is a plugin that will generate the exact, most up-to-date YouTube or Vimeo embed code available. It also gives you access to the video's title, its author, the author's YouTube URL, and a thumbnail. All you have to do is pass it a single URL. 

You can also output various pieces of metadata about the YouTube video.

{exp:antenna url='{the_youtube_or_vimeo_url}' max_width="232" max_height="323"}
    {embed_code}
    {video_title}
    {video_author}
    {video_author_url}
    {video_thumbnail}
	
	{if embed_code}
		It worked! {embed_code}
	{if:else}
		No video to display here.
	{/if}
	
{/exp:antenna}

Set the max_width and/or max_height for whatever size your website requires. The video will be resized to be within those dimensions, and will stay at the correct proportions.

If used as a single tag, it returns the HTML embed/object code for the video. If used as a pair, you get access to the 5 variables above and can use them in conditionals.

<?php
		$buffer = ob_get_contents();
		
		ob_end_clean(); 
	
		return $buffer;
	}
}
?>