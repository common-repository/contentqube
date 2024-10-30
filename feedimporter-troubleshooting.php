<?php

if (isset($_POST['cron'])) {
    if (!check_admin_referer('cron_settings')) {
        die();
    }
    $v = sanitize_text_field($_POST['cron']);
    if ($v === "alternate") {
        cqfimp_update_config(true);
    } elseif ($v === "default") {
        cqfimp_update_config(false);
    }
}

?>

<script type="text/javascript">
    function confirm_alternate_wp_cron() {
        result = confirm('Are you sure you want to turn ALTERNATE_WP_CRON on? This may break your other plugins');
        if (result) {
            window.location.href = "<?php echo esc_url(cqfimp_REQUEST_URI() . '&wp_cron=alternate'); ?>";
        }
    }
</script>

<div class="wrap">
    <h1> Troubleshooting FAQ</h1>
    <h3> Q: My feeds do not update automatically. What do i do? </h3>
    <h4> A: Our plugin uses <a href="https://developer.wordpress.org/plugins/cron/">wordpress cron</a> as a way to schedule your feed updates.
        You can read up on problems that may come up because of it <a href="https://wordpress.org/support/topic/scheduled-posts-still-not-working-in-282/#post-1163465">here</a>.
        You can also try to use an alternate wordpress cron by selecting that option below:
    <form method="post" action="<?php echo cqfimp_REQUEST_URI(); ?>" name="cron_settings">
        <select name="cron">
            <option <?php echo defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON === true ? '' : 'selected'; ?> value="default"> Default wp cron </option>
            <option <?php echo defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON === true ? 'selected' : ''; ?> value="alternate"> Alternate wp cron </option>
        </select>
        <button type="submit"> Save changes </button>
        <?php wp_nonce_field('cron_settings'); ?>
    </form>
    </h4>

    </div>
</div>
