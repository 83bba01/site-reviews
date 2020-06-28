<?php

namespace GeminiLabs\SiteReviews;

use GeminiLabs\SiteReviews\Database\Query;
use GeminiLabs\SiteReviews\Database\QuerySql;
use GeminiLabs\SiteReviews\Database\SqlSchema;
use GeminiLabs\SiteReviews\Defaults\RatingDefaults;
use GeminiLabs\SiteReviews\Helper;
use GeminiLabs\SiteReviews\Helpers\Str;
use WP_Query;

class Database
{
    protected $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * @return void
     */
    public function createTables()
    {
        glsr(SqlSchema::class)->createTables();
        glsr(SqlSchema::class)->addTableConstraints();
    }

    /**
     * @param int $reviewId
     * @return int|false
     */
    public function delete($reviewId)
    {
        return $this->db->delete(glsr(Query::class)->table('ratings'), [
            'review_id' => $reviewId,
        ]);
    }

    /**
     * Search SQL filter for matching against post title only.
     * @see http://wordpress.stackexchange.com/a/11826/1685
     * @param string $search
     * @return string
     * @filter posts_search
     */
    public function filterSearchByTitle($search, WP_Query $query)
    {
        if (empty($search) || empty($query->get('search_terms'))) {
            return $search;
        }
        global $wpdb;
        $n = empty($query->get('exact'))
            ? '%'
            : '';
        $search = [];
        foreach ((array) $query->get('search_terms') as $term) {
            $search[] = $wpdb->prepare("{$wpdb->posts}.post_title LIKE %s", $n.$wpdb->esc_like($term).$n);
        }
        if (!is_user_logged_in()) {
            $search[] = "{$wpdb->posts}.post_password = ''";
        }
        return ' AND '.implode(' AND ', $search);
    }

    /**
     * @param int $reviewId
     * @return \GeminiLabs\SiteReviews\Review|false
     */
    public function insert($reviewId, array $data = [])
    {
        $defaults = glsr(RatingDefaults::class)->restrict($data);
        $data = Arr::set($defaults, 'review_id', $reviewId);
        $result = $this->insertRaw(glsr(Query::class)->table('ratings'), $data);
        return (false !== $result)
            ? glsr(ReviewManager::class)->get($reviewId)
            : false;
    }

    /**
     * @param string $table
     * @return int|false
     */
    public function insertBulk($table, array $values, array $fields)
    {
        $this->db->insert_id = 0;
        $data = [];
        foreach ($values as $value) {
            $value = array_intersect_key($value, array_flip($fields)); // only keep field values
            if (count($value) === count($fields)) {
                $value = array_merge(array_flip($fields), $value); // make sure the order is correct
                $data[] = glsr(QuerySql::class)->escValuesForInsert($value);
            }
        }
        $table = glsr(Query::class)->table($table);
        $fields = glsr(QuerySql::class)->escFieldsForInsert(array_keys($data));
        $values = implode(',', array_values($data));
        return $this->db->query("INSERT IGNORE INTO {$table} {$fields} VALUES {$values}");
    }

    /**
     * @param string $table
     * @return int|false
     */
    public function insertRaw($table, array $data)
    {
        $this->db->insert_id = 0;
        $fields = glsr(QuerySql::class)->escFieldsForInsert(array_keys($data));
        $values = glsr(QuerySql::class)->escValuesForInsert($data);
        return $this->db->query("INSERT IGNORE INTO {$table} {$fields} VALUES {$values}");
    }

    /**
     * Do not remove this as it has been given in code snippets.
     * @return array
     */
    public function getTerms(array $args = [])
    {
        $args = wp_parse_args($args, [
            'count' => false,
            'fields' => 'id=>name',
            'hide_empty' => false,
            'taxonomy' => glsr()->taxonomy,
        ]);
        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            glsr_log()->error($terms->get_error_message());
            return [];
        }
        return $terms;
    }

    /**
     * @return bool
     */
    public function isMigrationNeeded()
    {
        global $wpdb;
        $table = glsr(Query::class)->table('ratings');
        $postCount = wp_count_posts(glsr()->post_type)->publish;
        if (empty($postCount)) {
            return false;
        }
        if (!empty($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_approved = 1"))) {
            return false;
        }
        return true;
    }

    /**
     * @param int $postId
     * @param string $key
     * @param bool $single
     * @return mixed
     */
    public function meta($postId, $key, $single = true)
    {
        $key = Str::prefix('_', $key);
        return get_post_meta(Helper::castToInt($postId), $key, $single);
    }

    /**
     * @param string $searchTerm
     * @return void|string
     */
    public function searchPosts($searchTerm)
    {
        $args = [
            'post_status' => 'publish',
            'post_type' => 'any',
        ];
        if (is_numeric($searchTerm)) {
            $args['post__in'] = [$searchTerm];
        } else {
            $args['orderby'] = 'relevance';
            $args['posts_per_page'] = 10;
            $args['s'] = $searchTerm;
        }
        add_filter('posts_search', [$this, 'filterSearchByTitle'], 500, 2);
        $search = new WP_Query($args);
        remove_filter('posts_search', [$this, 'filterSearchByTitle'], 500);
        if ($search->have_posts()) {
            $results = '';
            while ($search->have_posts()) {
                $search->the_post();
                ob_start();
                glsr()->render('partials/editor/search-result', [
                    'ID' => get_the_ID(),
                    'permalink' => esc_url((string) get_permalink()),
                    'title' => esc_attr(get_the_title()),
                ]);
                $results .= ob_get_clean();
            }
            wp_reset_postdata();
            return $results;
        }
    }

    /**
     * @param int $reviewId
     * @return int|bool
     */
    public function update($reviewId, array $data = [])
    {
        $defaults = glsr(RatingDefaults::class)->restrict($data);
        $data = array_intersect_key($data, $defaults);
        return $this->db->update(glsr(Query::class)->table('ratings'), $data, [
            'review_id' => $reviewId,
        ]);
    }
}
