<?php

if (!function_exists("get_option") || !function_exists("add_filter")) {
    die();
}

// Update options
if (isset($_POST['Submit']) && (check_admin_referer('options'))) {

    $pseudo_cron_interval = intval(@$_POST[CQFIMP_PSEUDO_CRON_INTERVAL]);
    if ($pseudo_cron_interval < 1) {
        $pseudo_cron_interval = 1;
    }

    $update_cqfimp_text = array();
    $update_cqfimp_queries[] = update_option(CQFIMP_RSS_PULL_MODE, sanitize_text_field($_POST[CQFIMP_RSS_PULL_MODE]));
    $update_cqfimp_queries[] = update_option(CQFIMP_PSEUDO_CRON_INTERVAL, abs(intval($pseudo_cron_interval)));
    $update_cqfimp_queries[] = update_option(CQFIMP_DISABLE_DUPLICATION_CONTROL, cqfimp_sanitize_checkbox(@$_POST[CQFIMP_DISABLE_DUPLICATION_CONTROL]));
    $update_cqfimp_queries[] = update_option(CQFIMP_LINK_TO_SOURCE, cqfimp_sanitize_checkbox(@$_POST[CQFIMP_LINK_TO_SOURCE]));
    $update_cqfimp_text[] = __('RSS pull mode');
    $update_cqfimp_text[] = __('Pseudo cron interval');
    $update_cqfimp_text[] = __('Feed duplication control');
    $update_cqfimp_text[] = __('Link to source');
    $i = 0;
    $text = '';
    foreach ($update_cqfimp_queries as $update_cqfimp_query) {
        if ($update_cqfimp_query) {
            $text .= $update_cqfimp_text[$i] . ' ' . __('Updated') . '<br />';
            if ($update_cqfimp_text[$i] == 'RSS pull mode' || $update_cqfimp_text[$i] == 'Pseudo cron interval') {
                wp_clear_scheduled_hook('update_by_wp_cron');
            }
        }
        $i++;
    }
    if (empty($text)) {
        $text = __('No Option Updated');
    }
// Login into contentqube
} elseif (isset($_POST['contentqube_auth']) && (check_admin_referer('contentqube_auth'))) {
    $username = sanitize_text_field($_POST['username']);
    $password = sanitize_text_field($_POST['password']);
    $response = cqfimp_login($username, $password);
    if (is_wp_error($response)) {
      $text = $response->get_error_message();
    } else {
        $res = json_decode($response['body'], true);
        if ($res['status'] === 'error') {
            $text = $res['error'];
        } else {
            update_option(CQFIMP_USERNAME, $res['account']['username']);
            update_option(CQFIMP_ACCESS_TOKEN, $res['token']['access_token']);
            $text = "Successfully logged in. \n";
            $text .= cqfimp_pull_feeds();
        }
    }
// Logout from contentqube
} elseif (isset($_POST['contentqube_control_logout']) && (check_admin_referer('contentqube_control'))) {
    update_option(CQFIMP_ACCESS_TOKEN, '');
    $text = "Successfully logged out";
// Pull feeds from contentqube
} elseif (isset($_POST['contentqube_control_cqfimp_pull_feeds']) && (check_admin_referer('contentqube_control'))) {
    $text = cqfimp_pull_feeds();
// Update feed categories
} elseif (isset($_POST['contentqube_update_feed_categories']) && (isset($_POST['categories'])) && (check_admin_referer('contentqube_update_feed_categories'))) {
    if (is_array($_POST['categories'])) {
        $categories = array_map('sanitize_text_field', $_POST['categories']);
        foreach ($categories as $i => $category) {
            $cqfimp_syndicator->feeds[$i]['options']['post_category'] = array($category);
            cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $cqfimp_syndicator->feeds, '', 'yes');
        }
        $text = "Successfully updated categories";
    }
}

$logged_in = false;
if (get_option(CQFIMP_ACCESS_TOKEN) != '') {
    $logged_in = true;
}

if (!empty($text)) {
    echo '<div id="message" class="updated fade"><p>' . $text . '</p></div>';
}
?>
<div class="wrap">

    <?php
    $problems = "";
    $upload_path = wp_upload_dir();
    if (!is_writable($upload_path['path'])) {
        $problems .= "Your " . $upload_path['path'] . " folder is not writable. You must chmod it to 777 if you want to use the \"Store Images Locally\" option.\n<br />";
    }
    if (!function_exists('mb_convert_case')) {
        $problems .= "The required <a href=\"http://php.net/manual/en/book.mbstring.php\" target=\"_blank\">mbstring</a> PHP extension is not installed. You must install it in order to make Feed Importer work properly.\n<br />";
    }
    if (!function_exists('curl_init') && ini_get('safe_mode')) {
        $problems .= "PHP variable <a href=\"http://php.net/manual/en/features.safe-mode.php\" target=\"_blank\">safe_mode</a> is enabled. You must disable it in order to make Feed Importer work properly.\n<br />";
    }
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        $problems .= "PHP variable <a href=\"http://php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen\" target=\"_blank\">allow_url_fopen</a> is disabled. You must enable it in order to make Feed Importer work properly.\n<br />";
    }
    if ($problems != "") {
        echo "<div id=\"message\" class=\"error\"><p>$problems</p></div>\n";
    }
    ?>

    <h2>ContentQube Account</h2>

<?php
    $feeds = array_filter($cqfimp_syndicator->feeds, function ($f){
        if ($f['url'] != null) {
            return $f;
        }
    });
// Select categories for feeds form
    if (count($feeds) != 0) {
        global $wpdb;
        $posts = $wpdb->get_col('SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = "_hashid"');
?>
        <form method="post" action="<?php echo cqfimp_REQUEST_URI(); ?>" name="contentqube_update_feed_categories">
            <table class="widefat">
                <thead>
                    <tr>
                        <th> Feed title</th>
                        <th> Category</th>
                    </tr>
                </thead>
<?php           foreach ($feeds as $i => $feed) {

                    echo "<tr>";
                        echo "<td>";
                            echo "{$feed['title']}";
                        echo "</td>";
                        echo "<td>";
                            $categories = get_categories( array('hide_empty' => 0 ) );
                            echo "<select name='categories[{$i}]'>";
                            if (count($feed['options']['post_category']) == 0) {
                                echo "<option disabled selected> Select a category </option>";
                            }
                            foreach ($categories as $category) {
                                $selected = ($feed['options']['post_category'][0] == $category->term_id) ? 'selected' : '';
                                echo "<option value={$category->term_id} {$selected}> {$category->name} </option>";
                            }
                            echo "</select>";
                        echo "</td>";
                    echo "</tr>";
                }
?>
                <tr>
                    <td> <input type="submit" name="contentqube_update_feed_categories" value="Update feed categories" class="button-primary"> </td>
                </tr>
            </table>
        <?php wp_nonce_field('contentqube_update_feed_categories'); ?>
        </form>
<?php
    }
?>

    <form method="post" action="<?php echo cqfimp_REQUEST_URI(); ?>" name="contentqube_auth"
        <?php if ($logged_in) {echo "style='display: none;'";} ?>>

        <table class="form-table">
            <tr valign="top">
                <th>Username:</td>
                <td><input type="text" name="username"></td>
            </tr>
            <tr valign="top">
                <th>Password:</td>
                <td><input type="password" name="password"></td>
            </tr>
            <tr valign="top">
                <td><input type="submit" name="contentqube_auth" class="button"></td>
            </tr>
        </table>

        <?php wp_nonce_field('contentqube_auth'); ?>
    </form>

    <form method="post" action="<?php echo cqfimp_REQUEST_URI(); ?>" name="contentqube_control"
        <?php if (!$logged_in) {echo "style='display: none;'";} ?>>

        <table class="form-table">
            <tr valign="top">
                <td>
                    <p> You are logged in as <?php echo get_option(CQFIMP_USERNAME); ?>. </p>
                    <input type="submit" name="contentqube_control_logout" class="button-primary" value="Logout">
                    <input type="submit" name="contentqube_control_cqfimp_pull_feeds" class="button" value="Pull Feeds">
                </td>
            </tr>
        </table>

        <?php wp_nonce_field('contentqube_control'); ?>
    </form>

</div>
