<?php

namespace GeminiLabs\SiteReviews\Controllers\ListTableColumns;

use GeminiLabs\SiteReviews\Review;

class ColumnValueRating implements ColumnValue
{
    /**
     * {@inheritdoc}
     */
    public function handle(Review $review)
    {
        return glsr_star_rating($review->rating);
    }
}
