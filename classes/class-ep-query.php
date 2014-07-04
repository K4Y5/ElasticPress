<?php

/**
 * Unfortunately we cannot filter into WP_Query since we need to pass around a construct that
 * contains a site ID. Therefore we pass around an array and convert it to a WP_Post object at
 * the last second. WP_Query cannot be filtered or hooked into to do this. The goal of this class
 * is to mimic WP_Query behavior but simply hit the ES index before the posts table.
 */
class EP_Query {

	public $posts = array();

	public $post;

	public $cross_site = false;

	public $post_count = 0;

	public $found_posts = 0;

	public $in_the_loop = false;

	public $current_post = -1;

	public $max_num_pages = 0;

	/**
	 * Setup new EP query
	 *
	 * @param array $args
	 * @since 0.1.0
	 */
	public function __construct( $args ) {

		$config = ep_get_option( 0 );
		if ( ! empty( $config['cross_site_search_active'] ) ) {
			$this->cross_site = true;
		}

		$this->query( $args );
	}

	/**
	 * Query our Elasticsearch instance
	 *
	 * @param array $args
	 * @since 0.1.0
	 * @return array
	 */
	public function query( $args ) {

		$site_id = null;
		if ( $this->cross_site ) {
			$site_id = 0;
		}

		if ( ! ep_is_setup( $site_id ) ) {
			return array();
		}

		$formatted_args = $this->format_args( $args );

		$posts_per_page = ( isset( $args['posts_per_page'] ) ) ? $args['posts_per_page'] : get_option( 'posts_per_page' );

		$search = ep_search( $formatted_args, $site_id );

		$this->found_posts = $search['found_posts'];

		$this->max_num_pages = ceil( $this->found_posts / $posts_per_page );

		$this->post_count = count( $search['posts'] );

		$this->posts = $search['posts'];

		return $this->posts;
	}

	/**
	 * Format query args for ES. The intention of this class is to accept args that look
	 * like WP_Query's
	 *
	 * @param array $args
	 * @return array
	 */
	private function format_args( $args ) {
		$formatted_args = array(
			'from' => 0,
			'size' => get_option( 'posts_per_page' ),
			'sort' => array(
				array(
					'_score' => array(
						'order' => 'desc',
					),
				),
			),
		);

		$filter = array(
			'and' => array(),
		);

		$search_fields = array(
			'post_title',
			'post_excerpt',
			'post_content',
		);

		if ( ! empty( $args['search_tax'] ) ) {
			foreach ( $args['search_tax'] as $tax ) {
				$search_fields[] = 'terms.' . $tax . '.name';
			}
		}

		if ( ! empty( $args['search_meta'] ) ) {
			foreach ( $args['search_meta'] as $key ) {
				$search_fields[] = 'post_meta.' . $key;
			}
		}

		$query = array(
			'bool' => array(
				'must' => array(
					'fuzzy_like_this' => array(
						'fields' => $search_fields,
						'like_text' => '',
						'min_similarity' => 0.5,
					),
				),
			),
		);

		if ( ! $this->cross_site ) {
			$formatted_args['filter']['and'][1] = array(
				'term' => array(
					'site_id' => get_current_blog_id()
				)
			);
		}

		if ( isset( $args['s'] ) ) {
			$query['bool']['must']['fuzzy_like_this']['like_text'] = $args['s'];
			$formatted_args['query'] = $query;
		}

		if ( isset( $args['post_type'] ) ) {
			$post_types = (array) $args['post_type'];
			$terms_map_name = 'terms';
			if ( count( $post_types ) < 2 ) {
				$terms_map_name = 'term';
			}

			$filter['and'][] = array(
				$terms_map_name => array(
					'post_type' => $post_types,
				),
			);

			$formatted_args['filter'] = $filter;
		}

		if ( isset( $args['offset'] ) ) {
			$formatted_args['from'] = $args['offset'];
		}

		if ( isset( $args['posts_per_page'] ) ) {
			$formatted_args['size'] = $args['posts_per_page'];
		}

		if ( isset( $args['paged'] ) ) {
			$paged = ( $args['paged'] <= 1 ) ? 0 : $args['paged'] - 1;
			$formatted_args['from'] = $args['posts_per_page'] * $paged;
		}

		return $formatted_args;
	}

	/**
	 * Check if the query has posts
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public function have_posts() {
		if ( is_multisite() && $this->cross_site ) {
			restore_current_blog();
		}

		if ( $this->current_post + 1 < $this->post_count ) {
			return true;
		} elseif ( $this->current_post + 1 == $this->post_count && $this->post_count > 0 ) {
			// loop has ended
		}

		$this->in_the_loop = false;
		return false;
	}

	/**
	 * Setup the current post in the $post global
	 *
	 * @since 0.1.0
	 */
	public function the_post() {
		global $post;

		$this->in_the_loop = true;

		$ep_post = $this->next_post();

		if ( is_multisite() && $this->cross_site && $ep_post['site_id'] != get_current_blog_id() ) {
			switch_to_blog( $ep_post['site_id'] );
		}

		$post = get_post( $ep_post['post_id'] );

		setup_postdata( $post );
	}

	/**
	 * Move to the next post in the query
	 *
	 * @since 0.1.0
	 * @return mixed
	 */
	function next_post() {
		$this->current_post++;

		$this->post = $this->posts[$this->current_post];
		return $this->post;
	}
}