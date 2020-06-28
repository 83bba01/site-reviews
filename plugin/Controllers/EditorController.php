<?php

namespace GeminiLabs\SiteReviews\Controllers;

use GeminiLabs\SiteReviews\Application;
use GeminiLabs\SiteReviews\Controllers\EditorController\Customization;
use GeminiLabs\SiteReviews\Controllers\EditorController\Labels;
use GeminiLabs\SiteReviews\Controllers\EditorController\Metaboxes;
use GeminiLabs\SiteReviews\Controllers\ListTableColumns\ColumnValueReviewType;
use GeminiLabs\SiteReviews\Database;
use GeminiLabs\SiteReviews\Defaults\CreateReviewDefaults;
use GeminiLabs\SiteReviews\Helpers\Arr;
use GeminiLabs\SiteReviews\Helpers\Str;
use GeminiLabs\SiteReviews\Modules\Html\Builder;
use GeminiLabs\SiteReviews\Modules\Html\Template;
use GeminiLabs\SiteReviews\Modules\Notice;
use GeminiLabs\SiteReviews\Review;
use WP_Post;

class EditorController extends Controller
{
    /**
     * @param array $settings
     * @return array
     * @filter wp_editor_settings
     */
    public function filterEditorSettings($settings)
    {
        return glsr(Customization::class)->filterEditorSettings(
            Arr::consolidate($settings)
        );
    }

    /**
     * Modify the WP_Editor html to allow autosizing without breaking the `editor-expand` script.
     * @param string $html
     * @return string
     * @filter the_editor
     */
    public function filterEditorTextarea($html)
    {
        return glsr(Customization::class)->filterEditorTextarea($html);
    }

    /**
     * @param bool $protected
     * @param string $metaKey
     * @param string $metaType
     * @return bool
     * @filter is_protected_meta
     */
    public function filterIsProtectedMeta($protected, $metaKey, $metaType)
    {
        if ('post' == $metaType && glsr()->post_type == get_post_type()) {
            $values = glsr(CreateReviewDefaults::class)->unguarded();
            $values = Arr::prefixKeys($values);
            if (array_key_exists($metaKey, $values)) {
                $protected = false;
            }
        }
        return $protected;
    }

    /**
     * @param array $messages
     * @return array
     * @filter post_updated_messages
     */
    public function filterUpdateMessages($messages)
    {
        return glsr(Labels::class)->filterUpdateMessages(
            Arr::consolidate($messages)
        );
    }

    /**
     * @return void
     * @action add_meta_boxes_{Application::POST_TYPE}
     */
    public function registerMetaBoxes($post)
    {
        add_meta_box(Application::ID.'_assigned_to', _x('Assigned To', 'admin-text', 'site-reviews'), [$this, 'renderAssignedToMetabox'], null, 'side');
        add_meta_box(Application::ID.'_review', _x('Details', 'admin-text', 'site-reviews'), [$this, 'renderDetailsMetaBox'], null, 'side');
        if ('local' === glsr(Query::class)->review($post->ID)->type) {
            add_meta_box(Application::ID.'_response', _x('Respond Publicly', 'admin-text', 'site-reviews'), [$this, 'renderResponseMetaBox'], null, 'normal');
        }
    }

    /**
     * @return void
     * @action admin_print_scripts
     */
    public function removeAutosave()
    {
        glsr(Customization::class)->removeAutosave();
    }

    /**
     * @return void
     * @action admin_menu
     */
    public function removeMetaBoxes()
    {
        glsr(Customization::class)->removeMetaBoxes();
    }

    /**
     * @return void
     */
    public function removePostTypeSupport()
    {
        glsr(Customization::class)->removePostTypeSupport();
    }

    /**
     * @param WP_Post $post
     * @return void
     * @callback add_meta_box
     */
    public function renderAssignedToMetabox($post)
    {
        if (Review::isReview($post)) {
            $review = glsr(Query::class)->review($post->ID);
            wp_nonce_field('assigned_to', '_nonce-assigned-to', false);
            $templates = array_reduce($review->assigned_post_ids, function ($carry, $postId) {
                return $carry.glsr(Template::class)->build('partials/editor/assigned-post', [
                    'context' => [
                        'data.id' => $postId,
                        'data.url' => (string) get_permalink($postId),
                        'data.title' => get_the_title($postId),
                    ],
                ]);
            });
            glsr()->render('partials/editor/metabox-assigned-to', [
                'templates' => $templates,
            ]);
        }
    }

    /**
     * @param WP_Post $post
     * @return void
     * @callback add_meta_box
     */
    public function renderDetailsMetaBox($post)
    {
        if (Review::isReview($post)) {
            $review = glsr(Query::class)->review($post);
            glsr()->render('partials/editor/metabox-details', [
                'metabox' => $this->normalizeDetailsMetaBox($review),
            ]);
        }
    }

    /**
     * @return void
     * @action post_submitbox_misc_actions
     */
    public function renderPinnedInPublishMetaBox()
    {
        $review = glsr(Query::class)->review(get_post()->ID);
        if ($review->isValid() && glsr()->can('edit_others_posts')) {
            glsr(Template::class)->render('partials/editor/pinned', [
                'context' => [
                    'no' => _x('No', 'admin-text', 'site-reviews'),
                    'yes' => _x('Yes', 'admin-text', 'site-reviews'),
                ],
                'pinned' => $review->is_pinned,
            ]);
        }
    }

    /**
     * @param WP_Post $post
     * @return void
     * @callback add_meta_box
     */
    public function renderResponseMetaBox($post)
    {
        if (Review::isReview($post)) {
            wp_nonce_field('response', '_nonce-response', false);
            glsr()->render('partials/editor/metabox-response', [
                'response' => glsr(Database::class)->meta($post->ID, 'response'),
            ]);
        }
    }

    /**
     * @param WP_Post $post
     * @return void
     * @action edit_form_after_title
     */
    public function renderReviewEditor($post)
    {
        if (Review::isReview($post) && !Review::isEditable($post)) {
            glsr()->render('partials/editor/review', [
                'post' => $post,
                'response' => glsr(Database::class)->meta($post->ID, 'response'),
            ]);
        }
    }

    /**
     * @return void
     * @action admin_head
     */
    public function renderReviewFields()
    {
        $screen = glsr_current_screen();
        if ('post' === $screen->base && glsr()->post_type === $screen->post_type) {
            add_action('edit_form_after_title', [$this, 'renderReviewEditor']);
            add_action('edit_form_top', [$this, 'renderReviewNotice']);
        }
    }

    /**
     * @param WP_Post $post
     * @return void
     * @action edit_form_top
     */
    public function renderReviewNotice($post)
    {
        if (Review::isReview($post) && !Review::isEditable($post)) {
            glsr(Notice::class)->addWarning(sprintf(
                _x('%s reviews are read-only.', 'admin-text', 'site-reviews'),
                glsr(ColumnValueReviewType::class)->handle(glsr(Query::class)->review($post->ID))
            ));
            glsr(Template::class)->render('partials/editor/notice', [
                'context' => [
                    'notices' => glsr(Notice::class)->get(),
                ],
            ]);
        }
    }

    /**
     * @param WP_Post $post
     * @return void
     * @see glsr_categories_meta_box()
     * @callback register_taxonomy
     */
    public function renderTaxonomyMetabox($post)
    {
        if (Review::isReview($post)) {
            glsr()->render('partials/editor/metabox-categories', [
                'post' => $post,
                'tax_name' => glsr()->taxonomy,
                'taxonomy' => get_taxonomy(glsr()->taxonomy),
            ]);
        }
    }

    /**
     * @param int $postId
     * @param \WP_Post $post
     * @param bool $isUpdate
     * @return void
     * @action save_post_.Application::POST_TYPE
     */
    public function saveMetaboxes($postId, $post, $isUpdating)
    {
        glsr(Metaboxes::class)->saveAssignedToMetabox($postId);
        glsr(Metaboxes::class)->saveResponseMetabox($postId);
        if ($isUpdating) {
            glsr()->action('review/saved', glsr(Query::class)->review($postId));
        }
    }

    /**
     * @return string|void
     */
    protected function getReviewType(Review $review)
    {
        if (count(glsr()->reviewTypes) < 2) {
            return;
        }
        $type = $review->type();
        if (!empty($review->url)) {
            return glsr(Builder::class)->a([
                'href' => $review->url,
                'target' => '_blank',
                'text' => $type,
            ]);
        }
        return $type;
    }

    /**
     * @return array
     */
    protected function normalizeDetailsMetaBox(Review $review)
    {
        $user = empty($review->author_id)
            ? _x('Unregistered user', 'admin-text', 'site-reviews')
            : glsr(Builder::class)->a(get_the_author_meta('display_name', $review->author_id), [
                'href' => get_author_posts_url($review->author_id),
            ]);
        $email = empty($review->email)
            ? '&mdash;'
            : glsr(Builder::class)->a($review->email, [
                'href' => 'mailto:'.$review->email.'?subject='.esc_attr(_x('RE:', 'admin-text', 'site-reviews').' '.$review->title),
            ]);
        $metabox = [
            _x('Rating', 'admin-text', 'site-reviews') => $review->rating(),
            _x('Type', 'admin-text', 'site-reviews') => $this->getReviewType($review),
            _x('Date', 'admin-text', 'site-reviews') => $review->date(),
            _x('Name', 'admin-text', 'site-reviews') => $review->author,
            _x('Email', 'admin-text', 'site-reviews') => $email,
            _x('User', 'admin-text', 'site-reviews') => $user,
            _x('IP Address', 'admin-text', 'site-reviews') => $review->ip_address,
            _x('Avatar', 'admin-text', 'site-reviews') => sprintf('<img src="%s" width="96">', $review->avatar),
        ];
        return array_filter(glsr()->filterArray('metabox/details', $metabox, $review));
    }

    /**
     * @param int $postId
     * @param int $messageIndex
     * @return void
     */
    protected function redirect($postId, $messageIndex)
    {
        $referer = wp_get_referer();
        $hasReferer = !$referer
            || Str::contains($referer, 'post.php')
            || Str::contains($referer, 'post-new.php');
        $redirectUri = $hasReferer
            ? remove_query_arg(['deleted', 'ids', 'trashed', 'untrashed'], $referer)
            : get_edit_post_link($postId);
        wp_safe_redirect(add_query_arg(['message' => $messageIndex], $redirectUri));
        exit;
    }
}
