<?php

namespace GeminiLabs\SiteReviews\Controllers\ListTableColumns;

use GeminiLabs\SiteReviews\Helper;
use GeminiLabs\SiteReviews\Modules\Html\Builder;
use GeminiLabs\SiteReviews\Review;

class ColumnValueReviewer implements ColumnValue
{
    /**
     * {@inheritdoc}
     */
    public function handle(Review $review)
    {
        if ($userId = (int) $review->author_id) {
            return glsr(Builder::class)->a([
                'href' => get_author_posts_url($userId),
                'text' => Helper::ifEmpty($review->author, __('Unknown', 'admin-text', 'site-reviews')),
            ]);
        }
        return $review->author;
    }
}
