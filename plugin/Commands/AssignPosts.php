<?php

namespace GeminiLabs\SiteReviews\Commands;

use GeminiLabs\SiteReviews\Contracts\CommandContract as Contract;
use GeminiLabs\SiteReviews\Database\ReviewManager;

class AssignPosts implements Contract
{
    public $ratingId;
    public $postIds;

    public function __construct($ratingId, array $postIds)
    {
        $this->ratingId = $ratingId;
        $this->postIds = $postIds;
    }

    /**
     * @return void
     */
    public function handle()
    {
        foreach ($this->postIds as $postId) {
            glsr(ReviewManager::class)->assignPost($this->ratingId, $postId);
        }
    }
}
