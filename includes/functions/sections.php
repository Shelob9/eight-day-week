<?php
namespace Eight_Day_Week\Sections;

use Eight_Day_Week\Core as Core;
use Eight_Day_Week\User_Roles as User;
use Eight_Day_Week\Print_Issue as Print_Issue;

	/**
	 * Sections are used as an "in between" p2p relationship
	 * Sections are managed via a metabox on the print issue CPT
	 * They basically serve to group articles within a print issue
	 * The relationship is Print Issue -> Sections -> Articles
	 */

/**
 * Default setup routine
 *
 * @uses add_action()
 * @uses do_action()
 *
 * @return void
 */
function setup() {
	function ns( $function ) {
		return __NAMESPACE__ . "\\$function";
	}

	function a( $function ) {
		add_action( $function, ns( $function ) );
	}

	add_action( 'Eight_Day_Week\Core\init', ns( 'register_post_type' ) );

	a( 'edit_form_after_title' );

	add_action( 'add_meta_boxes_' . EDW_PRINT_ISSUE_CPT, ns( 'add_sections_meta_box' ), 10, 1 );
	add_action( 'edit_form_advanced', ns( 'add_section_output' ) );

	add_action( 'wp_ajax_pp-create-section', ns( 'Section_Factory::create_ajax' ) );
	add_action( 'wp_ajax_pp-update-section-title', ns( 'Section_Factory::update_title_ajax' ) );
	add_action( 'save_print_issue', ns( 'update_print_issue_sections' ), 10, 3 );

	add_action( 'wp_ajax_meta-box-order', ns( 'save_metabox_order' ), 0 );

	add_filter('get_user_option_meta-box-order_' . EDW_PRINT_ISSUE_CPT, ns( 'get_section_order') );

	add_action( 'edw_section_metabox', ns( 'section_save_button' ), 999 );

}

/**
 * Register section post type
 */
function register_post_type() {

	$args = [
		'public'   => false,
		'supports' => [ ],
	];

	\register_post_type( EDW_SECTION_CPT, $args );
}

/**
 * Outputs information after the print issue title
 * Current outputs:
 * 1. The "Sections" title
 * 2. Error messages for interactions that take place in sections
 * 3. An action with which other parts can hook to output
 *
 * @param $post
 */
function edit_form_after_title( $post ) {
	if( EDW_PRINT_ISSUE_CPT !== $post->post_type ) {
		return;
	}
	echo '<h2>' . esc_html( 'Sections', 'eight-day-week' ) . '</h2>';
	echo '<p id="pi-section-error" class="pi-error-msg"></p>';
	do_action( 'edw_sections_top' );
}

/**
 * Adds the sections metaboxes
 *
 * When no sections are present for the print issue,
 * this outputs a template for the JS to duplicate when adding the first section
 *
 * @uses add_meta_box
 *
 * @param $post \WP_Post Current post
 */
function add_sections_meta_box( $post ) {
	$sections = explode( ',', get_sections( $post->ID ) );

	//this is used as a template for duplicating metaboxes via JS
	//It's also used in metabox saving to retrieve the post ID. So don't remove this!
	array_unshift( $sections, $post->ID );

	$i = 0;

	foreach ( (array) $sections as $section_id ) {

		//only allow 0 on first pass
		if ( $i > 0 && ! $section_id ) {
			continue;
		}

		$section_id = absint( $section_id );
		if ( 0 === $i || get_post( $section_id ) ) {

			//The "template" is used in metabox saving to retrieve the post ID. So don't remove this!
			//Don't change the ID either; it's what designates it to retreive the post ID.
			$id = ( 0 === $i ) ? "pi-sections-template-{$section_id}" : "pi-sections-box-{$section_id}";
			add_meta_box(
				$id,
				( 0 === $i ? 'Template' : get_the_title( $section_id ) ),
				__NAMESPACE__ . '\\sections_meta_box',
				EDW_PRINT_ISSUE_CPT,
				'normal',
				'high',
				[
					'section_id' => $section_id,
				]
			);
		}
		$i ++;
	}
}

/**
 * Callback for the section metabox
 *
 * Outputs:
 * 1. An action for 3rd party output
 * 2. The hidden input for the current section ID
 * 3. A button to delete the section
 *
 * @param $post
 * @param $args
 */
function sections_meta_box( $post, $args ) {
	$section_id = $args['args']['section_id'];
	do_action( 'edw_section_metabox', $section_id );

	if( User\current_user_can_edit_print_issue() ) : ?>
	<input type="hidden" class="section_id" name="section_id" value="<?php echo absint( $section_id ); ?>"/>
	<p class="pi-section-delete">
		<a href="#"><?php esc_html_e( 'Delete section', 'eight-day-week' ); ?></a>
	</p>
	<?php endif; ?>

	<?php
}

/**
 * Gets the sections for the provided print issue
 *
 * @param $post_id int The current post's ID
 *
 * @return string Comma separated section IDs, or an empty string
 */
function get_sections( $post_id ) {
	$section_ids = get_post_meta( $post_id, 'sections', true );
	//sanitize - only allow comma delimited integers
	if ( ! ctype_digit( str_replace( ',', '', $section_ids ) ) ) {
		return '';
	}

	return $section_ids;
}

/**
 * Outputs controls to add a section
 *
 * Also outputs the hidden input containing the print issue's section ids
 * This is necessary to save the sections to the print issue
 *
 * @todo Consider how to better save sections to print issues, or perhaps even do away with the p2p2p (print issue -> section -> post) relationship
 *
 * @param $post \WP_Post The current post
 */
function add_section_output( $post ) {
	if( EDW_PRINT_ISSUE_CPT !== $post->post_type ||
	    ! User\current_user_can_edit_print_issue()
	) {
		return;
	}

	$section_ids = get_sections( $post->ID );

	?>
	<button
		class="button button-secondary"
		id="pi-section-add"><?php esc_html_e( 'Add Section', 'eight-day-week' ); ?>
	</button>
	<div id="pi-section-add-info">
		<input
			type="text"
			name="pi-section-name"
			id="pi-section-name"
			placeholder="<?php esc_html_e( 'Enter a name for the new section.', 'eight-day-week' ); ?>"
			/>
		<button
			title="<?php esc_html_e( 'Click to confirm', 'eight-day-week' ); ?>"
			id="pi-section-add-confirm"
			class="button button-secondary dashicons dashicons-yes"></button>
	</div>
	<input
		type="hidden"
		name="pi-section-ids"
		id="pi-section-ids"
		value="<?php echo esc_attr( $section_ids ); ?>"
		/>
	<?php
}

/**
 * Saves sections to the print issue, and deletes removed ones
 *
 * @todo Consider handling this via ajax so that sections are added to/removed from a print issue immediately.
 * @todo Otherwise, if one adds a section and leaves the post without saving it, orphaned sections pollute the DB, which ain't good.
 *
 * @param $post_id int The print issue post ID
 * @param $post \WP_Post The print issue
 * @param $update bool Is this an update?
 */
function update_print_issue_sections( $post_id, $post, $update ) {

	if( ! isset( $_POST['pi-section-ids'] ) ) {
		return;
	}

	$section_ids = $_POST['pi-section-ids'];

	$existing = get_sections( $post_id );
	$delete   = array_diff( explode( ',', $existing ), explode( ',', $section_ids ) );
	if ( $delete ) {
		foreach ( $delete as $id ) {
			wp_delete_post( absint( $id ), true );
		}
	}

	set_print_issue_sections( $section_ids, $post_id );

}
/**
 * Saves section IDs to the DB
 *
 * @param $section_ids string Comma separated section IDs
 * @param $print_issue_id int The Print Issue post ID
 * @param $print_issue \WP_Post the Print Issue
 */
function set_print_issue_sections( $section_ids, $print_issue_id ) {

	//sanitize - only allow comma delimited integers
	if ( ! ctype_digit( str_replace( ',', '', $section_ids ) ) ) {
		return;
	}

	update_post_meta( $print_issue_id, 'sections', $section_ids );

	//allow other parts to hook
	do_action( 'save_print_issue_sections', $print_issue_id, $section_ids );

}


/**
 * Class Section_Factory
 * @package Eight_Day_Week\Sections
 *
 * Factory that creates + updates sections
 *
 * @todo Refactor this (possibly trash it). Was just an experiment, really.
 */
class Section_Factory {

	/**
	 * Creates a section
	 *
	 * @param $name string The name of the section (title)
	 *
	 * @return int|Section|\WP_Error
	 */
	public static function create( $name ) {

		$info       = [
			'post_title' => $name,
			'post_type' => EDW_SECTION_CPT,
		];
		$section_id = wp_insert_post( $info );
		if ( $section_id ) {
			return new Section( $section_id );
		}

		return $section_id;
	}

	public static function assign_to_print_issue( $section, $print_issue ) {
		$current_sections = get_sections( $print_issue->ID );
		$new_sections = $current_sections ? $current_sections . ',' . $section->ID : $section->ID;
		set_print_issue_sections( $new_sections, $print_issue->ID );
		return $new_sections;
	}

	/**
	 * Handles an ajax request to create a section, and assigns it to the current print issuez
	 *
	 * @todo refactor to use exceptions and one json response vs pepper-style
	 */
	public static function create_ajax() {

		Core\check_elevated_ajax_referer();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : false;
		if ( ! $name ) {
			Core\send_json_error( [ 'message' => __( 'Please enter a section name.', 'eight-day-week' ) ] );
		}

		$print_issue_id = absint( $_POST['print_issue_id'] );

		$print_issue = get_post( $print_issue_id );
		if ( ! $print_issue ) {
			throw new \Exception( 'Invalid print issue specified.' );
		}

		try {
			$section = self::create( $name );
		} catch ( \Exception $e ) {
			//let the whoops message run its course
			$section = null;
		}

		if ( $section instanceof Section ) {
			self::assign_to_print_issue( $section, $print_issue );
			Core\send_json_success( [ 'section_id' => $section->ID ] );
		}

		Core\send_json_error( [ 'message' => __( 'Whoops! Something went awry.', 'eight-day-week' ) ] );
	}

	/**
	 * Handles an ajax request to update a section's title
	 *
	 * @todo refactor to use exceptions and one json response vs pepper-style
	 */
	public static function update_title_ajax() {

		Core\check_elevated_ajax_referer();

		$title = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : false;
		if ( ! $title ) {
			Core\send_json_error( [ 'message' => __( 'Please enter a section name.', 'eight-day-week' ) ] );
		}

		$post_id = isset( $_POST['post_id'] ) ? sanitize_text_field( $_POST['post_id'] ) : false;
		if ( ! $post_id ) {
			Core\send_json_error( [ 'message' => __( 'Whoops! This section appears to be invalid.', 'eight-day-week' ) ] );
		}
		try {
			self::update_title( $title, $post_id );
		} catch ( \Exception $e ) {
			Core\send_json_error( [ 'message' => $e->getMessage() ] );
		}
		Core\send_json_success();
	}

	/**
	 * Updates a section's title
	 *
	 * @param $title string The new title
	 * @param $id int The section ID
	 *
	 * @throws \Exception
	 */
	static function update_title( $title, $id ) {
		$section = new Section( $id );
		$section->update_title( $title );
	}
}

/**
 * Class Section
 * @package Eight_Day_Week\Sections
 *
 * Class that represents a section object + offers utility functions for it
 *
 * @todo Refactor this (possibly trash it). Was just an experiment, really.
 */
class Section {

	/**
	 * @var int The section's post ID
	 */
	var $ID;

	/**
	 * @var \WP_Post The section's post
	 */
	private $_post;

	/**
	 * Ingests a section based on a post ID
	 *
	 * @param $id int The section's post ID
	 *
	 * @throws \Exception
	 */
	function __construct( $id ) {
		$this->ID = absint( $id );
		$this->import_post();
		$this->import_post_info();
	}

	/**
	 * Sets the object's _post property
	 *
	 * @throws \Exception
	 */
	private function import_post() {
		$post = get_post( $this->ID );
		if ( ! $post instanceof \WP_Post ) {
			throw new \Exception( __( 'Invalid post ID supplied', 'eight-day-week' ) );
		}
		$this->_post = $post;
	}

	/**
	 * Ingests the \WP_Post
	 * by duplicating its properties to this object's properties
	 *
	 * @todo Refactor away, unnecessary to have/perform
	 */
	private function import_post_info() {

		$info = $this->_post;

		if ( is_object( $info ) ) {
			$info = get_object_vars( $info );
		}
		if ( is_array( $info ) ) {
			foreach ( $info as $key => $value ) {
				if ( ! empty( $key ) ) {
					$this->$key = $value;
				} else if ( ! empty( $key ) && ! method_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}

	/**
	 * Updates the section
	 *
	 * @param $args array The arguments with which to update
	 *
	 * @uses wp_update_post
	 *
	 * @todo Refactor away, just use wp_update_post
	 *
	 * @return int|\WP_Error The result of wp_update_post
	 * @throws \Exception
	 */
	function update( $args ) {
		$result = wp_update_post( $args );
		if ( $result ) {
			return $result;
		}
		throw new \Exception( sprintf( __( 'Failed to update section %d', 'eight-day-week' ), $this->ID ) );
	}

	/**
	 * Updates a section's title
	 *
	 * @param $title string The new title
	 *
	 * @throws \Exception
	 */
	function update_title( $title ) {
		if ( ! $title ) {
			throw new \Exception( __( 'Please supply a valid, non-empty title', 'eight-day-week' ) );
		}
		$title  = sanitize_text_field( $title );
		$args   = [
			'ID'         => $this->ID,
			'post_title' => $title,
		];
		$result = $this->update( $args );
	}
}

/**
 * Override the default metabox order for PI CPT
 *
 * By default, metabox order is stored per user, per "$page"
 * We want per post, and not per user.
 * This stores the metabox in post meta instead, allowing cross-user order storage
 */
function save_metabox_order() {
	check_ajax_referer( 'meta-box-order' );
	$order = isset( $_POST['order'] ) ? (array) $_POST['order'] : false;

	if( ! $order ) {
		return;
	}

	$page = isset( $_POST['page'] ) ? $_POST['page'] : '';

	if ( $page != sanitize_key( $page ) )
		wp_die( 0 );

	//only intercept PI CPT
	if( EDW_PRINT_ISSUE_CPT !== $page ) {
		return;
	}

	if ( ! $user = wp_get_current_user() )
		wp_die( -1 );

	//don't allow print prod users to re-order
	if( ! User\current_user_can_edit_print_issue() ) {
		wp_die( -1 );
	}

	//grab the post ID from the section template
	$metaboxes = explode( ',', $order['normal'] );
	$template = false;
	foreach( $metaboxes as $metabox ) {
		if( strpos( $metabox, 'template' ) !== FALSE ) {
			$template = $metabox;
		}
	}

	//couldnt find PI template, which contains PI post ID
	if( ! $template ) {
		return;
	}

	$parts = explode( '-', $template );
	$post_id = end( $parts );

	$post = get_post( $post_id );

	if( ! $post || ( $post ) && EDW_PRINT_ISSUE_CPT !== $post->post_type ) {
		return;
	}

	update_post_meta( $post_id, 'section-order', $order );

	wp_die( 1 );
}

/**
 * Gets the order of sections for a print issue
 *
 * @param $result string The incoming order
 *
 * @return mixed Modified order, if found in post meta, else the incoming value
 */
function get_section_order( $result ) {
	global $post;

	if( $post && $order = get_post_meta( $post->ID, 'section-order', true ) ) {
		return $order;
	}

	return $result;
}

/**
 * Outputs a Save button
 */
function section_save_button(){
	if ( Print_Issue\is_read_only_view() || ! User\current_user_can_edit_print_issue() ) {
		return;
	}
	echo '<button class="button button-primary">' . esc_html( 'Save', 'eight-day-week' ) . '</button>';
}