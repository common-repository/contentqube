<?php
/*
  Plugin Name: ContentQube Feed Plugin
  Version: 1.0.6
  Author: OneQube Team
  Author URI: http://www.oneqube.com/
  Description: Integrate with OneQube's innovative ContentQube platform (https://oneqube.com/softwareservices/contentqube/) and curate, publish and syndicate content to your wordpress blog.
 */

require_once dirname( __FILE__ ) .'/image-proxy.php';
require_once dirname( __FILE__ ) .'/contentqube.php';

if (!function_exists("get_option") || !function_exists("add_filter")) {
    die();
}

define('CQFIMP_MAX_CURL_REDIRECTS', 10);
define('CQFIMP_MAX_DONLOAD_ATTEMPTS', 10);
define('CQFIMP_FEED_OPTIONS', 'cxxx_feed_options');
define('CQFIMP_SYNDICATED_FEEDS', 'cxxx_syndicated_feeds');
define('CQFIMP_RSS_PULL_MODE', 'cxxx_rss_pull_mode');
define('CQFIMP_CRON_MAGIC', 'cxxx_cron_magic');
define('CQFIMP_PSEUDO_CRON_INTERVAL', 'cxxx_pseudo_cron_interval');
define('CQFIMP_LINK_TO_SOURCE', 'cxxx_link_to_source');
define('CQFIMP_API_URL', 'http://api.contentqube.com');
define('CQFIMP_LOGIN_URL', 'http://login.contentqube.com');
define('CQFIMP_ACCESS_TOKEN', 'cxxx_access_token');
define('CQFIMP_USERNAME', 'cxxx_username');

$cqfimp_banner = '<div style="background-color:#FFFFCC; padding:10px 10px 10px 10px; border:1px solid #ddd;">
                  <h3>ContentQube Feed Plugin</h3>
                </div>';


function cqfimp_update_config($value){
    $config_file = ABSPATH.'wp-config.php';
    $v_str = $value ? 'true' : 'false';
    if( file_exists($config_file) ) {
        $config_contents_arr = file($config_file);

        $i = 0;
        $found = false;
        foreach( $config_contents_arr as $line ) {
            // Update wp-config.php constant if line begins with 'define' and contains the WordPress constant
            if ( substr( trim($line), 0, 6 ) === "define" && strpos( $line, 'ALTERNATE_WP_CRON' ) !== false ) {
                $updated_constant = str_replace( array( 'true', 'false' ), $v_str, trim( $line ) );
                $config_contents_arr[$i] = $updated_constant . "\n";
                $found = true;
            }
            $i++; // current index
        }

        // Find the line containing 'stop editing!' as an entry point to insert constants.
        $j = 0;
        $entry_point = 0;
        foreach( $config_contents_arr as $ln ) {
            if ( strpos( $ln, 'stop editing!' )  ) {
                $entry_point = $j;
                break;
            }
            $j++;
        }

        // Add constant to wp-config.php
        if( false === $found ) {
            array_splice($config_contents_arr, $entry_point, 0, array( "define('ALTERNATE_WP_CRON', " . $v_str . " );\n" ));
        }

        /* Update wp-config.php. */
        $config_contents = implode( '', $config_contents_arr);
        file_put_contents( $config_file, $config_contents );
    }
}

function cqfimp_create_feed($title, $url) {
    global $cqfimp_syndicator;
    $feed = array();
    $feed['title'] = $title;
    $feed['url'] = $url;
    $feed['options'] = get_option(CQFIMP_FEED_OPTIONS);
    $id = array_push($cqfimp_syndicator->feeds, $feed);
    $cqfimp_syndicator->syndicateFeeds(array($id), false);
    sort($cqfimp_syndicator->feeds);
    cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $cqfimp_syndicator->feeds, '', 'yes');
}

function cqfimp_create_contentqube_feed($title, $category_hashid) {
    global $cqfimp_syndicator;
    $existing_feeds = array_map( function($value) {
        $matches = array();
        preg_match('/api\.contentqube\.com.*feed\/(.*)\?token=.*/', $value['url'], $matches);
        if (isset($matches[1])) {
            return $matches[1];
        }
    }, $cqfimp_syndicator->feeds);
    if (array_search($category_hashid, $existing_feeds) === false) {
        $url = esc_url_raw(CQFIMP_API_URL . "/feed/" . $category_hashid . "?token=" . get_option(CQFIMP_ACCESS_TOKEN));
        $original_url = esc_url_raw(CQFIMP_API_URL . "/original-content/feed/" . $category_hashid . "?token=" . get_option(CQFIMP_ACCESS_TOKEN));
        cqfimp_create_feed($title, $url);
        cqfimp_create_feed($title . " - Original Content", $original_url);
        return $title;
    }
}

function cqfimp_pull_feeds() {
    $response = cqfimp_get_categories(get_option(CQFIMP_ACCESS_TOKEN));
    $result = '';
    if (is_wp_error($response)) {
        $text = $response->get_error_message();
    } else {
        $res = json_decode($response['body'], true);
        if (isset($res['status']) && $res['status'] === 'error') {
            if (isset($res['error']['message'])) {
                $result = $res['error']['message'];
            } else {
                $result = "There was a problem pulling your feeds from ContentQube";
            }
        } else {
            $feeds = array();
            foreach ($res as $category) {
                array_push($feeds, cqfimp_create_contentqube_feed($category['title'], $category['hashid']));
            }
            $feeds = array_filter($feeds, function($v){return !is_null($v);} );
            if (count($feeds) != 0) {
                $result = "Successfully added feeds: " . implode(", ", $feeds);
            }
        }
    }
    // cqfimp_update_feeds();
    return $result;
}

function cqfimp_short_str($url, $max = 0) {
    $length = strlen($url);
    if ($max > 1 && $length > $max) {
        $ninety = $max * 0.9;
        $length = $length - $ninety;
        $first = substr($url, 0, -$length);
        $last = substr($url, $ninety - $max);
        $url = $first . "&#8230;" . $last;
    }
    return $url;
}

function cqfimp_sanitize_checkbox($value) {
    if ($value == 'on') {
        return $value;
    }
    return '';
}
function cqfimp_REQUEST_URI() {
    return strtok($_SERVER['REQUEST_URI'], "?") . "?" . strtok("?");
}

function cqfimp_fix_white_spaces($str) {
    return preg_replace('/\s\s+/', ' ', preg_replace('/\s\"/', ' "', preg_replace('/\s\'/', ' \'', $str)));
}

function cqfimp_delete_post_images($post_id) {
    $post = get_post($post_id, ARRAY_A);
    $wp_upload_dir = wp_upload_dir();

    preg_match_all('/<img(.+?)src=[\'\"](.+?)[\'\"](.*?)>/is', $post['post_content'] . $post['post_excerpt'], $matches);
    $image_urls = $matches[2];

    if (count($image_urls)) {
        $image_urls = array_unique($image_urls);
        foreach ($image_urls as $url) {
            @unlink(str_replace($wp_upload_dir['url'], $wp_upload_dir['path'], $url));
        }
    }
}

function cqfimp_addslash($url) {
    if ($url[strlen($url) - 1] !== "/") {
        $url .= "/";
    }
    return $url;
}

function cqfimp_file_get_contents($url, $as_array = false, $useragent = 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36') {
    global $cqfimp_last_effective_url;
    $response = wp_remote_get($url, array('user-agent' => $useragent));
    $content = '';
    if (!is_wp_error($response)) {
        $content = $response['body'];
    }
    if ($as_array) {
        $content = @explode("\n", trim($content));
    }
    return $content;
}

function cqfimp_update_options(&$options) {
    $defaults = array('interval' => 180,
        'max_items' => 5,
        'post_status' => 'publish',
        'comment_status' => 'open',
        'ping_status' => 'closed',
        'post_author' => 1,
        'base_date' => 'post',
        'undefined_category' => 'use_default',
        'synonymizer_mode' => '0',
        'post_category' => array(),
        'insert_media_attachments' => 'no',
        'set_thumbnail' => 'media_attachment',
        'store_images' => '',
	'omit_media_source' => true);

    $result = 0;

    foreach ($defaults as $key => $value) {
        if (!isset($options[$key])) {
            $options[$key] = $value;
            $result = 1;
        }
    }

    return $result;
}

function cqfimp_preset_options() {

    if (get_option(CQFIMP_SYNDICATED_FEEDS) === false) {
        cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, array(), '', 'yes');
    }

    if (get_option(CQFIMP_CRON_MAGIC) === false) {
        cqfimp_set_option(CQFIMP_CRON_MAGIC, md5(time()), '', 'yes');
    }

    if (get_option(CQFIMP_RSS_PULL_MODE) === false) {
        cqfimp_set_option(CQFIMP_RSS_PULL_MODE, 'auto', '', 'yes');
    }

    if (get_option(CQFIMP_PSEUDO_CRON_INTERVAL) === false) {
        cqfimp_set_option(CQFIMP_PSEUDO_CRON_INTERVAL, '10', '', 'yes');
    }

    if (get_option(CQFIMP_LINK_TO_SOURCE) === false) {
        cqfimp_set_option(CQFIMP_LINK_TO_SOURCE, 'auto', '', 'yes');
    }
}

function cqfimp_compare_files($file_name_1, $file_name_2) {
    $file1 = cqfimp_file_get_contents($file_name_1);
    $file2 = cqfimp_file_get_contents($file_name_2);
    if ($file1 && $file2) {
        return (md5($file1) == md5($file2));
    }
    return false;
}

function cqfimp_save_image($image_url, $preferred_name = "") {
    $wp_upload_dir = wp_upload_dir();
    if (is_writable($wp_upload_dir['path'])) {
        $image_file = cqfimp_file_get_contents($image_url);
        $parsed_url = parse_url($image_url);
        $ext = pathinfo($parsed_url['path'], PATHINFO_EXTENSION);
        $default_file_name = sanitize_file_name(sanitize_title($preferred_name) . '.' . $ext);
        if ($preferred_name != "" && strpos($default_file_name, "%") === false) {
            $file_name = $default_file_name;
        } else {
            $file_name = basename(parse_url($image_url));
        }
        if (file_exists($wp_upload_dir['path'] . '/' . $file_name)) {
            if (cqfimp_compare_files($image_url, $wp_upload_dir['path'] . '/' . $file_name)) {
                return $wp_upload_dir['url'] . '/' . $file_name;
            }
            $file_name = wp_unique_filename($wp_upload_dir['path'], $file_name);
        }
        $image_path = $wp_upload_dir['path'] . '/' . $file_name;
        $local_image_url = $wp_upload_dir['url'] . '/' . $file_name;

        if (@file_put_contents($image_path, $image_file)) {
            return $local_image_url;
        }
    }
    return $image_url;
}

function cqfimp_add_image_to_library($image_url, $title, $post_id) {
    $title = trim($title);
    $upload_dir = wp_upload_dir();
    if (!file_exists($upload_dir['path'] . '/' . basename($image_url))) {
        $image_url = cqfimp_save_image($image_url, $title);
    }
    $img_path = str_replace($upload_dir['url'], $upload_dir['path'], $image_url);
    if (file_exists($img_path) && filesize($img_path)) {
        $wp_filetype = wp_check_filetype($upload_dir['path'] . basename($image_url), null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $title),
            'post_content' => '',
            'post_parent' => $post_id,
            'guid' => $upload_dir['path'] . basename($image_url),
            'post_status' => 'inherit'
        );
        $attach_id = wp_insert_attachment($attachment, $upload_dir['path'] . '/' . basename($image_url), $post_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_dir['path'] . '/' . basename($image_url));
        wp_update_attachment_metadata($attach_id, $attach_data);
        return $attach_id;
    }
    return false;
}
function cqfimp_attach_post_thumbnail($post_id, $image_url, $title) {
    $attach_id = cqfimp_add_image_to_library($image_url, $title, $post_id);
    if ($attach_id !== false) {
        set_post_thumbnail($post_id, $attach_id);
        return $attach_id;
    }
    return false;
}
class CQFIMP_Feed_Importer {

    var $post = array();
    var $insideitem;
    var $inside_author;
    var $element_tag;
    var $tag;
    var $count;
    var $failure;
    var $posts_found;
    var $max;
    var $current_feed = array();
    var $current_feed_url = "";
    var $feeds = array();
    var $update_period;
    var $feed_title;
    var $blog_charset;
    var $feed_charset;
    var $feed_charset_convert;
    var $preview;
    var $global_options = array();
    var $edit_existing;
    var $current_category;
    var $current_post_tag;
    var $current_custom_field;
    var $current_custom_field_attr = array();
    var $generator;
    var $xml_parse_error;
    var $show_report = false;

    function fixURL($url) {
        $url = trim($url);
        if (strlen($url) > 0 && !preg_match('!^https?://.+!i', $url)) {
            $url = "http://" . $url;
        }
        return $url;
    }

    function resetPost() {
        global $cqfimp_urls_to_check;
        $this->post ['source_author'] = "";
        $this->post ['tags'] = array();
        $this->post ['post_title'] = "";
        $this->post ['post_content'] = "";
        $this->post ['post_excerpt'] = "";
        $this->post ['media_description'] = "";
        $this->post ['guid'] = "";
        $this->post ['post_date'] = time();
        $this->post ['post_date_gmt'] = time();
        $this->post ['post_name'] = "";
        $this->post ['categories'] = array();
        $this->post ['comments'] = array();
        $this->post ['media_content'] = array();
        $this->post ['media_thumbnail'] = array();
        $this->post ['enclosure_url'] = "";
        $this->post ['twitter_oembed_urls'] = array();
        $this->post ['link'] = "";
        $this->post ['options'] = array();
        $cqfimp_urls_to_check = array();
    }

    function __construct() {
        $this->blog_charset = strtoupper(get_option('blog_charset'));

        $this->global_options = get_option(CQFIMP_FEED_OPTIONS);
        if (cqfimp_update_options($this->global_options)) {
            cqfimp_set_option(CQFIMP_FEED_OPTIONS, $this->global_options, '', 'yes');
        }

        $this->feeds = get_option(CQFIMP_SYNDICATED_FEEDS);
        $changed = 0;
        for ($i = 0; $i < count($this->feeds); $i++) {
            $changed += cqfimp_update_options($this->feeds [$i]['options']);
        }
        if ($changed) {
            cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $this->feeds, '', 'yes');
        }
    }

    function parse_w3cdtf($w3cdate) {
        if (preg_match("/^\s*(\d{4})(-(\d{2})(-(\d{2})(T(\d{2}):(\d{2})(:(\d{2})(\.\d+)?)?(?:([-+])(\d{2}):?(\d{2})|(Z))?)?)?)?\s*\$/", $w3cdate, $match)) {
            list($year, $month, $day, $hours, $minutes, $seconds) = array($match[1], $match[3], $match[5], $match[7], $match[8], $match[10]);
            if (is_null($month)) {
                $month = (int) gmdate('m');
            }
            if (is_null($day)) {
                $day = (int) gmdate('d');
            }
            if (is_null($hours)) {
                $hours = (int) gmdate('H');
                $seconds = $minutes = 0;
            }
            $epoch = gmmktime($hours, $minutes, $seconds, $month, $day, $year);
            if ($match[14] != 'Z') {
                list($tz_mod, $tz_hour, $tz_min) = array($match[12], $match[13], $match[14]);
                $tz_hour = (int) $tz_hour;
                $tz_min = (int) $tz_min;
                $offset_secs = (($tz_hour * 60) + $tz_min) * 60;
                if ($tz_mod == "+") {
                    $offset_secs *= - 1;
                }
                $offset = $offset_secs;
            }
            $epoch = $epoch + $offset;
            return $epoch;
        } else {
            return -1;
        }
    }

    function parseFeed($feed_url) {
        $this->tag = "";
        $this->insideitem = false;
        $this->inside_author = false;
        $this->element_tag = "";
        $this->feed_title = "";
        $this->generator = "";
        $this->current_feed_url = $feed_url;
        $this->feed_charset_convert = "";
        $this->posts_found = 0;
        $this->failure = false;

        if ($this->preview) {
            $options = $this->global_options;
        } else {
            $options = $this->current_feed ['options'];
        }

        $feed_url = $this->current_feed_url;

        $rss_lines = cqfimp_file_get_contents($feed_url, true);
        if (!$rss_lines) {
            $rss_lines = cqfimp_file_get_contents($feed_url, true, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2 GTB5');
        }

        if (is_array($rss_lines) && count($rss_lines) > 0) {
            preg_match("/encoding[. ]?=[. ]?[\"'](.*?)[\"']/i", $rss_lines[0], $matches);
            if (isset($matches[1]) && $matches[1] != "") {
                $this->feed_charset = trim($matches[1]);
            } else {
                $this->feed_charset = "not defined";
            }

            $xml_parser = xml_parser_create();
            xml_parser_set_option($xml_parser, XML_OPTION_TARGET_ENCODING, $this->blog_charset);
            xml_set_object($xml_parser, $this);
            xml_set_element_handler($xml_parser, "startElement", "endElement");
            xml_set_character_data_handler($xml_parser, "charData");

            $this->xml_parse_error = 0;
            foreach ($rss_lines as $line) {
                if ($this->count >= $this->max || $this->failure) {
                    break;
                }

                if (!xml_parse($xml_parser, $line . "\n")) {
                    $this->xml_parse_error = xml_get_error_code($xml_parser);
                    xml_parser_free($xml_parser);
                    return false;
                }
            }
            xml_parser_free($xml_parser);
            return $this->count;
        } else {
            return false;
        }
    }

    function syndicateFeeds($feed_ids, $check_time) {
        $this->preview = false;
        $feeds_cnt = count($this->feeds);
        if (count($feed_ids) > 0) {
            if ($this->show_report) {
                if (ob_get_length()) {
                  ob_end_flush();
                  ob_implicit_flush();
                }
                echo "<div id=\"message\" class=\"updated fade\"><p>\n";
                flush();
            }
            @set_time_limit(60 * 60);
            for ($i = 0; $i < $feeds_cnt; $i++) {
                if (in_array($i, $feed_ids) && !is_object($this->feeds[$i]['updated'])) {
                    if (!$check_time || $this->getUpdateTime($this->feeds [$i]) == "asap") {
                        $this->feeds [$i]['updated'] = time();
                        cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $this->feeds, '', 'yes');
                        $this->current_feed = $this->feeds [$i];
                        $this->resetPost();
                        $this->max = (int) $this->current_feed ['options']['max_items'];
                        if ($this->show_report) {
                            echo 'Syndicating <a href="' . htmlspecialchars($this->current_feed ['url']) . '" target="_blank"><strong>' . $this->current_feed ['title'] . "</strong></a>...\n";
                            flush();
                        }
                        if ($this->current_feed ['options']['undefined_category'] == 'use_global') {
                            $this->current_feed ['options']['undefined_category'] = $this->global_options ['undefined_category'];
                        }
                        $this->count = 0;

                        $result = $this->parseFeed($this->current_feed ['url']);

                        if ($this->show_report) {
                            if ($this->count == 1) {
                                echo $this->count . " post was added";
                            } else {
                                echo $this->count . " posts were added";
                            }
                            if ($result === false) {
                                echo " [!]";
                            }
                            echo "<br />\n";
                            flush();
                        }
                    }
                }
            }
            if (isset($save_options)) {
                cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $this->feeds, '', 'yes');
            }
            if ($this->show_report) {
                echo "</p></div>\n";
            }
        }
    }

    function displayPost() {
        echo "<p><strong>Feed Title:</strong> " . $this->feed_title . "<br />\n";
        echo "<strong>URL:</strong> " . htmlspecialchars($this->current_feed_url) . "<br />\n";
        if ($this->generator != "") {
            echo "<strong>Generator:</strong> " . $this->generator . "<br />\n";
        }
        echo "<strong>Charset Encoding:</strong> " . $this->feed_charset . "</p>\n";
        echo "<strong>Title:</strong> " . cqfimp_fix_white_spaces(trim($this->post ['post_title'])) . "<br />\n";
        echo "<strong>Date:</strong> " . gmdate('Y-m-d H:i:s', (int) $this->post ['post_date']) . "<br />\n";
        if (mb_strlen(trim($this->post ['post_content'])) == 0) {
            $this->post ['post_content'] = $this->post ['post_excerpt'];
        }

        echo '<div style="overflow:auto; max-height:250px; border:1px #ccc solid; background-color:white; padding:8px; margin:8px 0 8px; 0;">' . "\n";
        echo cqfimp_fix_white_spaces(trim($this->post ['post_content']));
        echo '</div>' . "\n";

        $attachment = '';
        $video_extensions = wp_get_video_extensions();
        if ($this->post['enclosure_url'] != '') {
            $ext = mb_strtolower(pathinfo($this->post['enclosure_url'], PATHINFO_EXTENSION));
            if (in_array($ext, $video_extensions)) {
                $video = array('src' => $this->post['enclosure_url']);
                if (isset($this->post['media_thumbnail'][0])) {
                    $video['poster'] = $this->post['media_thumbnail'][0];
                }
                $attachment .= wp_video_shortcode($video);
            } else {
                $attachment .= '<img src="' . $this->post['enclosure_url'] . '">';
            }
        } else {
            if (sizeof($this->post['media_content'])) {
                    $attachment .= '<div class="media_block">';
                for ($i = 0; $i < sizeof($this->post['media_content']); $i++) {
                    $ext = mb_strtolower(pathinfo($this->post['media_content'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, $video_extensions)) {
                        $video = array('src' => $this->post['media_content'][$i]);
                        if (isset($this->post['media_thumbnail'][$i])) {
                            $video['poster'] = $this->post['media_thumbnail'][$i];
                        }
                        $attachment .= wp_video_shortcode($video);
                    } elseif (isset($this->post['media_thumbnail'][$i])) {
                            $attachment .= '<a href="' . $this->post['media_content'][$i] . '"><img src="' . $this->post['media_thumbnail'][$i] . '" class="media_thumbnail"></a>';
                }
            }
                    $attachment .= '</div>';
            } elseif (sizeof($this->post['media_thumbnail'])) {
                $attachment .= '<div class="media_block">';
                for ($i = 0; $i < sizeof($this->post['media_thumbnail']); $i++) {
                    $attachment .= '<img src="' . $this->post['media_thumbnail'][$i] . '" class="media_thumbnail">';
                }
                $attachment .= '</div>';
            }
        }
        if ($attachment != '') {
            echo "<br /><strong>Attachments </strong> (adjust the \"Media Attachments\" settings to handle them):<br /><hr />\n" . $attachment . "<hr />\n";
        }
    }

    function feedPreview($feed_url, $edit_existing = false) {
        echo "<br />\n";
        $this->edit_existing = $edit_existing;
        $this->max = 1;
        $this->preview = true;
        ?>
        <input class="button-primary" name="preview_feed" value="Preview feed" type="button" onclick="jQuery(feed_preview).toggle(); return false;">

        <table class="widefat" width="100%" id="feed_preview" style="display: none;">
            <thead>
                <tr valign="top">
                    <th>Feed Info and Preview</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php
                        $this->resetPost();
                        $this->count = 0;
                        $result = $this->parseFeed($feed_url);
                        if (!$result) {
                            echo '<div id="message"><p><strong>No feed found at</strong> <a href="http://validator.w3.org/feed/check.cgi?url=' . urlencode($feed_url) . '" target="_blank">' . htmlspecialchars($feed_url) . '</a><br />' . "\n";
                            echo 'XML parse error: ' . $this->xml_parse_error . ' (' . xml_error_string($this->xml_parse_error) . ')</p></div>';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
        return ($result != 0);
    }

    function startElement($parser, $name, $attribs) {
        $this->tag = $name;

        if ($this->insideitem && $name == "MEDIA:CONTENT" && isset($attribs["URL"])) {
            $this->post ['media_content'][] = $attribs["URL"];
        }

        if ($this->insideitem && $name == "MEDIA:THUMBNAIL") {
            $this->post ['media_thumbnail'][] = $attribs["URL"];
        }

        if ($name == "ENCLOSURE") {
            if (isset($attribs['URL'])) {
            $this->post ['enclosure_url'] = $attribs['URL'];
            }
        }

        if ($this->insideitem && $name == "LINK" && isset($attribs['HREF']) && isset($attribs ["REL"])) {
            if (stripos($attribs ["REL"], "enclosure") !== false) {
                $this->post['enclosure_url'] = $attribs['HREF'];
            } elseif (stripos($attribs ["REL"], "alternate") !== false && $this->post['link'] == '') {
                $this->post['link'] = $attribs['HREF'];
                if (isset($attribs['TITLE'])){
                  $this->post['source_name'] = $attribs['TITLE'];
                }
            } elseif (stripos($attribs ["REL"], "related") !== false && stripos($attribs ["TYPE"], "oembed/twitter") !== false) {
                $this->post['twitter_oembed_urls'][] = $attribs['HREF'];
            }
        }

        if($this->insideitem && $name == "CATEGORY" && isset($attribs['TERM'])){
          $this->current_post_tag = $attribs['TERM'];
        }

        if ($name == "ITEM" || $name == "ENTRY") {
            $this->insideitem = true;
        } elseif (!$this->insideitem && $name == "TITLE" && strlen(trim($this->feed_title)) != 0) {
            $this->tag = "";
        }

        if ($name == "AUTHOR") {
          $this->inside_author = true;
        }
    }

    function endElement($parser, $name) {
        if (($name == "ITEM" || $name == "ENTRY")) {
            $this->posts_found++;
            if (($this->count < $this->max)) {
                if ($this->preview) {
                    $this->displayPost();
                    $this->count++;
                } else {
                    $this->insertPost();
                }
                $this->resetPost();
                $this->insideitem = false;
            }
        } elseif ($name == "AUTHOR") {
          $this->inside_author = false;
        } elseif ($name == "CATEGORY") {
            $category = trim(cqfimp_fix_white_spaces($this->current_category));
            $post_tag = trim(cqfimp_fix_white_spaces($this->current_post_tag));
            if (strlen($category) > 0) {
                $this->post ['categories'][] = $category;
            }
            if (strlen($post_tag) > 0) {
                $this->post ['tags'][] = $post_tag;
            }
            $this->current_category = "";
            $this->current_post_tag = "";
        } elseif ($this->count >= $this->max) {
            $this->insideitem = false;
        }
    }

    function charData($parser, $data) {
        if ($this->insideitem) {
            switch ($this->tag) {
                case "TITLE":
                    $this->post ['post_title'] .= $data;
                    break;
                case "DESCRIPTION":
                    $this->post ['post_excerpt'] .= $data;
                    break;
                case "MEDIA:DESCRIPTION":
                    $this->post ['media_description'] .= $data;
                    break;
                case "SUMMARY":
                    $this->post ['post_excerpt'] .= $data;
                    break;
                case "LINK":
                    if (trim($data) != "") {
                        $this->post ['link'] .= trim($data);
                    }
                    break;
                case "NAME":
                    if ($this->inside_author){
                      $this->post ['source_author'] .= $data;
                    }
                    break;
                case "CONTENT:ENCODED":
                    $this->post ['post_content'] .= $data;
                    break;
                case "CONTENT":
                    if (!array_key_exists('post_content', $this->post) || is_null($this->post['post_content'])){
                      $this->post['post_content'] .= $data;
                    };
                    break;
                case "THEM:RAWCONTENT":
                    $this->post ['post_content'] .= $data;
                    break;
                case "CATEGORY":
                    $this->current_category .= trim($data);
                    break;
                case "GUID":
                    $this->post ['guid'] .= trim($data);
                    break;
                case "ID":
                    $this->post ['guid'] .= trim($data);
                    break;
                case "ATOM:ID":
                    $this->post ['guid'] .= trim($data);
                    break;
                case "DC:IDENTIFIER":
                    $this->post ['guid'] .= trim($data);
                    break;
                case "DC:DATE":
                    $this->post ['post_date'] = $this->parse_w3cdtf($data);
                    if ($this->post ['post_date']) {
                        $this->tag = "";
                    }
                    break;
                case "DCTERMS:ISSUED":
                    $this->post ['post_date'] = $this->parse_w3cdtf($data);
                    if ($this->post ['post_date']) {
                        $this->tag = "";
                    }
                    break;
                case "PUBLISHED":
                    $this->post ['post_date'] = $this->parse_w3cdtf($data);
                    if ($this->post ['post_date']) {
                        $this->tag = "";
                    }
                    break;
                case "ISSUED":
                    $this->post ['post_date'] = $this->parse_w3cdtf($data);
                    if ($this->post ['post_date']) {
                        $this->tag = "";
                    }
                    break;
                case "PUBDATE":
                    $this->post ['post_date'] = strtotime($data);
                    if ($this->post ['post_date']) {
                        $this->tag = "";
                    }
                    break;
            }
        } elseif ($this->tag == "TITLE") {
            $this->feed_title .= cqfimp_fix_white_spaces($data);
        } elseif ($this->tag == "GENERATOR") {
            $this->generator .= trim($data);
        }
    }

    function deleteFeeds($feed_ids, $delete_posts = false, $defele_feeds = false) {
        global $wpdb;
        $feeds_cnt = count($feed_ids);
        if ($feeds_cnt > 0) {

            @set_time_limit(60 * 60);
            ob_end_flush();
            ob_implicit_flush();
            echo "<div id=\"message\" class=\"updated fade\"><p>\n";
            echo "Deleting. Please wait...";
            flush();

            if ($delete_posts) {
                $to_delete = "(";
                $cnt = count($feed_ids);
                for ($i = 0; $i < $cnt; $i++) {
                    $to_delete .= "'" . $this->feeds [$feed_ids[$i]]['url'] . "', ";
                }
                $to_delete .= ")";
                $to_delete = str_replace(", )", ")", $to_delete);
                $post_ids = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rss_source' AND meta_value IN {$to_delete}");
                if (count($post_ids) > 0) {
                    foreach ($post_ids as $post_id) {
                        @wp_delete_post($post_id, false);
                        echo(str_repeat(' ', 512));
                        flush();
                    }
                }
            }
            if ($defele_feeds) {
                $feeds = array();
                $feeds_cnt = count($this->feeds);
                for ($i = 0; $i < $feeds_cnt; $i++) {
                    if (!in_array($i, $feed_ids)) {
                        $feeds[] = $this->feeds [$i];
                    }
                }
                $this->feeds = $feeds;
                sort($this->feeds);
            }
            cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $this->feeds, '', 'yes');

            echo " Done!</p></div>\n";
        }
    }

    function insertPost() {
        global $wpdb, $wp_version, $cqfimp_last_effective_url;

        if ($this->show_report) {
            echo(str_repeat(' ', 512));
            flush();
        }

        $this->post['post_title'] = trim($this->post['post_title']);

        if (mb_strlen($this->post ['post_title'])) {
            $cat_ids = $this->getCategoryIds($this->post ['categories']);
            if (empty($cat_ids) && $this->current_feed ['options']['undefined_category'] == 'drop') {
                return;
            }
            $post = array();

            if (isset($this->post['tags_input']) && is_array($this->post['tags_input'])) {
                $post['tags_input'] = $this->post['tags_input'];
            } else {
                $post['tags_input'] = array();
            }

            if (mb_strlen($this->post['guid']) < 8) {
                if (strlen($this->post['link'])) {
                    $components = parse_url($this->post ['link']);
                    $guid = 'tag:' . $components['host'];
                } else {
                    $guid = 'tag:' . md5($this->post['post_content'] . $this->post['post_excerpt']);
                }
                if ($this->post['post_date'] != "") {
                    $guid .= '://post.' . $this->post['post_date'];
                } else {
                    $guid .= '://' . md5($this->post['link'] . '/' . $this->post['post_title']);
                }
            } else {
                $guid = $this->post['guid'];
            }

            $this->post['post_title'] = cqfimp_fix_white_spaces($this->post['post_title']);
            $post['post_name'] = sanitize_title($this->post['post_title']);
            $post['guid'] = addslashes($guid);

            $posts = $wpdb->get_col('SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = "_hashid"');
            $result_dup = array_search($post['guid'], $posts);

            if ($result_dup === false) {

                if (mb_strlen(trim($this->post['post_content'])) == 0) {
                    $this->post['post_content'] = $this->post['post_excerpt'];
                }

                if ($this->current_feed ['options']['base_date'] == 'syndication') {
                    $post_date = time();
                } else {
                    $post_date = ((int) $this->post ['post_date']);
                }
                $post['post_date'] = addslashes(gmdate('Y-m-d H:i:s', $post_date + 3600 * (int) get_option('gmt_offset')));
                $post['post_date_gmt'] = addslashes(gmdate('Y-m-d H:i:s', $post_date));
                $post['post_modified'] = addslashes(gmdate('Y-m-d H:i:s', $post_date + 3600 * (int) get_option('gmt_offset')));
                $post['post_modified_gmt'] = addslashes(gmdate('Y-m-d H:i:s', $post_date));
                $post['post_status'] = $this->current_feed ['options']['post_status'];
                $post['comment_status'] = $this->current_feed ['options']['comment_status'];
                $post['ping_status'] = $this->current_feed ['options']['ping_status'];
                $post['post_type'] = 'post';
                $post['post_author'] = $this->current_feed ['options']['post_author'];

                $post['post_title'] = $this->post['post_title'];
                $post['post_content'] = $this->post['post_content'];
                $post['post_excerpt'] = $this->post['post_excerpt'];

                if (!isset($this->post['media_thumbnail'][0]) && $this->post['enclosure_url'] != '') {
                    $this->post['media_thumbnail'][0] = $this->post['enclosure_url'];
                }

                $attachment = '';
                if ($this->current_feed['options']['insert_media_attachments'] != 'no') {
                    $attachment = '';
                    $video_extensions = wp_get_video_extensions();
                    if ($this->post['enclosure_url'] != '') {
                        $ext = mb_strtolower(pathinfo($this->post['enclosure_url'], PATHINFO_EXTENSION));
                        if (in_array($ext, $video_extensions)) {
                            $attachment .= '[video src="' . $this->post['enclosure_url'] . '"';
                            if (isset($this->post['media_thumbnail'][0])) {
                                if ($this->current_feed['options']['store_images'] == 'on') {
                                    $this->post['media_thumbnail'][0] = cqfimp_save_image($this->post['media_thumbnail'][0], $this->post['post_title']);
                                }
                                $attachment .= ' poster="' . $this->post['media_thumbnail'][0] . '"';
                            }
                            $attachment .= ']';
                        } else {
                            if ($this->current_feed['options']['store_images'] == 'on') {
                                $this->post['enclosure_url'] = cqfimp_save_image($this->post['enclosure_url'], $this->post['post_title']);
                            }
                            $attachment .= '<img src="' . $this->post['enclosure_url'] . '">';
                        }
                    } else {
                        if (sizeof($this->post['media_content'])) {
                                $attachment .= '<div class="media_block">';
                            for ($i = 0; $i < sizeof($this->post['media_content']); $i++) {
                                $ext = mb_strtolower(pathinfo($this->post['media_content'][$i], PATHINFO_EXTENSION));
                                if (in_array($ext, $video_extensions)) {
                                    $attachment .= '[video src="' . $this->post['media_content'][$i] . '"';
                                    if (isset($this->post['media_thumbnail'][$i])) {
                            if ($this->current_feed ['options']['store_images'] == 'on') {
                                        $this->post['media_thumbnail'][$i] = cqfimp_save_image($this->post['media_thumbnail'][$i], $this->post['post_title']);
                            }
                                        $attachment .= ' poster="' . $this->post['media_thumbnail'][$i] . '"';
                                    }
                                    $attachment .= ']';
                                } elseif (isset($this->post['media_thumbnail'][$i])) {
                                    if ($this->current_feed['options']['store_images'] == 'on') {
                                        $this->post['media_thumbnail'][$i] = cqfimp_save_image($this->post['media_thumbnail'][$i], $this->post['post_title']);
                                    }
                                        $attachment .= '<a href="' . $this->post['media_content'][$i] . '"><img src="' . $this->post['media_thumbnail'][$i] . '" class="media_thumbnail"></a>';
                            }
                        }
                                $attachment .= '</div>';
                        } elseif (sizeof($this->post['media_thumbnail'])) {
                            $attachment .= '<div class="media_block">';
                            for ($i = 0; $i < sizeof($this->post['media_thumbnail']); $i++) {
                                $attachment .= '<img src="' . $this->post['media_thumbnail'][$i] . '" class="media_thumbnail">';
                            }
                            $attachment .= '</div>';
                        }
                    }
                }

                $attachment_status = $this->current_feed['options']['insert_media_attachments'];

                if ($this->current_feed['options']['set_thumbnail'] == 'first_image') {
                    preg_match('/<img.+?src=["\'](.+?)["\'].*?>/is', $this->post['post_content'] . $this->post['post_excerpt'] . $attachment, $matches);
                    if (isset($matches[1])) {
                        $post_thumb_src = $matches[1];
                        $image_url = cqfimp_save_image($post_thumb_src, $this->post['post_title']);
                    }
                } elseif ($this->current_feed['options']['set_thumbnail'] == 'last_image') {

                    preg_match('/<img.+?src=["\'](.+?)["\'].*?>/is', $this->post['post_content'] . $this->post['post_excerpt'] . $attachment, $matches);
                    if (count($matches) > 1) {
                        $post_thumb_src = $matches[count($matches) - 1];
                        $image_url = cqfimp_save_image($post_thumb_src, $this->post['post_title']);
                    }
                } elseif ($this->current_feed['options']['set_thumbnail'] == 'media_attachment' && isset($this->post['media_thumbnail'][0])) {
                    $post_thumb_src = trim($this->post['media_thumbnail'][0]);
                    $image_url = cqfimp_save_image($post_thumb_src, $this->post['post_title']);
                }

                if ($this->current_feed ['options']['store_images'] == 'on') {
                    preg_match_all('/<img(.+?)src=[\'\"](.+?)[\'\"](.*?)>/is', $post['post_content'] . $post['post_excerpt'], $matches);
                    $image_urls = array_unique($matches[2]);
                    $home = get_option('home');
                    for ($i = 0; $i < count($image_urls); $i++) {
                        if (strpos($image_urls[$i], $home) === false) {
                            $new_image_url = cqfimp_save_image($image_urls[$i], $post['post_title']);
                            $post['post_content'] = str_replace($image_urls[$i], $new_image_url, $post['post_content']);
                            $post['post_excerpt'] = str_replace($image_urls[$i], $new_image_url, $post['post_excerpt']);
                            if ($this->show_report) {
                                echo(str_repeat(' ', 256));
                                flush();
                            }
                        }
                    }
                }

                $title = $post['post_title'];
                $content = cqfimp_fix_white_spaces($post['post_content']);
                $excerpt = cqfimp_fix_white_spaces($post['post_excerpt']);

                $post['post_title'] = addslashes($title);
                $post['post_content'] = addslashes(cqfimp_touch_post_content($content, $attachment, $attachment_status));
                $post['post_excerpt'] = addslashes(cqfimp_touch_post_content($excerpt, $attachment, $attachment_status));

                $post_categories = array();
                if (is_array($this->current_feed ['options']['post_category'])) {
                    $post_categories = $this->current_feed ['options']['post_category'];
                }

                if (!empty($cat_ids)) {
                    $post_categories = array_merge($post_categories, $cat_ids);
                } elseif ($this->current_feed ['options']['undefined_category'] == 'use_default' && empty($post_categories)) {
                    $post_categories[] = get_option('default_category');
                }

                $post_categories = array_unique($post_categories);

                $post['post_category'] = $post_categories;

                $post['tags_input'] = array_merge($post['tags_input'], $this->post ['tags']);

                $post['tags_input'] = array_unique($post['tags_input']);

                if (!isset($allow_post_kses)) {
                    remove_filter('content_save_pre', 'wp_filter_post_kses');
                    remove_filter('excerpt_save_pre', 'wp_filter_post_kses');
                }

                $post_id = wp_insert_post($post, true);

                if (is_wp_error($post_id) && $this->show_report) {
                    $this->failure = true;
                    echo "<br /><b>Error:</b> " . $post_id->get_error_message($post_id->get_error_code()) . "<br />\n";
                } else {

                    if (method_exists('WPSEO_Meta','set_value') && count($post['tags_input']) > 0){
                      WPSEO_Meta::set_value( 'focuskw', $post['tags_input'][0], $post_id );
                    }
                    if ($this->current_feed ['options']['insert_media_attachments'] == "nelio"){
                      add_post_meta($post_id, '_nelioefi_url', $this->post ['enclosure_url']);
                    }
                    if (count($this->post['twitter_oembed_urls']) > 0){
                      add_post_meta($post_id, 'twitter_oembed_urls', $this->post ['twitter_oembed_urls']);
                    }
                    add_post_meta($post_id, 'source_name', $this->post ['source_name']);
                    add_post_meta($post_id, 'source_author', $this->post ['source_author']);

		    // generate and set post thumbnail
                    if ($this->current_feed['options']['set_thumbnail'] != 'no_thumb') {
                        if (isset($image_url)) {
                            $attach_id = cqfimp_attach_post_thumbnail($post_id, $image_url, $this->post['post_title']);
                        }
                        if (!has_post_thumbnail($post_id) || (isset($attach_id) && $attach_id === false)) {
                            @wp_delete_post($post_id, true);
                            return;
                        }
                    }

                    $this->count++;
                    $this->failure = false;
                    add_post_meta($post_id, 'rss_source', $this->current_feed ['url']);
                    add_post_meta($post_id, 'post_link', $this->post ['link']);
                    add_post_meta($post_id, '_hashid', $post['guid']);

                    if (version_compare($wp_version, '3.0', '<')) {
                        if (function_exists('wp_set_post_categories')) {
                            wp_set_post_categories($post_id, $post_categories);
                        } elseif (function_exists('wp_set_post_cats')) {
                            wp_set_post_cats('1', $post_id, $post_categories);
                        }
                    }
                }
            }
        }
    }

    function getCategoryIds($category_names) {
        global $wpdb;

        $cat_ids = array();
        foreach ($category_names as $cat_name) {
            if (function_exists('term_exists')) {
                $cat_id = term_exists($cat_name, 'category');
                if ($cat_id) {
                    $cat_ids[] = $cat_id['term_id'];
                } elseif ($this->current_feed ['options']['undefined_category'] == 'create_new') {
                    $term = wp_insert_term($cat_name, 'category');
                    $cat_ids[] = $term['term_id'];
                }
            } else {
                $cat_name_escaped = addslashes($cat_name);
                $results = $wpdb->get_results("SELECT cat_ID FROM $wpdb->categories WHERE (LOWER(cat_name) = LOWER('$cat_name_escaped'))");

                if ($results) {
                    foreach ($results as $term) {
                        $cat_ids[] = (int) $term->cat_ID;
                    }
                } elseif ($this->current_feed ['options']['undefined_category'] == 'create_new') {
                    if (function_exists('wp_insert_category')) {
                        $cat_id = wp_insert_category(array('cat_name' => $cat_name));
                    } else {
                        $cat_name_sanitized = sanitize_title($cat_name);
                        $wpdb->query("INSERT INTO $wpdb->categories SET cat_name='$cat_name_escaped', category_nicename='$cat_name_sanitized'");
                        $cat_id = $wpdb->insert_id;
                    }
                    $cat_ids[] = $cat_id;
                }
            }
        }
        if ((count($cat_ids) != 0)) {
            $cat_ids = array_unique($cat_ids);
        }
        return $cat_ids;
    }

    function categoryChecklist($post_id = 0, $descendents_and_self = 0, $selected_cats = false) {
        wp_category_checklist($post_id, $descendents_and_self, $selected_cats);
    }

    function categoryListBox($checked, $title) {
        echo '<div id="categorydiv" class="postbox">' . "\n";
        echo '<ul id="category-tabs">' . "\n";
        echo '<li class="ui-tabs-selected">' . "\n";
        echo '<p>' . $title . '</p>' . "\n";
        echo '</li>' . "\n";
        echo '</ul>' . "\n";

        echo '<div id="categories-all" class="feedimporter-ui-tabs-panel">' . "\n";
        echo '<ul id="categorychecklist" class="list:category categorychecklist form-no-clear">' . "\n";
        $this->categoryChecklist(NULL, false, $checked);
        echo '</ul>' . "\n";
        echo '</div><br />' . "\n";
        echo '</div>' . "\n";
    }

    function showSettings($islocal, $settings) {
        global $wp_version, $wpdb;
        if (version_compare($wp_version, '2.5', '<')) {
            echo "<hr>\n";
        }
        echo '<form name="feed_settings" action="' . preg_replace('/\&edit-feed-id\=[0-9]+/', '', cqfimp_REQUEST_URI()) . '" method="post">' . "\n";
        ?>

        <table class="widefat" style="margin-top: .8em" width="100%">
            <thead>
                <tr valign="top">
                    <?php
                    if ($islocal) {
                        echo "<th colspan=\"2\">Syndication settings for \"" . trim($this->feed_title) . "\"</th>";
                    } else {
                        echo "<th colspan=\"2\">Default syndication settings</th>";
                    }
                    ?>
                </tr>
            </thead>
            <tbody>

                <tr>
                    <td width="280"><?php
                        if ($islocal) {
                            echo "Syndicate this feed to the following categories";
                        } else {
                            echo "Syndicate new feeds to the following categories";
                        }
                        ?>
                    </td>
                    <td>
                        <div id="categorydiv">
                            <div id="categories-all" class="feedimporter-ui-tabs-panel">
                                <ul id="categorychecklist" class="list:category categorychecklist form-no-clear">
                                    <?php
                                    $this->categoryChecklist(NULL, false, $settings['post_category']);
                                    ?>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>

                <tr>
                    <td>Attribute all posts to the following user</td>
                    <td><select name="post_author" size="1">
                            <?php
                            $wp_user_search = $wpdb->get_results("SELECT ID, display_name FROM $wpdb->users ORDER BY ID");
                            foreach ($wp_user_search as $userid) {
                                echo '<option ' . (($settings["post_author"] == $userid->ID) ? 'selected ' : '') . 'value="' . $userid->ID . '">' . $userid->display_name . "\n";
                            }
                            ?>
                        </select></td>
                </tr>

                <tr>
                    <td><?php
                        if ($islocal) {
                            echo 'Check this feed for updates every</td><td><input type="text" name="update_interval" value="' . $settings['interval'] . '" size="4"> minutes. If you don\'t need automatic updates set this parameter to 0.';
                        } else {
                            echo 'Check syndicated feeds for updates every</td><td><input type="text" name="update_interval" value="' . $settings['interval'] . '" size="4"> minutes. If you don\'t need auto updates, just set this parameter to 0.';
                        }
                        if (defined("CQFIMP_MIN_UPDATE_TIME")) {
                            echo " <strong>This option is limited by Administrator:<strong> the update period can not be less than " . CQFIMP_MIN_UPDATE_TIME . " minutes.";
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Maximum number of posts to be syndicated from each feed at once</td>
                    <td><?php
                        echo '<input type="text" name="max_items" value="' . $settings['max_items'] . '" size="3">' . " - use low values to decrease the syndication time and improve SEO of your blog.";
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Posts status</td>
                    <td><select name="post_status" size="1">
                            <?php
                            echo '<option ' . (($settings["post_status"] == "publish") ? 'selected ' : '') . 'value="publish">Publish immediately</option>' . "\n";
                            echo '<option ' . (($settings["post_status"] == "pending") ? 'selected ' : '') . 'value="pending">Hold for review</option>' . "\n";
                            echo '<option ' . (($settings["post_status"] == "draft") ? 'selected ' : '') . 'value="draft">Save as draft</option>' . "\n";
                            echo '<option ' . (($settings["post_status"] == "private") ? 'selected ' : '') . 'value="private">Save as private</option>' . "\n";
                            ?>
                        </select></td>
                </tr>
                <tr>
                    <td>Comments</td>
                    <td><select name="post_comments" size="1">
                            <?php
                            echo '<option ' . (($settings['comment_status'] == 'open') ? 'selected ' : '') . 'value="open">Allow comments on syndicated posts</option>' . "\n";
                            echo '<option ' . (($settings['comment_status'] == 'closed') ? 'selected ' : '') . 'value="closed">Disallow comments on syndicated posts</option>' . "\n";
                            ?>
                        </select></td>
                </tr>

                <tr>
                    <td> <a href="#" onclick="jQuery(advanced_settings).toggle(); return false;">Advanced settings</a> </td>
                </tr>

                <?php
                    if ($islocal && !is_object($this->current_feed_url)) {
                        ?>
                        <tr id="advanced_settings" style="display: none;">
                            <td>Feed title:</td>
                            <td>
                                <input type="text" name="feed_title" size="132" value="<?php echo ($this->edit_existing) ? $this->feeds [(int) $_GET["edit-feed-id"]]['title'] : $this->feed_title; ?>">
                            </td>
                        </tr>
                        <tr id="advanced_settings" style="display: none;">
                            <td>Feed URL</td>
                            <td><input type="text" name="new_feed_url" size="132" value="<?php echo htmlspecialchars($this->current_feed_url); ?>"<?php
                                if (!$this->edit_existing) {
                                    echo ' disabled';
                                }
                                ?>>
                            </td>
                        </tr>
                        <?php
                    }
                ?>

                <tr id="advanced_settings" style="display: none;">
                    <td>Undefined categories</td>
                    <td><select name="undefined_category" size="1">
                            <?php
                            if ($islocal) {
                                echo '<option ' . (($settings["undefined_category"] == "use_global") ? 'selected ' : '') . 'value="use_global">Use default settings</option>' . "\n";
                            }
                            echo '<option ' . (($settings["undefined_category"] == "use_default") ? 'selected ' : '') . 'value="use_default">Post to default WordPress category</option>' . "\n";
                            echo '<option ' . (($settings["undefined_category"] == "create_new") ? 'selected ' : '') . 'value="create_new">Create new categories defined in syndicating post</option>' . "\n";
                            echo '<option ' . (($settings["undefined_category"] == "drop") ? 'selected ' : '') . 'value="drop">Do not syndicate post that doesn\'t match at least one category defined above</option>' . "\n";
                            ?>
                        </select></td>
                </tr>

                <tr id="advanced_settings" style="display: none;">
                    <td>Pings</td>
                    <td><select name="post_pings" size="1">
                            <?php
                            echo '<option ' . (($settings['ping_status'] == 'open') ? 'selected ' : '') . 'value="open">Accept pings</option>' . "\n";
                            echo '<option ' . (($settings['ping_status'] == 'closed') ? 'selected ' : '') . 'value="closed">Don\'t accept pings</option>' . "\n";
                            ?>
                        </select></td>
                </tr>
                <tr id="advanced_settings" style="display: none;">
                    <td>Base date</td>
                    <td><select name="post_publish_date" size="1">
                            <?php
                            echo '<option ' . (($settings['base_date'] == 'post') ? 'selected ' : '') . 'value="post">Get date from post</option>' . "\n";
                            echo '<option ' . (($settings['base_date'] == 'syndication') ? 'selected ' : '') . 'value="syndication">Use syndication date</option>' . "\n";
                            ?>
                        </select></td>
                </tr>

                <tr id="advanced_settings" style="display: none;">
                    <td>Media attachments</td>
                    <td><select name="insert_media_attachments" size="1">
                            <?php
                            echo '<option ' . (($settings["insert_media_attachments"] == "no") ? 'selected ' : '') . 'value="no">Do not insert attachments</option>' . "\n";
                            echo '<option ' . (($settings["insert_media_attachments"] == "top") ? 'selected ' : '') . 'value="top">Insert attachments at the top of the post</option>' . "\n";
                            echo '<option ' . (($settings["insert_media_attachments"] == "bottom") ? 'selected ' : '') . 'value="bottom">Insert attachments at the bottom of the post</option>' . "\n";
                            ?>
                        </select> - if enabled syndicator will insert media attachments (if available) into the aggregating post.
                        <p class="description">The following types of attachments are supported: <strong>&lt;media:content&gt;</strong>, <strong>&lt;media:thumbnail&gt;</strong> and <strong>&lt;enclosure&gt;.</strong> All the aggregated images will contain <strong>class="media_thumbnail"</strong> in the <strong>&lt;img&gt;</strong> tag.</p>
                    </td>
                </tr>

                <tr id="advanced_settings" style="display: none;">
                    <th scope="row">Post thumbnail</th>
                    <td><select name="set_thumbnail" size="1">
                            <?php
                            echo '<option ' . (($settings["set_thumbnail"] == "no_thumb") ? 'selected ' : '') . 'value="no_thumb">Do not generate</option>';
                            echo '<option ' . (($settings["set_thumbnail"] == "first_image") ? 'selected ' : '') . 'value="first_image">Generate from the first post image</option>';
                            echo '<option ' . (($settings["set_thumbnail"] == "last_image") ? 'selected ' : '') . 'value="last_image">Generate from the last post image</option>';
                            echo '<option ' . (($settings["set_thumbnail"] == "media_attachment") ? 'selected ' : '') . 'value="media_attachment">Generate from media attachment thumbnail</option>';
                            ?>
                        </select>
                    </td>
                </tr>

                <tr id="advanced_settings" style="display: none;">
                    <td>Store images locally</td>
                    <td><?php
                        echo '<input type="checkbox" name="store_images" ' . (($settings['store_images'] == 'on') ? 'checked ' : '') . '> - if enabled, all images from the syndicating feeds will be copied into the default uploads folder of this blog. Make sure that your /wp-content/uploads folder is writable.';
                        ?>
                    </td>
                </tr>

            </tbody>
        </table>
        <input type="hidden" name="cqfimp_token" value="<?php echo get_option('CQFIMP_TOKEN'); ?>" />
        <?php
        echo '<div class="submit">' . "\n";
        if ($islocal) {
            if ($this->edit_existing) {
                echo '<input class="button-primary" name="update_feed_settings" value="Update Feed Settings" type="submit">' . "\n";
                echo '<input class="button" name="cancel" value="Cancel" type="submit">' . "\n";
                echo '<input type="hidden" name="feed_id" value="' . (int) $_GET["edit-feed-id"] . '">' . "\n";
            } else {
                echo '<input class="button-primary" name="syndicate_feed" value="Syndicate This Feed" type="submit">' . "\n";
                echo '<input class="button" name="cancel" value="Cancel" type="submit">' . "\n";
                echo '<input type="hidden" name="feed_url" value="' . $this->current_feed_url . '">' . "\n";
            }
        } else {
            echo '<input class="button-primary" name="update_default_settings" value="Update Default Settings" type="submit">' . "\n";
        }
        ?>
        </div>
        <?php wp_nonce_field('settings'); ?>
        </form>
        <?php
    }

    function getUpdateTime($feed) {
        $time = time();
        $interval = 60 * (int) $feed['options']['interval'];
        $updated = (int) $feed['updated'];
        if ($feed['options']['interval'] == 0) {
            return "never";
        } elseif (($time - $updated) >= $interval) {
            return "asap";
        } else {
            return "in " . (int) (($interval - ($time - $updated)) / 60) . " minutes";
        }
    }

    function showMainPage($showsettings = true) {
        global $wp_version, $cqfimp_banner;
        echo $cqfimp_banner;
        echo '<form action="' . cqfimp_REQUEST_URI() . '" method="post">' . "\n";
        echo '<table class="form-table" width="100%">';
        echo "<tr><td align=\"right\">\n";
        echo 'New Feed URL: <input type="text" name="feed_url" value="" size="100">' . "\n";
        echo '&nbsp;<input class="button-primary" name="new_feed" value="Syndicate &raquo;" type="submit">' . "\n";
        echo "</td></tr>\n";
        echo "</table>\n";
        echo wp_nonce_field('new_feed');
        echo "</form>";
        echo '<form id="syndycated_feeds" action="' . cqfimp_REQUEST_URI() . '" method="post">' . "\n";
        if (count($this->feeds) > 0) {
            echo '<table class="widefat" style="margin-top: .5em" width="100%">' . "\n";
            echo '<thead>' . "\n";
            echo '<tr>' . "\n";
            echo '<th scope="row" width="3%"><input type="checkbox" onclick="checkAll(document.getElementById(\'syndycated_feeds\'));"></th>' . "\n";
            echo '<th scope="row" width="25%">Feed title</th>' . "\n";
            echo '<th scope="row" width="50%">URL</th>' . "\n";
            echo '<th scope="row" width="10%">Next update</th>' . "\n";
            echo '<th scope="row" width="12%">Last update</th>' . "\n";
            echo "</tr>\n";
            echo '</thead>' . "\n";
            for ($i = 0; $i < count($this->feeds); $i++) {
                if (is_string($this->feeds[$i]['url'])) {
                if ($i % 2) {
                    echo "<tr>\n";
                } else {
                    echo '<tr class="alternate">' . "\n";
                }
                    echo '<th align="center"><input name="feed_ids[]" value="' . $i . '" type="checkbox"></th>' . "\n";
                echo '<td>' . $this->feeds [$i]['title'] . ' [<a href="' . cqfimp_REQUEST_URI() . '&edit-feed-id=' . $i . '">edit</a>]</td>' . "\n";
                echo '<td>' . '<a href="' . $this->feeds [$i]['url'] . '" target="_blank">' . cqfimp_short_str(htmlspecialchars($this->feeds [$i]['url']), 100) . '</a></td>' . "\n";
                echo "<td>" . $this->getUpdateTime($this->feeds [$i]) . "</td>\n";
                $last_update = $this->feeds [$i]['updated'];
                if ($last_update) {
                    echo "<td>" . intval((time() - $last_update) / 60) . " minutes ago</td>\n";
                } else {
                    echo "<td> - </td>\n";
                }
                echo "</tr>\n";
                }
            }
            echo "</table>\n";
            if (version_compare($wp_version, '2.5', '<')) {
                echo "<br /><hr>\n";
            }
        }
        ?>
        <div class="submit">
            <table width="100%">
                <tr>
                    <td>
                        <div align="left">
                            <input class="button-primary" name="check_for_updates" value="Pull selected feeds now!" type="submit">
                        </div>
                    </td>
                    <td>
                        <div align="right">
                            <input class="button secondary" name="delete_feeds_and_posts" value="Delete selected feeds and syndicated posts" type="submit">
                            <input class="button secondary" name="delete_feeds" value="Delete selected feeds" type="submit">
                            <input class="button secondary" name="delete_posts" value="Delete posts syndycated from selected feeds" type="submit">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td></td>
                    <td><br />
                        <div align="right">
                            <input class="button secondary" name="alter_default_settings" value="Alter default settings" type="submit">
                        </div>
                    </td>
                </tr>
            </table>
            <?php
            update_option('CQFIMP_TOKEN', rand());
            ?>
            <input type="hidden" name="cqfimp_token" value="<?php echo get_option('CQFIMP_TOKEN'); ?>" />
            <?php wp_nonce_field('main_page'); ?>
        </form>
        </div>
        <?php
        if ($showsettings) {
            $this->showSettings(false, $this->global_options);
        }
    }
}

function cqfimp_set_option($option_name, $newvalue, $deprecated, $autoload) {
    if (get_option($option_name) === false) {
        add_option($option_name, $newvalue, $deprecated, $autoload);
    } else {
        update_option($option_name, $newvalue);
    }
}

function cqfimp_parse_special_words($content) {
    global $cqfimp_syndicator;
    return str_replace('####post_link####', $cqfimp_syndicator->post ['link'], $content);
}

function cqfimp_touch_post_content($content, $attachment = "", $attachment_status = "no") {
    global $cqfimp_syndicator;

    if ($attachment != "") {
        if ($attachment_status == "top") {
            $content = $attachment . $content;
        } elseif ($attachment_status == "bottom") {
            $content .= $attachment;
        }
    }

    return $content;
}

function cqfimp_main_menu() {
    if (function_exists('add_menu_page')) {
        add_menu_page('ContentQube', 'ContentQube', 'add_users', DIRNAME(__FILE__) . '/feedimporter-options.php');
        add_submenu_page(DIRNAME(__FILE__) . '/feedimporter-options.php', 'ContentQube RSS/Atom Syndicator', 'Advanced', 'add_users', DIRNAME(__FILE__) . '/feedimporter-syndicator.php');
        add_submenu_page(DIRNAME(__FILE__) . '/feedimporter-options.php', 'Troubleshooting FAQ', 'Troubleshooting FAQ', 'add_users',  DIRNAME(__FILE__) . '/feedimporter-troubleshooting.php');
    }
}

function cqfimp_update_feeds() {
    global $cqfimp_syndicator;
    $feed_cnt = count($cqfimp_syndicator->feeds);
    if ($feed_cnt > 0) {
        $feed_ids = range(0, $feed_cnt - 1);
        $cqfimp_syndicator->show_report = false;
        $cqfimp_syndicator->syndicateFeeds($feed_ids, true);
    }
}

function cqfimp_generic_ping($post_id) {
    global $wpdb, $cqfimp_syndicator;
    $dates = $wpdb->get_row("SELECT post_date, post_modified FROM $wpdb->posts WHERE id=$post_id");
    if ($cqfimp_syndicator->count <= 1 && $dates->post_modified == $dates->post_date && (strtotime($dates->post_modified < time()) || strtotime($dates->post_date) < time())) {
        if (function_exists('_publish_post_hook')) {
            _publish_post_hook($post_id);
        } else {
            generic_ping();
        }
    }
}

if (is_admin()) {
    cqfimp_preset_options();
}
$cqfimp_syndicator = new CQFIMP_Feed_Importer();
$cqfimp_rss_pull_mode = get_option(CQFIMP_RSS_PULL_MODE);

function cqfimp_deactivation() {
    wp_clear_scheduled_hook('update_by_wp_cron');
}

register_deactivation_hook(__FILE__, 'cqfimp_deactivation');

function cqfimp_get_cuctom_cron_interval_name() {
    return 'every ' . get_option(CQFIMP_PSEUDO_CRON_INTERVAL) . ' minutes';
}

function cqfimp_add_cuctom_cron_interval($schedules) {
    $name = cqfimp_get_cuctom_cron_interval_name();
    $schedules[$name] = array(
        'interval' => intval(get_option(CQFIMP_PSEUDO_CRON_INTERVAL)) * 60,
        'display' => __($name)
    );
    return $schedules;
}

function cqfimp_permalink($permalink) {
    global $post;
    $link = get_post_meta($post->ID, 'post_link', true);
    if (strlen($link)) {
    if (filter_var($link, FILTER_VALIDATE_URL)) {
        $permalink = $link;
    } elseif (filter_var($post->guid, FILTER_VALIDATE_URL)) {
        $permalink = $post->guid;
        }
    }
    return $permalink;
}

add_filter('cron_schedules', 'cqfimp_add_cuctom_cron_interval');

if (isset($_GET['pull-feeds']) && $_GET['pull-feeds'] == get_option(CQFIMP_CRON_MAGIC)) {
    if (!is_admin()) {
        require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
        add_action('shutdown', 'cqfimp_update_feeds');
    }
} else {
    if (is_admin()) {
        add_action('admin_menu', 'cqfimp_main_menu');
        add_action('before_delete_post', 'cqfimp_delete_post_images');
        remove_action("publish_post", "generic_ping");
        remove_action('do_pings', 'do_all_pings', 10, 1);
        remove_action('publish_post', '_publish_post_hook', 5, 1);
        add_action("publish_post", "cqfimp_generic_ping");
    } else {
        if (get_option(CQFIMP_LINK_TO_SOURCE) == 'on') {
            add_filter('post_link', 'cqfimp_permalink', 1);
        }
        if (strpos($cqfimp_rss_pull_mode, "auto") !== false) {
            if (function_exists('wp_next_scheduled')) {
                add_action('update_by_wp_cron', 'cqfimp_update_feeds');
                if (!wp_next_scheduled('update_by_wp_cron')) {
                    wp_schedule_event(time(), cqfimp_get_cuctom_cron_interval_name(), 'update_by_wp_cron');
                }
            } else {
                add_action('shutdown', 'cqfimp_update_feeds');
            }
        } else {
            if (function_exists('wp_clear_scheduled_hook') && wp_next_scheduled('update_by_wp_cron')) {
                wp_clear_scheduled_hook('update_by_wp_cron');
            }
        }
    }
}
?>
