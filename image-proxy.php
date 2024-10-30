<?php
class CQFIMP_ImageProxy {

	/** Hook WordPress
	*	@return void
	*/
	public function __construct(){
    add_filter('the_content', array($this, 'fix_content'), 0);
		add_filter('query_vars', array($this, 'add_query_vars'), 0);
		add_action('parse_request', array($this, 'sniff_requests'), 0);
		add_action('init', array($this, 'add_endpoint'), 0);
	}

	/** Add public query vars
	*	@param array $vars List of current public query vars
	*	@return array $vars
	*/
	public function add_query_vars($vars){
		$vars[] = '__api';
		$vars[] = 'imageurl';
		return $vars;
	}

	/** Add API Endpoint
	*	This is where the magic happens - brush up on your regex skillz
	*	@return void
	*/
	public function add_endpoint(){
		add_rewrite_rule('^image-proxy/imageurl=(.*)','index.php?__api=1&imageurl=$matches[1]','top');
	}

	/**	Sniff Requests
	*	This is where we hijack all API requests
	* 	If $_GET['__api'] is set, we kill WP and serve up pug bomb awesomeness
	*	@return die if API request
	*/
	public function sniff_requests(){
		global $wp;
		if(isset($wp->query_vars['__api'])){
			$this->handle_request();
			exit;
		}
	}

	/** Handle Requests
	*	This is where we send off for an intense pug bomb package
	*	@return void
	*/
	protected function handle_request(){
    global $wp;
    $imageurl = $wp->query_vars['imageurl'];

    if (!$imageurl){
      http_response_code(404);
      print('Not Found');
      exit;
    }

    $response = wp_remote_get($imageurl);
    if (is_wp_error($response)) {
        http_response_code(500);
        print($response->get_error_message());
        exit;
    }
    $response_content = $response['body'];
    $response_headers = $response['headers'];
    // (re-)send the headers
    foreach ($response_headers as $key => $response_header) {
        header($key . ': ' . $response_header, false);
    }
    // finally, output the content
    print($response_content);
    exit;
	}

  /**
	* fix images, embeds, iframes in content
	* @param string $content
	* @return string
	*/
	public function fix_content($content) {
    if (is_ssl() && trim(get_post_meta(get_the_ID(), 'post_link', true ))){
  		static $searches = array(
  			'#<(?:img) .*?srcset=[\'"]\Khttp://[^\'"]+#i',		// fix image and iframe elements
        '#<(?:img) .*?src=[\'"]\Khttp://[^\'"]+#i'		// fix image and iframe elements
  		);
  		$content = preg_replace_callback($searches, array(__CLASS__, 'fixContent_src_callback'), $content);
    }
		return $content;
	}

	/**
	* callback for fixContent() regex replace for URLs
	* @param array $matches
	* @return string
	*/
	public static function fixContent_src_callback($matches) {
    return str_replace('http://', '/image-proxy/imageurl=http://', $matches[0]);
	}

}
new CQFIMP_ImageProxy();
?>
