<?php

namespace GeminiLabs\SiteReviews\Controllers;

use GeminiLabs\SiteReviews\Commands\CreateReview;
use GeminiLabs\SiteReviews\Commands\ToggleStatus;
use GeminiLabs\SiteReviews\Database\Query;
use GeminiLabs\SiteReviews\Database\ReviewManager;
use GeminiLabs\SiteReviews\Review;
use WP_Post;

class ReviewController extends Controller
{
    /**
     * @return void
     * @action admin_action_approve
     */
    public function approve()
    {
        if (glsr()->id == filter_input(INPUT_GET, 'plugin')) {
            check_admin_referer('approve-review_'.($postId = $this->getPostId()));
            $this->execute(new ToggleStatus($postId, 'publish'));
            wp_safe_redirect(wp_get_referer());
            exit;
        }
    }

    /**
     * @param array $posts
     * @return array
     * @filter the_posts
     */
    public function filterPostsToCacheReviews($posts)
    {
        $reviews = array_filter($posts, function ($post) {
            return glsr()->post_type === $post->post_type;
        });
        if ($postIds = wp_list_pluck($reviews, 'ID')) {
            glsr(Query::class)->reviews([], $postIds); // this caches the associated Review objects
        }
        return $posts;
    }

    /**
     * Triggered when one or more categories are added or removed from a review.
     *
     * @param int $postId
     * @param array $terms
     * @param array $newTTIds
     * @param string $taxonomy
     * @param bool $append
     * @param array $oldTTIds
     * @return void
     * @action set_object_terms
     */
    public function onAfterChangeAssignedTerms($postId, $terms, $newTTIds, $taxonomy, $append, $oldTTIds)
    {
        if (!Review::isReview($postId)) {
            return;
        }
        $diff = $this->getAssignedDiff($oldTTIds, $newTTIds);
        $review = glsr(Query::class)->review($postId);
        foreach ($diff['old'] as $termId) {
            glsr(ReviewManager::class)->unassignTerm($review, $termId);
        }
        foreach ($diff['new'] as $termId) {
            glsr(ReviewManager::class)->assignTerm($review, $termId);
        }
    }

    /**
     * Triggered when a review's assigned post IDs are updated.
     *
     * @return void
     * @action site-reviews/review/updated/post_ids
     */
    public function onChangeAssignedPosts(Review $review, array $postIds = [])
    {
        $diff = $this->getAssignedDiff($review->assigned_post_ids, $postIds);
        foreach ($diff['old'] as $postId) {
            glsr(ReviewManager::class)->unassignPost($review, $postId);
        }
        foreach ($diff['new'] as $postId) {
            glsr(ReviewManager::class)->assignPost($review, $postId);
        }
    }

    /**
     * Triggered when a review's assigned users IDs are updated.
     *
     * @return void
     * @action site-reviews/review/updated/user_ids
     */
    public function onChangeAssignedUsers(Review $review, array $userIds = [])
    {
        $diff = $this->getAssignedDiff($review->assigned_user_ids, $userIds);
        foreach ($diff['old'] as $userId) {
            glsr(ReviewManager::class)->unassignUser($review, $userId);
        }
        foreach ($diff['new'] as $userId) {
            glsr(ReviewManager::class)->assignUser($review, $userId);
        }
    }

    /**
     * @return void
     * @action admin_action_unapprove
     */
    public function unapprove()
    {
        if (glsr()->id == filter_input(INPUT_GET, 'plugin')) {
            check_admin_referer('unapprove-review_'.($postId = $this->getPostId()));
            $this->execute(new ToggleStatus($postId, 'pending'));
            wp_safe_redirect(wp_get_referer());
            exit;
        }
    }

    /**
     * @return array
     */
    protected function getAssignedDiff(array $existing, array $replacements)
    {
        sort($existing);
        sort($replacements);
        $new = $old = [];
        if ($existing !== $replacements) {
            $ignored = array_intersect($existing, $replacements);
            $new = array_diff($replacements, $ignored);
            $old = array_diff($existing, $ignored);
        }
        return [
            'new' => $new,
            'old' => $old,
        ];
    }

    public function onAfterChangeAssignedUsers($userIds)
    {
    }

    /**
     * Triggered when a post status changes or when a review is approved|unapproved|trashed.
     *
     * @param string $oldStatus
     * @param string $newStatus
     * @param \WP_Post $post
     * @return void
     * @action transition_post_status
     */
    public function onAfterChangeStatus($newStatus, $oldStatus, $post)
    {
        if (in_array($oldStatus, ['new', $newStatus])) {
            return;
        }
        $postType = get_post_type($post);
        if (glsr()->post_type === $postType) {
            glsr(ReviewManager::class)->update($post->ID, [
                'is_approved' => 'publish' === $newStatus,
            ]);
        } else {
            glsr(ReviewManager::class)->updateAssignedPost($post->ID, [
                'is_published' => 'publish' === $newStatus,
            ]);
        }
    }

    /**
     * Triggered when a review is first created.
     *
     * @return void
     * @action site-reviews/review/creating
     * @todo fix $command->review_type
     */
    public function onAfterCreate(WP_Post $post, CreateReview $command)
    {
        glsr(ReviewManager::class)->insert($post->ID, [
            'is_approved' => 'publish' === $post->status,
            'rating' => $command->rating,
            'type' => $command->review_type,
        ]);
    }
}
