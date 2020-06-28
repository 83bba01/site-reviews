<?php

namespace GeminiLabs\SiteReviews\Controllers\ListTableColumns;

use GeminiLabs\SiteReviews\Review;

class ColumnValueEmail implements ColumnValue
{
    /**
     * {@inheritdoc}
     */
    public function handle(Review $review)
    {
        return $review->email;
    }
}
