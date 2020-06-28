<?php

namespace GeminiLabs\SiteReviews\Database;

use GeminiLabs\SiteReviews\Application;
use GeminiLabs\SiteReviews\Commands\CreateReview;
use GeminiLabs\SiteReviews\Database;
use GeminiLabs\SiteReviews\Defaults\CreateReviewDefaults;
use GeminiLabs\SiteReviews\Helpers\Arr;
use GeminiLabs\SiteReviews\Review;
use GeminiLabs\SiteReviews\Reviews;

class ReviewManager
{
    /**
     * @param int $postId
     * @return int|false
     */
    public function assignPost(Review $review, $postId)
    {
        return glsr(Database::class)->insertRaw(glsr(Query::class)->table('assigned_posts'), [
            'is_published' => 'publish' === get_post_status($postId),
            'post_id' => $postId,
            'rating_id' => $review->rating_id,
        ]);
    }

    /**
     * @param int $termId
     * @return int|false
     */
    public function assignTerm(Review $review, $termId)
    {
        return glsr(Database::class)->insertRaw(glsr(Query::class)->table('assigned_terms'), [
            'rating_id' => $review->rating_id,
            'term_id' => $termId,
        ]);
    }

    /**
     * @param int $userId
     * @return int|false
     */
    public function assignUser(Review $review, $userId)
    {
        return glsr(Database::class)->insertRaw(glsr(Query::class)->table('assigned_users'), [
            'rating_id' => $review->rating_id,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param int $reviewId
     * @return Review
     */
    public function get($reviewId)
    {
        $review = glsr(Query::class)->review($reviewId);
        glsr()->action('get/review', $review, $reviewId);
        return $review;
    }

    /**
     * @return Reviews
     */
    public function reviews(array $args = [])
    {
        $reviews = glsr(Query::class)->reviews($args);
        $total = $this->total($args, $reviews);
        glsr()->action('get/reviews', $reviews, $args);
        return new Reviews($reviews, $total, $args);
    }

    /**
     * @return int
     */
    public function total(array $args = [], array $reviews = [])
    {
        return glsr(Query::class)->totalReviews($args, $reviews);
    }

    /**
     * @param int $postId
     * @return int|false
     */
    public function unassignPost(Review $review, $postId)
    {
        return glsr(Database::class)->delete(glsr(Query::class)->table('assigned_posts'), [
            'post_id' => $postId,
            'rating_id' => $review->rating_id,
        ]);
    }

    /**
     * @param int $termId
     * @return int|false
     */
    public function unassignTerm(Review $review, $termId)
    {
        return glsr(Database::class)->delete(glsr(Query::class)->table('assigned_terms'), [
            'rating_id' => $review->rating_id,
            'term_id' => $termId,
        ]);
    }   

    /**
     * @param int $termId
     * @return int|false
     */
    public function unassignUser(Review $review, $userId)
    {
        return glsr(Database::class)->delete(glsr(Query::class)->table('assigned_users'), [
            'rating_id' => $review->rating_id,
            'user_id' => $userId,
        ]);
    }





    /**
     * @param array[]|string $termIds
     * @return array
     */
    public function normalizeTermIds($termIds)
    {
        $termIds = Arr::convertFromString($termIds);
        foreach ($termIds as &$termId) {
            $term = term_exists($termId, glsr()->taxonomy); // get the term from a term slug
            $termId = Arr::get($term, 'term_id', 0);
        }
        return Arr::uniqueInt($termIds);
    }


// -[ ] insert review (rating)
// -[ ] update review (rating)
// -[ ] delete review (rating)





























































    /**
     * @return false|Review
     */
    public function create(CreateReview $command)
    {
        $reviewValues = glsr(CreateReviewDefaults::class)->restrict((array) $command);
        $reviewValues = glsr()->filterArray('create/review-values', $reviewValues, $command);
        $reviewValues = Arr::prefixKeys($reviewValues);
        $postValues = [
            'comment_status' => 'closed',
            'meta_input' => $reviewValues,
            'ping_status' => 'closed',
            'post_content' => $reviewValues['_content'],
            'post_date' => $reviewValues['_date'],
            'post_date_gmt' => get_gmt_from_date($reviewValues['_date']),
            'post_name' => uniqid($reviewValues['_review_type']),
            'post_status' => $this->getNewPostStatus($reviewValues, $command->blacklisted),
            'post_title' => $reviewValues['_title'],
            'post_type' => Application::POST_TYPE,
        ];
        $postId = wp_insert_post($postValues, true);
        if (is_wp_error($postId)) {
            glsr_log()->error($postId->get_error_message())->debug($postValues);
            return false;
        }
        $post = get_post($postId);
        glsr()->action('review/creating', $post, $command);
        $this->setTerms($post->ID, $command->category);
        $review = $this->get($post);
        glsr()->action('review/created', $review, $command);
        return $review;
    }

    /**
     * @param string $metaReviewId
     * @return void
     */
    public function delete($metaReviewId)
    {
        if ($postId = $this->getPostId($metaReviewId)) {
            wp_delete_post($postId, true);
        }
    }


    /**
     * @param bool $isBlacklisted
     * @return string
     */
    protected function getNewPostStatus(array $reviewValues, $isBlacklisted)
    {
        $requireApproval = glsr(OptionManager::class)->getBool('settings.general.require.approval');
        return 'local' == $reviewValues['_review_type'] && ($requireApproval || $isBlacklisted)
            ? 'pending'
            : 'publish';
    }

    /**
     * @param int $postId
     * @param string $termIds
     * @return void
     */
    protected function setTerms($postId, $termIds)
    {
        $termIds = $this->normalizeTermIds($termIds);
        if (empty($termIds)) {
            return;
        }
        $termTaxonomyIds = wp_set_object_terms($postId, $termIds, Application::TAXONOMY);
        if (is_wp_error($termTaxonomyIds)) {
            glsr_log()->error($termTaxonomyIds->get_error_message());
        }
    }
}
