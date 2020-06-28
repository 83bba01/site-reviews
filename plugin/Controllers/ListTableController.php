<?php

namespace GeminiLabs\SiteReviews\Controllers;

use GeminiLabs\SiteReviews\Application;
use GeminiLabs\SiteReviews\Controllers\ListTableColumns\ColumnFilterRating;
use GeminiLabs\SiteReviews\Controllers\ListTableColumns\ColumnFilterReviewType;
use GeminiLabs\SiteReviews\Database\Query;
use GeminiLabs\SiteReviews\Helper;
use GeminiLabs\SiteReviews\Helpers\Arr;
use GeminiLabs\SiteReviews\Helpers\Str;
use GeminiLabs\SiteReviews\Modules\Html\Builder;
use GeminiLabs\SiteReviews\Modules\Migrate;
use WP_Post;
use WP_Query;
use WP_Screen;

class ListTableController extends Controller
{
    /**
     * @param array $columns
     * @return array
     * @filter manage_.Application::POST_TYPE._posts_columns
     */
    public function filterColumnsForPostType($columns)
    {
        $columns = Arr::consolidate($columns);
        $postTypeColumns = glsr()->retrieve('columns.'.glsr()->post_type, []);
        foreach ($postTypeColumns as $key => &$value) {
            if (array_key_exists($key, $columns) && empty($value)) {
                $value = $columns[$key];
            }
        }
        return array_filter($postTypeColumns, 'strlen');
    }

    /**
     * @param string $status
     * @param WP_Post $post
     * @return string
     * @filter post_date_column_status
     */
    public function filterDateColumnStatus($status, $post)
    {
        if (glsr()->post_type == Arr::get($post, 'post_type')) {
            $status = _x('Submitted', 'admin-text', 'site-reviews');
        }
        return $status;
    }

    /**
     * @param array $hidden
     * @param WP_Screen $post
     * @return array
     * @filter default_hidden_columns
     */
    public function filterDefaultHiddenColumns($hidden, $screen)
    {
        if (Arr::get($screen, 'id') == 'edit-'.glsr()->post_type) {
            $hidden = Arr::consolidate($hidden);
            $hidden = array_unique(array_merge($hidden, [
                'email', 'ip_address', 'response', 'reviewer',
            ]));
        }
        return $hidden;
    }

    /**
     * @return void
     * @filter posts_clauses
     */
    public function filterPostClauses(array $clauses, WP_Query $query)
    {
        if (!$this->hasPermission($query)) {
            return $clauses;
        }
        $table = glsr(Query::class)->table('ratings');
        foreach ($clauses as $key => &$clause) {
            $method = Helper::buildMethodName($key, 'modifyClause');
            if (method_exists($this, $method)) {
                $clause = call_user_func([$this, $method], $clause, $table, $query);
            }
        }
        return $clauses;
    }

    /**
     * @param array $actions
     * @param WP_Post $post
     * @return array
     * @filter post_row_actions
     */
    public function filterRowActions($actions, $post)
    {
        if (glsr()->post_type != Arr::get($post, 'post_type')
            || 'trash' == $post->post_status
            || !user_can(get_current_user_id(), 'edit_post', $post->ID)) {
            return $actions;
        }
        unset($actions['inline hide-if-no-js']); //Remove Quick-edit
        $rowActions = [
            'approve' => _x('Approve', 'admin-text', 'site-reviews'),
            'unapprove' => _x('Unapprove', 'admin-text', 'site-reviews'),
        ];
        $newActions = [];
        foreach ($rowActions as $key => $text) {
            $newActions[$key] = glsr(Builder::class)->a($text, [
                'aria-label' => esc_attr(sprintf(_x('%s this review', 'Approve the review (admin-text)', 'site-reviews'), $text)),
                'class' => 'glsr-toggle-status',
                'href' => wp_nonce_url(
                    admin_url('post.php?post='.$post->ID.'&action='.$key.'&plugin='.glsr()->id),
                    $key.'-review_'.$post->ID
                ),
            ]);
        }
        return $newActions + Arr::consolidate($actions);
    }

    /**
     * @param array $columns
     * @return array
     * @filter manage_edit-.Application::POST_TYPE._sortable_columns
     */
    public function filterSortableColumns($columns)
    {
        $columns = Arr::consolidate($columns);
        $postTypeColumns = glsr()->retrieve('columns.'.glsr()->post_type, []);
        unset($postTypeColumns['cb']);
        foreach ($postTypeColumns as $key => $value) {
            if (!Str::startsWith('assigned', $key) && !Str::startsWith('taxonomy', $key)) {
                $columns[$key] = $key;
            }
        }
        return $columns;
    }

    /**
     * @param string $columnName
     * @param string $postType
     * @return void
     * @action bulk_edit_custom_box
     */
    public function renderBulkEditFields($columnName, $postType)
    {
        if ('assigned_to' == $columnName && glsr()->post_type == $postType) {
            glsr()->render('partials/editor/bulk-edit-assigned-to');
        }
    }

    /**
     * @param string $postType
     * @return void
     * @action restrict_manage_posts
     */
    public function renderColumnFilters($postType)
    {
        if (glsr()->post_type !== $postType) {
            return;
        }
        if ($filter = glsr()->runIf(ColumnFilterRating::class)) {
            echo $filter;
        }
        if ($filter = glsr()->runIf(ColumnFilterReviewType::class)) {
            echo $filter;
        }
    }

    /**
     * @param string $column
     * @param int $postId
     * @return void
     * @action manage_posts_custom_column
     */
    public function renderColumnValues($column, $postId)
    {
        $review = glsr(Query::class)->review($postId);
        if (!$review->isValid()) {
            glsr(Migrate::class)->reset(); // looks like a migration is needed!
            return;
        }
        $className = Helper::buildClassName('ColumnValue'.$column, 'Controllers\ListTableColumns');
        $value = glsr()->runIf($className, $review);
        $value = glsr()->filterString('columns/'.$column, $value, $postId);
        echo Helper::ifEmpty($value, '&mdash;');
    }

    /**
     * @param int $postId
     * @return void
     * @action save_post_.Application::POST_TYPE
     */
    public function saveBulkEditFields($postId)
    {
        if (glsr()->can('edit_posts')) {
            $review = glsr(Query::class)->review($reviewId);
            $assignedPostIds = Arr::consolidate(filter_input(INPUT_GET, 'assigned_to'));
            $assignedUserIds = Arr::consolidate(filter_input(INPUT_GET, 'user_ids'));
            glsr()->action('review/updated/post_ids', $review, $assignedPostIds);
            glsr()->action('review/updated/user_ids', $review, $assignedUserIds);
        }
    }

    /**
     * @return void
     * @action pre_get_posts
     */
    public function setQueryForColumn(WP_Query $query)
    {
        if (!$this->hasPermission($query)) {
            return;
        }
        $orderby = $query->get('orderby');
        if ('response' === $orderby) {
            $query->set('meta_key', Str::prefix('_', $orderby));
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Check if the translation string can be modified.
     * @param string $domain
     * @return bool
     */
    protected function canModifyTranslation($domain = 'default')
    {
        $screen = glsr_current_screen();
        return 'default' == $domain
            && 'edit' == $screen->base
            && glsr()->post_type == $screen->post_type;
    }

    /**
     * @return bool
     */
    protected function hasPermission(WP_Query $query)
    {
        global $pagenow;
        return is_admin()
            && $query->is_main_query()
            && glsr()->post_type == $query->get('post_type')
            && 'edit.php' == $pagenow;
    }

    /**
     * @param string $join
     * @return string
     */
    protected function modifyClauseJoin($join, $table, WP_Query $query)
    {
        global $wpdb;
        $join .= " INNER JOIN {$table} ON {$table}.review_id = {$wpdb->posts}.ID ";
        return $join;
    }

    /**
     * @param string $orderby
     * @return string
     */
    protected function modifyClauseOrderby($orderby, $table, WP_Query $query)
    {
        $order = $query->get('order');
        $orderby = $query->get('orderby');
        $columns = [
            'email' => 'email',
            'ip_address' => 'ip_address',
            'pinned' => 'is_pinned',
            'rating' => 'rating',
            'review_type' => 'type',
            'reviewer' => 'name',
        ];
        if (array_key_exists($orderby, $columns)) {
            $column = "{$table}.{$columns[$orderby]}";
            $orderby = "NULLIF({$column}, '') IS NULL, {$column} {$order}";
        }
        return $orderby;
    }

    /**
     * @param string $where
     * @return string
     */
    protected function modifyClauseWhere($where, $table, WP_Query $query)
    {
        $filters = Arr::removeEmptyValues([
            'rating' => filter_input(INPUT_GET, 'rating'),
            'type' => filter_input(INPUT_GET, 'review_type'),
        ]);
        foreach ($filters as $key => $value) {
            $where .= " (AND {$table}.{$key} = '{$value}') ";
        }
        return $where;
    }
}
