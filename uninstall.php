<?php

defined('WP_UNINSTALL_PLUGIN') || die;

$file = __DIR__.'/site-reviews.php';
require_once $file;

if (!(new GL_Plugin_Check_v4($file))->isValid()) {
    return;
}
if (!glsr_get_option('general.delete_data_on_uninstall', 'no', 'bool')) {
    return;
}

foreach (range(1, glsr()->version('major')) as $version) {
    delete_option(GeminiLabs\SiteReviews\Database\OptionManager::databaseKey($version));
}
delete_option('_glsr_trustalyze');
delete_option('widget_glsr_site-reviews');
delete_option('widget_glsr_site-reviews-form');
delete_option('widget_glsr_site-reviews-summary');
delete_option(glsr()->id.'activated');
delete_transient('glsr_migrations');
delete_transient(glsr()->id.'_cloudflare_ips');
delete_transient(glsr()->id.'_remote_post_test');
wp_cache_delete(glsr()->id);

global $wpdb;

$wpdb->query("
    OPTIMIZE TABLE {$wpdb->options}
");
$wpdb->query("
    DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_glsr_notices'
");
