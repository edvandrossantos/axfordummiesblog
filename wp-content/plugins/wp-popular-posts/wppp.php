<?php
/*
 Plugin Name: WordPress.com Popular Posts
Plugin URI: http://polpoinodroidi.com/wordpress-plugins/wordpresscom-popular-posts/
Description: Shows the most popular posts, using data collected by
<a href='http://wordpress.org/extend/plugins/jetpack/'>Jetpack</a>
or <a href='http://wordpress.org/extend/plugins/stats/'>WordPress.com stats</a>
plugins.
Version: 2.6.0
Text Domain: wordpresscom-popular-posts
Author: Frasten
Author URI: http://polpoinodroidi.com
License: GPL3
*/

/* Created by Frasten (email : frasten@gmail.com) */


if ( ! class_exists( 'WPPP' ) && class_exists( 'WP_Widget' ) ) :
class WPPP extends WP_Widget {
	var $defaults;
	var $cache_expire = 600;

	function WPPP() {
		$this->defaults = array('title'	 => __( 'Popular Posts', 'wordpresscom-popular-posts' )
				,'number' => '5'
				,'days'	 => '0'
				,'show'	 => 'both'
				,'format' => "<a href='%post_permalink%' title='%post_title_attribute%'>%post_title%</a>"
				,'excerpt_length' => '100'
				,'title_length' => '0'
				,'exclude_author' => ''
				,'cutoff' => '0'
				,'list_tag' => 'ul'
				,'category' => '0'
				,'enable_cache' => '1'
				,'cache_only_when_visitor' => '0'
				,'time_format' => ''
				,'magic_number' => '1'
				,'thumbnail_size' => '50'
		);


		$widget_ops = array( 'classname' => 'widget_wppp',
				'description' => __( "A list of your most popular posts", 'wordpresscom-popular-posts' )
		);
		$control_ops = array( 'width' => 350, 'height' => 300 );
		$this->WP_Widget( 'wppp', __( 'Popular Posts', 'wordpresscom-popular-posts' ), $widget_ops, $control_ops );
	}

	function widget( $args, $instance = null ) {
		global $wpdb, $allowedposttags;
		if ( ! function_exists( 'stats_get_options' ) || ! function_exists( 'stats_get_csv' ) )
			return;

		extract( $args );

		if ( ! $instance ) {
			// Called from static non-widget function. (Or maybe some error? :-P)
			$instance = $args;

			/* cache */
			if ( ! empty( $instance['cachename'] ) ) {
				$this->id = $instance['cachename'];
			}
		}

		$from_shortcode = false;
		if ( ! empty( $instance['from_shortcode'] ) )
			$from_shortcode = true;


		/* Before the widget (as defined by the theme) */
		if ( ! $from_shortcode )
			echo $before_widget;

		/* CACHE SYSTEM */
		if ( $this->id && ( ! isset( $instance['enable_cache'] ) || $instance['enable_cache'] ) ) {
			$cache = get_option( 'wppp_cache' );
			if ( $cache && is_array( $cache ) ) {
				$widget_cache = $cache[$this->id];

				/* Don't serve a cached version (forcing refresh) when the user
				 * is logged in (if set in the preferences) */
				if ( ! $instance['cache_only_when_visitor'] || ! is_user_logged_in() ) {

					/* Check if it is valid or not */
					if ( isset( $widget_cache['time'] ) &&
							$widget_cache['time'] > ( time() - $this->cache_expire ) ) {

						/* If it's called from the function, let's make some check to
						 * see if the options have changed. */
						$valid = true;
						if ( ! empty( $instance['cachename'] ) ) {
							$settings_string = implode( '|', $instance );
							$md5 = md5( $settings_string );
							if ( $md5 != $widget_cache['settings_checksum'] )
								$valid = false;
						}
						if ( $valid ) {
							/* Print out the data from the cache. */
							if ( ! $from_shortcode ) {
								echo $widget_cache['value'];
								echo $after_widget;
								return;
							}
							else
								return $widget_cache['value'];
						}
						unset( $valid );
					} /* end cache's validity check */
				} /* end check if cache_only_when_visitor */

				unset( $widget_cache );
			}
			unset( $cache );
		}
		/* END CACHE SYSTEM */

		// Check against malformed values
		$instance['days'] = intval( $instance['days'] );
		$instance['number'] = intval( $instance['number'] );

		if ( $instance['days'] <= 0 )
			$instance['days'] = '-1';

		// A little hackish, but "could" work!
		$howmany = $instance['number'];
		if ( $instance['show'] == 'posts' )
			$howmany *= 2;
		else if ( $instance['show'] == 'pages' )
			$howmany *= 4; // pages are usually less, let's try more!

		// If I want to show links by category, I need more data.
		if ( $instance['category'] ) {
			$howmany *= 3;
		}

		/* The last workaround: if the automatic guesses are not enough,
		 * users can raise the "magic number" to increase the size of the
		* data asked to the server.
		* Note: if this number is set too high, it can slow the requests.
		* So it's a good practice to enable the cache for this plugin. */
		$instance['magic_number'] = floatval( $instance['magic_number'] );
		if ( $instance['magic_number'] > 1 ) {
			$howmany *= $instance['magic_number'];
			$howmany = intval( $howmany );
		}

		// If I set some posts to be excluded, I must ask for more data
		$excluded_ids = explode( ',', $instance['exclude'] );
		if ( is_array( $excluded_ids ) && sizeof( $excluded_ids ) && $excluded_ids[0] !== '' ) {
			$howmany += sizeof( $excluded_ids );
		}

		/* TEMPORARY FIX FOR WP_STATS PLUGIN */
		$reset_cache = false;
		$stats_cache = get_option( 'stats_cache' );

		if ( ! $stats_cache || ! is_array( $stats_cache ) ) {
			$reset_cache = true;
		}
		else {
			foreach ( $stats_cache as $key => $val ) {
				if ( ! is_array( $val ) || ! sizeof( $val ) ) {
					$reset_cache = true;
					break;
				}
				foreach ( $val as $key => $val2 ) {
					if ( ! is_array( $val2 ) || ! sizeof( $val2 ) ) {
						$reset_cache = true;
						break;
					}
					break;
				}
				break;
			}
		}

		if ( $reset_cache ) {
			update_option( 'stats_cache', "" );
		}
		/* END FIX */

		$top_posts = stats_get_csv( 'postviews', "days={$instance['days']}&limit=$howmany" );

		$output = '';
		/*********************
		 *      TITLE        *
		********************/
		if ( ! empty( $instance['title'] ) ) {
			/* I'm disabling this because it escapes html code and users want to
			 * use it. If anybody will need this filter, I'll try to find a solution.
			* Instead, I'm using wp_kses() for securing it.
			$instance['title'] = apply_filters( 'widget_title', $instance['title'] );
			*/
			$instance['title'] = wp_kses( $instance['title'], $allowedposttags );
			// Tags before and after the title (as called by WordPress)
			if ( $before_title || $after_title ) {
				$instance['title'] = $before_title . $instance['title'] . $after_title;
			}
			$output .= $instance['title'] . "\n";
		}

		// Check against malicious data
		if ( ! in_array( $instance['list_tag'], array( 'ul', 'ol', 'none' ) ) )
			$instance['list_tag'] = $this->defaults['list_tag'];

		if ( $instance['list_tag'] != 'none' )
			$output .= "<{$instance['list_tag']} class='wppp_list'>\n";

		// Cleaning and filtering
		if ( is_array( $top_posts ) && sizeof( $top_posts ) ) {
			$temp_list = array();
			foreach ( $top_posts as $p ) {
				// If I set some posts to be excluded:
				if ( sizeof( $excluded_ids ) && in_array( $p['post_id'], $excluded_ids ) ) continue;
				/* I don't know why, but on some blogs there are "fake" entries,
				 without data. */
				if ( ! $p['post_id'] ) continue;
				// Posts with views <= 0 must be excluded
				if ( $p['views'] <= 0 ) continue;
				// If I have set to have a cutoff, exclude the posts with views below that threshold
				if ( $instance['cutoff'] > 0 && $p['views'] < $instance['cutoff'] ) continue;

				$temp_list[] = $p;
			}
			$top_posts = $temp_list;
		}
		else {
			/* No popular posts. Maybe something went wrong while fetching data.
			 * Write a hidden debug message. */
			echo "<!-- WPPP error: no top-posts fetched. -->\n";
			echo $after_widget;
			return;
		}


		/*************************************************************
		 * Removing non-existing posts and updating data from the DB *
		************************************************************/
		$id_list = array();
		foreach ( $top_posts as $p ) {
			$id_list[] = $p['post_id'];
		}

		// If no top-posts, just do nothing gracefully
		if ( sizeof( $id_list ) ) {

			/* The data from WP-Stats aren't updated, so we must fetch them
			 * from the DB, overwriting the old values.
			* 1) check if that id is still valid (deleted post?)
			* 2) exclude private posts and drafts
			* 3) If I chose to show only posts or pages, only show them
			* 4) If I chose to show only a category, only show those posts.
			*    Thanks to Rob Malon for this.
			*/
			/* Let's save CPU time: execute this long query with joins only if necessary */
			$instance['category'] = (int) $instance['category'];
			$filter_category = $instance['category'] > 0;
			if ( $filter_category ) {
				$query = "SELECT p.id, p.post_title, p.post_author FROM $wpdb->posts AS p INNER JOIN $wpdb->term_relationships AS r ON r.object_id = p.id";
			}
			else {
				$query = "SELECT p.id, p.post_title, p.post_author FROM $wpdb->posts AS p";
			}
			$query .= " WHERE p.id IN (" . implode( ',', $id_list ) . ")";
			$query .= " AND p.post_status != 'draft' AND p.post_status != 'private' AND p.post_status != 'trash'";

			// If I want to show only posts or only pages:
			if ( $instance['show'] != 'both' ) {
				$query .= " AND p.post_type = '" . ( $instance['show'] == 'pages' ? 'page' : 'post' ) . "'";
			}

			if ( $filter_category ) {
				/* NOTE: if a category is set, it won't show pages (you can set
				 * categories only with posts. */

				$query .= " AND (r.term_taxonomy_id = '{$instance['category']}'";

				/* If I chose a parent category and the post is in a child category,
				 * I must also check if the post is in any of the child categories. */

				/* I need the term_id of the category, because get_categories()
				 * wants it. */
				$query_cat = "SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = '{$instance['category']}'";
				$cat_id = $wpdb->get_var( $query_cat );

				$children = get_categories("child_of=$cat_id&hide_empty=0");
				foreach ($children as $child) {
					$query .= " OR r.term_taxonomy_id = '$child->term_taxonomy_id'";
				}

				$query .= ')';
			}

			/* Check for exclude_author parameter */
			if ( ! empty( $instance['exclude_author'] ) ) {
				$query .= " AND p.post_author NOT IN (" . $instance['exclude_author'] . ")";
			}

			$results = $wpdb->get_results( $query );
			$valid_list = array();
			foreach ( $results as $valid ) {
				$valid_list[$valid->id] = $valid;
			}

			$temp_list = array();
			foreach ( $top_posts as $p ) {
				if ( in_array( $p['post_id'], array_keys( $valid_list ) ) ) {
					// Updating the title from the DB
					$p['post_title'] = strip_tags( __( $valid_list[$p['post_id']]->post_title ) );
					$temp_list[] = $p;
				}
				// Limit the number of posts shown following user settings.
				if ( sizeof( $temp_list ) >= $instance['number'] )
					break;
			}
			$top_posts = $temp_list;
			unset( $temp_list );
		} // end if (I have posts)


		foreach ( $top_posts as $post ) {
			if ( $instance['list_tag'] != 'none' )
				$output .= "\t<li>";

			// Replace format with data
			$replace = array(
					'%post_permalink%'			 => get_permalink( $post['post_id'] ),
					'%post_title%'					 => esc_html( $this->truncateText( $post['post_title'], $instance['title_length'] ) ),
					'%post_title_attribute%' => esc_attr( $post['post_title'] ),
					'%post_views%'					 => number_format_i18n( $post['views'] )
			);

			// %post_category% stuff
			if ( FALSE !== strpos( $instance['format'], '%post_category%' ) ) {
				// RS account for multiple categories
				$cat = get_the_category( $post['post_id'] );
				$replace['%post_category%'] = $cat[0]->cat_name;
			}

			// %post_comments% stuff
			if ( FALSE !== strpos( $instance['format'], '%post_comments%' ) ) {
				$replace['%post_comments%'] = get_comments_number( $post['post_id'] );
			}

			// %post_author% stuff
			if ( FALSE !== strpos( $instance['format'], '%post_author%' ) ) {
				$temppost = &get_post ( $post['post_id'] );
				$author = get_the_author_meta( 'display_name', $temppost->post_author );
				$replace['%post_author%'] = $author;
				unset( $temppost );
			}

			// %post_excerpt% stuff
			if ( FALSE !== strpos( $instance['format'], '%post_excerpt%' ) ) {
				// I get the excerpt for the post only if necessary, to save CPU time.
				$temppost = &get_post( $post['post_id'] );

				if ( ! empty( $temppost->post_excerpt ) ) {
					/* Excerpt already saved by the user */
					$replace['%post_excerpt%'] = $this->truncateText( $temppost->post_excerpt, $instance['excerpt_length'] );
				}
				else {
					// let's calculate the excerpt:
					$excerpt = strip_tags( $temppost->post_content );
					$excerpt = preg_replace( '|\[(.+?)\](.+?\[/\\1\])?|s', '', $excerpt );
					$excerpt = $this->truncateText( $excerpt, $instance['excerpt_length'] );
					$replace['%post_excerpt%'] = $excerpt;
				}
				unset( $temppost );
			}

			// %post_time% stuff
			if ( FALSE !== strpos( $instance['format'], '%post_time%' ) ) {
				/* If the first argument of get_the_time() is not set, it will use
				 * the default DATE format. */
				$replace['%post_time%'] = get_the_time( $instance['time_format'], $post['post_id'] );
			}

			// %post_thumbnail% stuff, WP 2.9+ only.
			if ( FALSE !== strpos( $instance['format'], '%post_thumbnail%' ) ) {
				// Check for mandatory functions (from WP 2.9 only)
				if ( function_exists( 'get_the_post_thumbnail' ) && function_exists( 'has_post_thumbnail' ) ) {
					if ( has_post_thumbnail( $post['post_id'] ) ) {
						$replace['%post_thumbnail%'] = get_the_post_thumbnail(
								$post['post_id'],
								array( $instance['thumbnail_size'], $instance['thumbnail_size'] )
						);
					}
					else {
						// No image found, show a default image, from Gravatar.
						$hash = md5( $post['post_id'] );
						$replace['%post_thumbnail%'] = "<img src='http://www.gravatar.com/avatar/$hash?d=identicon'" .
						" style='width: $instance[thumbnail_size]px; height: $instance[thumbnail_size]px'" .
						" class='no-grav'" . // To avoid a mouseover effect in late versions of WP
						" alt='' />";
					}
				}
				else {
					// If the theme doesn't support post thumbnails:
					$replace['%post_thumbnail%'] = '';
				}
			}
			/*
			TODO:
			we could use $args["widget_id"] to distinct settings from multiple
			instances of this widget, and add CSS code by default.
			*/


			$output .= wp_kses( strtr( $instance['format'], $replace ), $allowedposttags );

			if ( $instance['list_tag'] != 'none' )
				$output .= "</li>\n";
		}
		if ( $instance['list_tag'] != 'none' )
			$output .= "</{$instance['list_tag']}>\n";

		/* Cache data */
		$cache = get_option( 'wppp_cache' );
		if ( ! is_array($cache) ) $cache = array();
		$cache[$this->id] = array( 'value' => $output, 'time' => time() );
		if ( ! empty( $md5 ) ) {
			/* If I'm calling this from the function, I must save the checksum
			 * for the settings, to reset the cache everytime I change the settings. */
			$cache[$this->id]['settings_checksum'] = $md5;
		}
		update_option( 'wppp_cache', $cache );
		if ( ! $from_shortcode )
			echo $output;
		else
			return $output;

		/* After the widget (as defined by the theme) */
		echo $after_widget;
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance['title'] = $new_instance['title'];
		$instance['number'] = intval( $new_instance['number'] );
		$instance['days'] = intval( $new_instance['days'] );
		$instance['format'] = $new_instance['format'];
		$instance['show'] = in_array( $new_instance['show'], array( 'both', 'posts', 'pages' ) ) ?
		$new_instance['show'] :
		$this->defaults['show'];
		$instance['excerpt_length'] = intval( $new_instance['excerpt_length'] );
		$instance['title_length'] = intval( $new_instance['title_length'] );
		// I want only digits or commas for these:
		$instance['exclude'] = preg_replace( '/[^0-9,]/', '', $new_instance['exclude'] );
		$instance['exclude_author'] = preg_replace( '/[^0-9,]/', '', $new_instance['exclude_author'] );

		$instance['cutoff'] = max( intval( $new_instance['cutoff'] ), 0 );
		$instance['list_tag'] = in_array( $new_instance['list_tag'], array( 'ul', 'ol', 'none') ) ?
		$new_instance['list_tag'] :
		$this->defaults['list_tag'];
		$instance['category'] = intval( $new_instance['category'] );
		$instance['enable_cache'] = ( $new_instance['enable_cache'] ? 1 : 0 );
		$instance['cache_only_when_visitor'] = ( $new_instance['cache_only_when_visitor'] ? 1 : 0 );
		$instance['magic_number'] = floatval( $new_instance['magic_number'] );
		$instance['time_format'] = $new_instance['time_format'];
		$instance['thumbnail_size'] = max( 1, intval( $new_instance['thumbnail_size'] ) ); // >= 1px

		/* Reset cache */
		$cache = get_option( 'wppp_cache' );
		unset( $cache[$this->id] );
		update_option( 'wppp_cache', $cache );

		return $instance;
	}

	function form( $instance ) {
		// Set the settings that are still undefined
		$instance = wp_parse_args( (array) $instance, $this->defaults );

		$field_id = $this->get_field_id( 'title' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Title', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 180px;' id='$field_id' name='" .
		$this->get_field_name( 'title' ) . "' type='text' value='" .
		esc_attr( $instance['title'] ) . "' /></label></p>";

		$field_id = $this->get_field_id( 'number' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Number of links shown', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 30px;' id='$field_id' name='" .
		$this->get_field_name( 'number' ) . "' type='text' value='" .
		intval( $instance['number'] ) . "' /></label></p>";

		$field_id = $this->get_field_id( 'days' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'The length (in days) of the desired time frame.<br />(0 means unlimited)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 40px;' id='$field_id' name='" .
		$this->get_field_name( 'days' ) . "' type='text' value='" .
		intval( $instance['days'] ) . "' /></label></p>";

		$field_id = $this->get_field_id( 'show' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Show: ', 'wordpresscom-popular-posts' );
		$opt = array(
				'both'	=> __( 'posts and pages', 'wordpresscom-popular-posts' ),
				'posts' => __( 'only posts', 'wordpresscom-popular-posts' ),
				'pages' => __( 'only pages', 'wordpresscom-popular-posts' )
		);
		if ( ! $instance['show'] )
			$instance['show'] = $this->defaults['show'];
		echo "<select name='" . $this->get_field_name( 'show' ) . "' id='$field_id'>\n";
		foreach ( $opt as $key => $value ) {
			echo "<option value='$key'" . selected( $instance['show'], $key ) . ">$value</option>\n";
		}
		echo '</select></label></p>';

		$field_id = $this->get_field_id( 'format' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Format of the links. See <a href="http://wordpress.org/extend/plugins/wordpresscom-popular-posts/faq/">docs</a> for help', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 300px;' id='$field_id' name='" .
		$this->get_field_name( 'format' ) . "' type='text' value='" .
		esc_attr( $instance['format'] ) . "' /></label></p>";

		// If the theme doesn't support post thumbnails, echo a notice.
		if ( ! function_exists( 'current_theme_supports' ) || ! current_theme_supports( 'post-thumbnails' ) ) {
			echo '<em>';
			_e( "Note: your current theme doesn't support post thumbnails: you won't be able to set them.", 'wordpresscom-popular-posts' );
			echo '</em>';
		}
		$field_id = $this->get_field_id( 'thumbnail_size' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Width/height of the thumbnail image (if %post_thumbnail% is used in the format above)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 40px;' id='$field_id' name='" .
		$this->get_field_name( 'thumbnail_size' ) . "' type='text' value='" .
		intval( $instance['thumbnail_size'] ) . "' />" . __(' px', 'wordpresscom-popular-posts' ) . "</label></p>";

		$field_id = $this->get_field_id( 'excerpt_length' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Length of the excerpt (if %post_excerpt% is used in the format above)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 40px;' id='$field_id' name='" .
		$this->get_field_name( 'excerpt_length' ) . "' type='text' value='" .
		intval( $instance['excerpt_length'] ) . "' />" . __(' characters', 'wordpresscom-popular-posts' ) . "</label></p>";

		$field_id = $this->get_field_id( 'time_format' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Date/time format (if %post_time% is used in the format above). See <a href="http://wordpress.org/extend/plugins/wordpresscom-popular-posts/faq/">docs</a> for help', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 200px;' id='$field_id' name='" .
		$this->get_field_name( 'time_format' ) . "' type='text' value='" .
		esc_attr( $instance['time_format'] ) . "' /></label></p>";

		$field_id = $this->get_field_id( 'title_length' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Max length of the title links.<br />(0 means unlimited)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 30px;' id='$field_id' name='" .
		$this->get_field_name( 'title_length' ) . "' type='text' value='" .
		intval( $instance['title_length'] ) . "' />" . __(' characters', 'wordpresscom-popular-posts' ) . "</label></p>";

		$field_id = $this->get_field_id( 'exclude' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Exclude these posts: (separate the IDs by commas. e.g. 1,42,52)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 180px;' id='$field_id' name='" .
		$this->get_field_name( 'exclude' ) . "' type='text' value='" .
		esc_attr( $instance['exclude'] ) . "' /></label></p>";

		/* Setup form field for parameter exclude_author */
		$field_id = $this->get_field_id( 'exclude_author' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Exclude these authors: (separate the IDs by commas. e.g. 1,42,52)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 180px;' id='$field_id' name='" .
		$this->get_field_name( 'exclude_author' ) . "' type='text' value='" .
		esc_attr( $instance['exclude_author'] ) . "' /></label></p>";

		$field_id = $this->get_field_id( 'cutoff' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Don\'t show posts/pages with a view count under', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 50px;' id='$field_id' name='" .
		$this->get_field_name( 'cutoff' ) . "' type='text' value='" .
		intval( $instance['cutoff'] ) . "' /></label> " . __('(0 means unlimited)', 'wordpresscom-popular-posts' ) . '</p>';

		$field_id = $this->get_field_id( 'list_tag' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Kind of list', 'wordpresscom-popular-posts' );
		$opt = array(
				'ul'	=> __( 'Unordered list (&lt;ul&gt;)', 'wordpresscom-popular-posts' ),
				'ol'	=> __( 'Ordered list (&lt;ol&gt;)', 'wordpresscom-popular-posts' ),
				'none'	=> __( 'None (use custom format)', 'wordpresscom-popular-posts' )
		);
		if ( ! $instance['show'] )
			$instance['show'] = $this->defaults['list_tag'];
		echo ": <select name='" . $this->get_field_name( 'list_tag' ) . "' id='$field_id'>\n";
		foreach ( $opt as $key => $value ) {
			echo "<option value='$key'" . selected( $key, $instance['list_tag'] ) . ">$value</option>\n";
		}
		echo '</select></label></p>';

		// Category stuff
		$field_id = $this->get_field_id( 'category' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Only show posts/pages in this category', 'wordpresscom-popular-posts' );
		$cat_list = array(0 => __('&lt;All categories&gt;', 'wordpresscom-popular-posts' ) );
		$categories = get_categories();

		foreach ( $categories as $c ) {
			$cat_list[$c->term_taxonomy_id] = $c->cat_name;
		}
		echo ": <select name='" . $this->get_field_name( 'category' ) . "' id='$field_id'>\n";
		foreach ( $cat_list as $key => $value ) {
			echo "<option value='$key'" . selected( $key, $instance['category'] ) . ">$value</option>\n";
		}
		echo '</select></label></p>';

		$field_cache = $field_id = $this->get_field_id( 'enable_cache' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Enable cache (improves speed)', 'wordpresscom-popular-posts' );
		echo ": <input type='checkbox' id='$field_id' name='" .
		$this->get_field_name( 'enable_cache' ) . "'" .
		( $instance['enable_cache'] ? " checked='checked'" : '' ) . ' /></label></p>';

		/* This option is enabled only when enable_cache is on. */
		$field_id = $this->get_field_id( 'cache_only_when_visitor' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Only show a cached version to the not logged in users', 'wordpresscom-popular-posts' );
		echo ": <input type='checkbox' id='$field_id' name='" .
		$this->get_field_name( 'cache_only_when_visitor' ) . "'" .
		( $instance['cache_only_when_visitor'] ? " checked='checked'" : '' ) .
		( $instance['enable_cache'] ? '' : " disabled='disabled'" ) . ' /></label></p>';

		/* JavaScript for cache_only_when_visitor */
		echo <<<EOF
<script type='text/javascript'>
//<![CDATA[
jQuery(document).ready( function($) {
	$('#$field_cache').click(function() {
		var checkbox = $('#$field_id');
		if (checkbox.attr("disabled"))
			checkbox.removeAttr("disabled");
		else
			checkbox.attr("disabled", "disabled");
	});
});
//]]>
</script>
EOF;

		$field_id = $this->get_field_id( 'magic_number' );
		echo "<p style='text-align:right;'><label for='$field_id'>";
		_e( 'Magic number (raise it if you see less links than expected)', 'wordpresscom-popular-posts' );
		echo ": <input style='width: 50px;' id='$field_id' name='" .
		$this->get_field_name( 'magic_number' ) . "' type='text' value='" .
		intval( $instance['magic_number'] ) . "' /></label></p>";

	}

	function truncateText( $text, $chars = 50 ) {
		if ( strlen( $text ) <= $chars || $chars <= 0 )
			return $text;
		$new = wordwrap( $text, $chars, "|" );
		$newtext = explode( "|", $new );
		return $newtext[0] . "...";
	}
}
endif;

/* You can call this function if you want to integrate the plugin in a theme
 * that doesn't support widgets.
*
* Just insert this code:
* <?php if ( function_exists( 'WPPP_show_popular_posts' ) ) WPPP_show_popular_posts();?>
*
* Optionally you can add some parameters to the function, in this format:
* name=value&name=value etc.
*
* Possible names are:
* - title (title of the widget, you can add tags (e.g. <h3>Popular Posts</h3>) default: Popular Posts)
* - number (number of links shown, default: 5)
* - days (length of the time frame of the stats, default 0, i.e. infinite)
* - show (can be: both, posts, pages, default both)
* - format (the format of the links shown, default: <a href='%post_permalink%' title='%post_title%'>%post_title%</a>)
* - time_format (the format used with %post_time%, see http://codex.wordpress.org/Formatting_Date_and_Time)
* - thumbnail_size (the width/height in pixels of the post's thumbnail image)
* - excerpt_length (the length of the excerpt, if %post_excerpt% is used in the format)
* - title_length (the length of the title links, default 0, i.e. unlimited)
* - exclude (the list of post/page IDs to exclude, separated by commas)
* - exclude_author (the list of authors IDs to exclude, separated by commas)
* - cutoff (don't show posts/pages with a view count under this number, default 0, i.e. unlimited)
* - list_tag (can be: ul, ol, none, default ul)
* - category (the ID of the category, see FAQ for info. Default 0, i.e. all categories)
* - cachename (it is used to enable the cache. Please see the FAQ)
* - cache_only_when_visitor (if enabled, it doesn't serve a cached version of the popular posts to the users logged in, default 0)
* - magic_number (set it to a number greater than 1 if you see less links than expected)
*
* Example: if you want to show the widget without any title, the 3 most viewed
* articles, in the last week, and in this format: My Article (123 views)
* you will use this:
* WPPP_show_popular_posts( "title=&number=3&days=7&format=<a href='%post_permalink%' title='%post_title_attribute%'>%post_title% (%post_views% views)</a>" );
*
* You don't have to fill every field, you can insert only the values you
* want to change from default values.
*
* You can use these special markers in the `format` value:
* %post_permalink% the link to the post
* %post_title% the title the post
* %post_title_attribute% the title of the post; use this in attributes, e.g. <a title='%post_title_attribute%'
* %post_views% number of views
* %post_thumbnail% the thumbnail image of the post.
* %post_excerpt% the first n characters of the content. Set n with excerpt_length.
* %post_category% the category of the post
* %post_comments% the number of comments a post has
* %post_time% the date/time of the post. You can set the format with time_format.
* %post_author% the author of the post.
*
* */
function WPPP_show_popular_posts( $user_args = '' ) {
	$wppp = new WPPP();

	$args = wp_parse_args( $user_args, $wppp->defaults );

	$wppp->widget( $args );
}


/**
 * This function allows using [wp_popular_posts] shortcodes in posts/pages.
 */
function WPPP_shortcode_popular_posts( $user_args = '' ) {
	$wppp = new WPPP();
	$default_values = $wppp->defaults;
	$default_values['from_shortcode'] = true;
	$args = shortcode_atts( $default_values, $user_args );
	return $wppp->widget( $args );
}
add_shortcode('wp_popular_posts', 'WPPP_shortcode_popular_posts');


function wppp_notice_incompatible() {
	echo "<div class='error'><p>";
	printf( __( "Wordpress.com Popular Post &gt;= 2.0.0 is compatible with WordPress &gt;= 2.8 only.<br />
			Please either <a href='%s'>update</a> your WordPress installation, <a href='%s'>downgrade this plugin</a> to v1.3.6
			or <a href='%s'>uninstall it</a>.", 'wordpresscom-popular-posts' ),
			'http://wordpress.org/download/',
			'http://downloads.wordpress.org/plugin/wordpresscom-popular-posts.1.3.6.zip',
			'plugins.php' );
	echo "</p></div>";
}

function wppp_check_upgrade() {
	if ( ! class_exists( 'WP_Widget' ) ) return;
	// Import eventual old settings (from WPPP < 2.0.0)
	$wppp_options = get_option( 'widget_wppp' );
	if ( ! $wppp_options ) return;
	if ( array_key_exists( '_multiwidget', $wppp_options ) ) return;

	$new_options = array( 2 => $wppp_options, '_multiwidget' => 1 );
	update_option( 'widget_wppp', $new_options );

	$sb_option = get_option( 'sidebars_widgets' );
	foreach ( $sb_option as $key => $value ) {
		if ( 'wp_inactive_widgets' == $key || 'array_version' == $key ) continue;
		foreach ( $value as $i => $widgetname ) {
			if ( 'popular-posts' == $widgetname || 'articoli-piu-letti' == $widgetname ) {
				$sb_option[$key][$i] = 'wppp-2';
				break;
			}
		}
	}
	update_option( 'sidebars_widgets', $sb_option );
}
add_action('plugins_loaded', 'wppp_check_upgrade');


// Language loading
load_textdomain( 'wordpresscom-popular-posts', dirname(__FILE__) . "/language/wordpresscom-popular-posts-" . get_locale() . ".mo" );

// This version is incompatible with WP < 2.8
if ( ! class_exists( 'WP_Widget' ) ) {
	add_action( 'admin_notices', 'wppp_notice_incompatible' );
}
else {
	add_action( 'widgets_init', create_function( '', 'return register_widget( "WPPP" );' ) );
}
?>
