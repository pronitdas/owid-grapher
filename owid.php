<?php
/*
Plugin Name: Our World In Data
*/

/*
 * Plugin set-up
 * https://developer.wordpress.org/block-editor/tutorials/javascript/
 *
 *  Save post meta in block
 *  https://developer.wordpress.org/block-editor/tutorials/metabox/meta-block-1-intro/
 */

const READING_CONTEXT_META_FIELD = 'owid_reading_context_meta_field';

function owid_plugin_register()
{
	wp_register_script(
		'owid-plugin-script',
		plugins_url('build/index.js', __FILE__),
		array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose')
	);

	wp_register_style(
		'owid-plugin-css',
		plugins_url('src/style.css', __FILE__)
	);

	// Register custom post meta field. The content of the field will be saved
	// separately from the serialized HTML
	register_post_meta(
		'post',
		READING_CONTEXT_META_FIELD,
		array(
			'show_in_rest' => true,
			'single' => true,
			'type' => 'integer',
		)
	);
}

function owid_plugin_assets_enqueue()
{
	$screen = get_current_screen();
	if ($screen->post_type === 'post') {
		wp_enqueue_script('owid-plugin-script');
		wp_enqueue_style('owid-plugin-css');
	}
}

add_action('init', 'owid_plugin_register');
add_action('enqueue_block_editor_assets', 'owid_plugin_assets_enqueue');

/*
 * API fields
 */


/*
 * Returns the first heading (requires the first block to be a Gutenberg block).
 * The rest of the content can remain in the classic editor.
 */

function getFirstHeading(array $outerPost)
{
	$firstHeading = null;

	if (isset($outerPost['meta'][READING_CONTEXT_META_FIELD]) && $outerPost['meta'][READING_CONTEXT_META_FIELD] !== 0) {

		// Checking the first block of the post (outerPost) for a reusable
		// block (innerPost). Then get the first heading.
		$outerPostBlocks = parse_blocks($outerPost['content']['raw']);
		if ($outerPostBlocks[0]['blockName'] === "core/block") {
			$innerPost = get_post($outerPostBlocks[0]['attrs']['ref']);
			$innerPostBlocks = parse_blocks($innerPost->post_content);
			if ($innerPostBlocks[0]['blockName'] === 'core/heading') {
				$firstHeading = trim(strip_tags($innerPostBlocks[0]['innerHTML']));
			}
		}
	}

	return $firstHeading;
}

/*
 * Returns either the post's standard path or the path of the embedding entry (for blog
 * posts embedded in an entry)
 */
function getPath(array $post)
{
	// Compute the deep path if the blog post reading context is an
	// entry (as opposed to its own page)
	if (isset($post['meta'][READING_CONTEXT_META_FIELD]) && $post['meta'][READING_CONTEXT_META_FIELD] !== 0) {
		// Get the entry path from the sidebar plugin
		return get_page_uri($post['meta'][READING_CONTEXT_META_FIELD]);
	} else {
		// Remove the host and leading slash from the post link (not expected by the
		// JS clients). Without hierarchical paths (parent/page-slug), the path is
		// identical to the slug.
		return substr(wp_make_link_relative($post['link']), 1);
	}
}

function getReadingContext(array $post)
{
	if (isset($post['meta'][READING_CONTEXT_META_FIELD]) && $post['meta'][READING_CONTEXT_META_FIELD] !== 0) {
		return 'entry';
	} else {
		return 'in-situ';
	}
}

function getAuthorsName(array $post)
{
	$authorNames = [];
	$authors = get_coauthors($post['id']);
	foreach ($authors as $author) {
		$authorNames[] = $author->data->display_name;
	}
	return $authorNames;
}

function getFeaturedMediaPath(array $post)
{
	$featured_media_url = wp_get_attachment_image_url($post['featured_media'], 'medium_large');
	if ($featured_media_url) {
		return wp_make_link_relative($featured_media_url);
	} else {
		return null;
	}
}

function getTitleRaw(array $post)
{
	return $post['title']['raw'];
}

function getExcerptRaw(array $post)
{
	return $post['excerpt']['raw'];
}

function permissionsCallbackGetPostType()
{
	// Restrict endpoint to only users who have the edit_posts capability.
	if (!current_user_can('edit_posts')) {
		return new WP_Error('rest_forbidden', esc_html__('Sorry, you are not allowed to do that.', 'my-text-domain'), array('status' => 401));
	}

	return true;
}

function getPostType($request)
{
	$post = NULL;
	if ($request['slug']) {
		$posts = get_posts(array(
			'name' => $request['slug'],
			'posts_per_page' => 1,
			'post_type' => 'any',
			'post_status' => ['private', 'publish']
		));
		if (!empty($posts)) {
			$post = $posts[0];
		}
	} else if ($request['id']) {
		$post = get_post($request['id']);
	}

	if ($post !== NULL) {
		return rest_ensure_response($post->post_type);
	} else {
		return new WP_Error('no_post', 'No post found with these search criteria', array('status' => 404));
	}
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route('owid/v1', '/type', array(
			'methods' => 'GET',
			'callback' => 'getPostType',
			'permission_callback' => 'permissionsCallbackGetPostType'
		));
		register_rest_field(
			['post', 'page'],
			'path',
			['get_callback' => 'getPath']
		);
		register_rest_field(
			['post'],
			'first_heading',
			['get_callback' => 'getFirstHeading']
		);
		register_rest_field(
			'post',
			'reading_context',
			['get_callback' => 'getReadingContext']
		);
		register_rest_field(
			['post', 'page'],
			'authors_name',
			['get_callback' => 'getAuthorsName']
		);
		register_rest_field(
			'post',
			'featured_media_path',
			['get_callback' => 'getFeaturedMediaPath']
		);
		register_rest_field(
			['post', 'page', 'post-revision', 'page-revision'],
			'title_raw',
			['get_callback' => 'getTitleRaw']
		);
		register_rest_field(
			['post'],
			'excerpt_raw',
			['get_callback' => 'getExcerptRaw']
		);
	}
);
