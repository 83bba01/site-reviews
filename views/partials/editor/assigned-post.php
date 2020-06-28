<?php defined('WPINC') || die; ?>

<span class="glsr-assigned-post">
    <input type="hidden" name="assigned_to[]" value="{{ data.id }}">
    <button type="button" class="glsr-remove-button">
        <span class="glsr-remove-icon" aria-hidden="true"></span>
        <span class="screen-reader-text"><?= _x('Remove assignment', 'admin-text', 'site-reviews'); ?></span>
    </button>
    <a href="{{ data.url }}">{{ data.title }}</a>
</span>
