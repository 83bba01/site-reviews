<?php

namespace GeminiLabs\SiteReviews\Controllers\ListTableColumns;

use GeminiLabs\SiteReviews\Review;

class ColumnValueReviewType implements ColumnValue
{
    /**
     * {@inheritdoc}
     */
    public function handle(Review $review)
    {
        return array_key_exists($review->type, glsr()->reviewTypes)
            ? glsr()->reviewTypes[$review->type]
            : _x('Unsupported Type', 'admin-text', 'site-reviews');
    }
}
