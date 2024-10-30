<?php

if (isset($_POST["alter_default_settings"]) || isset($_POST["check_for_updates"]) || isset($_POST["delete_feeds"]) || isset($_POST["feed_ids"]) || isset($_POST["delete_posts"]) || isset($_POST["feed_ids"]) || isset($_POST["delete_feeds_and_posts"]) || isset($_POST["alter_default_settings"])) {
    if (!check_admin_referer('main_page')) {
        die();
    }
} elseif (isset($_POST["update_default_settings"]) || isset($_POST["update_feed_settings"]) || isset($_POST["syndicate_feed"])){
    if (!check_admin_referer('settings')) {
        die();
    }
} elseif (isset($_POST["new_feed"]) && !check_admin_referer('new_feed')) {
    die();
}


if (!function_exists("get_option") || !function_exists("add_filter")) {
    die();
}
if (isset($_POST["update_feed_settings"]) || isset($_POST["check_for_updates"]) || isset($_POST["delete_feeds"]) || isset($_POST["delete_posts"]) || isset($_POST["feed_ids"]) || isset($_POST["syndicate_feed"]) || isset($_POST["update_default_settings"]) || isset($_POST["alter_default_settings"])) {

    if (!isset($_POST['cqfimp_token']) || ($_POST['cqfimp_token'] != get_option('CQFIMP_TOKEN'))) {
        die();
    }
}
update_option('CQFIMP_TOKEN', rand());
?>
<style type="text/css">
    div.feedimporter-ui-tabs-panel {
        margin: 0 5px 0 0px;
        padding: .5em .9em;
        height: 11em;
        width: 675px;
        overflow: auto;
        border: 1px solid #dfdfdf;
    }

    .error a {
        color: #100;
    }
</style>

<script type="text/javascript">
    function checkAll(form) {
        for (i = 0, n = form.elements.length; i < n; i++) {
            if (form.elements[i].type == "checkbox" && !(form.elements[i].getAttribute('onclick', 2))) {
                if (form.elements[i].checked == true)
                    form.elements[i].checked = false;
                else
                    form.elements[i].checked = true;
            }
        }
    }
</script>

<div class="wrap">
    <?php
    if (isset($_POST["alter_default_settings"])) {
        echo('<h2>Advanced - Default Settings</h2>');
    } else {
        echo('<h2>Advanced</h2>');
    }
    if (isset($_GET["edit-feed-id"])) {
        $cqfimp_syndicator->feedPreview($cqfimp_syndicator->fixURL($cqfimp_syndicator->feeds[(int) $_GET["edit-feed-id"]]['url']), true);
        $cqfimp_syndicator->showSettings(true, $cqfimp_syndicator->feeds[(int) $_GET["edit-feed-id"]]['options']);
    } elseif (isset($_POST["update_feed_settings"])) {
        if (mb_strlen(trim(stripslashes(htmlspecialchars($_POST['feed_title'], ENT_NOQUOTES)))) == 0) {
            $_POST['feed_title'] = "no name";
        }
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['title'] = trim(stripslashes(htmlspecialchars($_POST['feed_title'], ENT_NOQUOTES)));

        $new_url = esc_url_raw(trim($_POST["new_feed_url"]));
        $old_url = $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['url'];
        if (stripos($new_url, 'http') === 0 && $new_url != $old_url) {
            $query = "UPDATE $wpdb->postmeta SET meta_value = '". $wpdb->prepare($new_url) . "' WHERE meta_key = 'rss_source' AND meta_value = '". $wpdb->prepare($old_url) . "'";
            $wpdb->get_results($query);
            $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['url'] = $new_url;
        }

        if ((int) $_POST['update_interval'] == 0) {
            $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['interval'] = 0;
        } else {
            $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['interval'] = abs((int) $_POST['update_interval']);
        }
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['post_status'] = sanitize_text_field($_POST['post_status']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['comment_status'] = sanitize_text_field($_POST['post_comments']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['ping_status'] = sanitize_text_field($_POST['post_pings']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['post_author'] = intval($_POST['post_author']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['base_date'] = sanitize_text_field($_POST['post_publish_date']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['max_items'] = abs(intval($_POST['max_items']));
        if (isset($_POST['post_category'])) {
            $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['post_category'] = array_map('absint', @$_POST['post_category']);
        }
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['undefined_category'] = sanitize_text_field($_POST['undefined_category']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['insert_media_attachments'] = sanitize_text_field($_POST['insert_media_attachments']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['set_thumbnail'] = sanitize_text_field($_POST['set_thumbnail']);
        $cqfimp_syndicator->feeds[intval($_POST["feed_id"])]['options']['store_images'] = cqfimp_sanitize_checkbox(@$_POST['store_images']);
        cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $cqfimp_syndicator->feeds, '', 'yes');
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["check_for_updates"])) {
        $cqfimp_syndicator->show_report = true;
        $cqfimp_syndicator->syndicateFeeds(array_map('absint', $_POST["feed_ids"]), false);
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["delete_feeds"]) && isset($_POST["feed_ids"])) {
        $cqfimp_syndicator->deleteFeeds(array_map('absint', $_POST["feed_ids"]), false, true);
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["delete_posts"]) && isset($_POST["feed_ids"])) {
        $cqfimp_syndicator->deleteFeeds(array_map('absint', $_POST["feed_ids"]), true, false);
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["delete_feeds_and_posts"]) && isset($_POST["feed_ids"])) {
        $cqfimp_syndicator->deleteFeeds(array_map('absint', $_POST["feed_ids"]), true, true);
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["new_feed"]) && strlen($_POST["feed_url"]) > 0 ) {
        if ($cqfimp_syndicator->feedPreview($cqfimp_syndicator->fixURL($_POST["feed_url"]), false)) {
            $options = $cqfimp_syndicator->global_options;
            $options['undefined_category'] = 'use_global';
            $cqfimp_syndicator->showSettings(true, $options);
        } else {
            $cqfimp_syndicator->showMainPage(false);
        }
    } elseif (isset($_POST["syndicate_feed"])) {
        if (mb_strlen(trim(stripslashes(htmlspecialchars($_POST['feed_title'], ENT_NOQUOTES)))) == 0) {
            $_POST['feed_title'] = "no name";
        }

        if ((int) $_POST['update_interval'] == 0) {
            $update_interval = 0;
        } else {
            $update_interval = abs((int) $_POST['update_interval']);
        }
        $feed = array();
        $feed['title'] = trim(stripslashes(htmlspecialchars($_POST['feed_title'], ENT_NOQUOTES)));
        $feed['url'] = esc_url_raw($_POST['feed_url']);
        $feed['updated'] = 0;
        $feed['options']['interval'] = $update_interval;
        if (isset($_POST['post_category'])) {
            $feed['options']['post_category'] = array_map('absint', @$_POST['post_category']);
        }
        $feed['options']['post_status'] = sanitize_text_field($_POST['post_status']);
        $feed['options']['comment_status'] = sanitize_text_field($_POST['post_comments']);
        $feed['options']['ping_status'] = sanitize_text_field($_POST['post_pings']);
        $feed['options']['post_author'] = intval($_POST['post_author']);
        $feed['options']['base_date'] = sanitize_text_field($_POST['post_publish_date']);
        $feed['options']['undefined_category'] = sanitize_text_field($_POST['undefined_category']);
        $feed['options']['insert_media_attachments'] = sanitize_text_field($_POST['insert_media_attachments']);
        $feed['options']['set_thumbnail'] = sanitize_text_field($_POST['set_thumbnail']);
        $feed['options']['store_images'] = cqfimp_sanitize_checkbox(@$_POST['store_images']);
        $feed['options']['max_items'] = abs((int) $_POST['max_items']);
        $feed['options']['last_archive_page'] = 2;
        $id = array_push($cqfimp_syndicator->feeds, $feed);
        if ((intval($update_interval)) != 0) {
            $cqfimp_syndicator->show_report = false;
            $cqfimp_syndicator->syndicateFeeds(array($id), false);
        }
        sort($cqfimp_syndicator->feeds);
        cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $cqfimp_syndicator->feeds, '', 'yes');
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["update_default_settings"])) {
        $cqfimp_syndicator->global_options['interval'] = abs((int) $_POST['update_interval']);
        $cqfimp_syndicator->global_options['post_status'] = sanitize_text_field($_POST['post_status']);
        $cqfimp_syndicator->global_options['comment_status'] = sanitize_text_field($_POST['post_comments']);
        $cqfimp_syndicator->global_options['ping_status'] = sanitize_text_field($_POST['post_pings']);
        $cqfimp_syndicator->global_options['post_author'] = intval($_POST['post_author']);
        $cqfimp_syndicator->global_options['base_date'] = sanitize_text_field($_POST['post_publish_date']);
        $cqfimp_syndicator->global_options['max_items'] = abs((int) $_POST['max_items']);
        if (isset($_POST['post_category'])) {
            $cqfimp_syndicator->global_options['post_category'] = array_map('absint', @$_POST['post_category']);
        }
        $cqfimp_syndicator->global_options['undefined_category'] = sanitize_text_field($_POST['undefined_category']);
        $cqfimp_syndicator->global_options['insert_media_attachments'] = sanitize_text_field($_POST['insert_media_attachments']);
        $cqfimp_syndicator->global_options['set_thumbnail'] = sanitize_text_field($_POST['set_thumbnail']);
        $cqfimp_syndicator->global_options['store_images'] = cqfimp_sanitize_checkbox(@$_POST['store_images']);
        cqfimp_set_option(CQFIMP_FEED_OPTIONS, $cqfimp_syndicator->global_options, '', 'yes');
        // Update all existing feeds options (except category)
        $options = $cqfimp_syndicator->global_options;
        foreach ($cqfimp_syndicator->feeds as $id => $feed) {
            $category = $feed['options']['post_category'];
            $cqfimp_syndicator->feeds[intval($id)]['options'] = $options;
            $cqfimp_syndicator->feeds[intval($id)]['options']['post_category'] = $category;
        }
        cqfimp_set_option(CQFIMP_SYNDICATED_FEEDS, $cqfimp_syndicator->feeds, '', 'yes');
        $cqfimp_syndicator->showMainPage(false);
    } elseif (isset($_POST["alter_default_settings"])) {
        $cqfimp_syndicator->showSettings(false, $cqfimp_syndicator->global_options);
    } else {
        $cqfimp_syndicator->showMainPage(false);
    }
    ?>
</div>
