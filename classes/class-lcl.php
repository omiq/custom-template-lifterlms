<?php
/**
 * 
 */

defined( 'ABSPATH' ) or exit;

/**
 * Loader Class for LCL
 */
class LCL {

	private static $_instance = null;

	public static function instance() {
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}

	private function __construct() {
		add_action( 'wp', array( $this, 'override_template_include' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 999 );
		add_filter( 'astra_page_layout', array( $this, 'astra_page_layout' ), 999 );
		add_filter( 'astra_get_content_layout', array( $this, 'content_layout' ), 999 );
		add_filter( 'astra_the_title_enabled', array( $this, 'page_title' ), 999 );
		add_filter( 'astra_featured_image_enabled', array( $this, 'featured_image' ), 999 );
	}

	public function astra_page_layout( $sidebar ) {

		$template = self::get_template();
		if ( $template ) {
			$template_sidebar = get_post_meta( $template, 'site-sidebar-layout', true );
			if( ! empty( $template_sidebar ) && 'default' != $template_sidebar ) {
				$sidebar = $template_sidebar;
			}
		}

		return $sidebar;
	}

	public function content_layout( $layout ) {

		$template = self::get_template();
		if ( $template ) {
			$template_layout = get_post_meta( $template, 'site-content-layout', true );
			if( ! empty( $template_layout ) && 'default' != $template_layout ) {
				$layout = $template_layout;
			}
		}

		return $layout;
	}

	public function page_title( $status ) {

		$template = self::get_template();
		if ( $template ) {
			$template_status = get_post_meta( $template, 'site-post-title', true );
			if( ! empty( $template_status ) ) {
				$status = ( 'disabled' == $template_status ) ? false : true;
			}
		}

		return $status;
	}

	public function featured_image( $status ) {

		$template = self::get_template();
		if ( $template && is_singular() ) {
			$template_status = get_post_meta( $template, 'ast-featured-img', true );
			if( ! empty( $template_status ) ) {
				$status = ( 'disabled' == $template_status ) ? false : true;
			}
		}

		return $status;
	}

	public static function get_template() {
		
		// Don't override the template if the post type is not `course`
		if ( 'course' !== get_post_type() ) {
			return false;
		}
		
		if ( is_user_logged_in() && llms_is_user_enrolled( get_current_user_id(), get_the_id() ) ) {
			return false;
		}

		$template = get_post_meta( get_the_id(), 'course_template', true );
		if ( '' == $template ) {
			return false;
		}

		return $template;
	}

	public function enqueue_scripts() {

		// Don't override the template if the post type is not `course`
		if ( 'course' !== get_post_type() ) {
			return false;
		}
		
		if ( is_user_logged_in() && llms_is_user_enrolled( get_current_user_id(), get_the_id() ) ) {
			return false;
		}

		$template = get_post_meta( get_the_id(), 'course_template', true );
		if ( class_exists( '\Elementor\Post_CSS_File' ) ) {

			if ( self::is_elementor_activated( $template ) ) {

				$css_file = new \Elementor\Post_CSS_File( $template );
				$css_file->enqueue();
			}
		}

		// Add VC style if it is activated.
		$wpb_custom_css = get_post_meta( $template, '_wpb_shortcodes_custom_css', true );
		if( ! empty( $wpb_custom_css ) ) {
			wp_add_inline_style( 'astra-addon-css', $wpb_custom_css );
		}
	}

	public function override_template_include() {

		// Don't run any code in admin area.
		if ( is_admin() ) {
			return false;
		}

		// Don't override the template if the post type is not `course`.
		if ( 'course' !== get_post_type() ) {
			return false;
		}
		
		if ( is_user_logged_in() && llms_is_user_enrolled( get_current_user_id(), get_the_id() ) ) {
			return false;
		}

		add_filter( 'the_content', array( $this, 'render' ) );
	}

	public function render( $content ) {

		$template = get_post_meta( get_the_id(), 'course_template', true );
		if( $template ) {
			$content = $this->get_action_content( $template );	
		}
		return $content;
	}

	/**
	 * Advanced Hooks get content
	 *
	 * Loads content
	 *
	 * @since 1.0.0
	 * @param int $post_id post id.
	 */
	public function get_action_content( $post_id ) {

		global $post;
		$current_post = $post;
		$post         = get_post( $post_id, OBJECT );
		setup_postdata( $post );

		if ( class_exists( 'FLBuilderModel' ) ) {
			$do_render  = apply_filters( 'fl_builder_do_render_content', true, FLBuilderModel::get_post_id() );
			$fl_enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );
			if ( $do_render && $fl_enabled ) {
				wp_reset_postdata();

				ob_start();
				if ( is_callable( 'FLBuilderShortcodes::insert_layout' ) ) {
					echo FLBuilderShortcodes::insert_layout(
						array( // WPCS: XSS OK.
							'id' => $post_id,
						)
					);
				}

				wp_reset_postdata();
				return ob_get_clean();
			}
		}
		if ( self::is_elementor_activated( $post_id ) ) {
			
			// set post to glabal post.
			$post               = $current_post;
			$elementor_instance = Elementor\Plugin::instance();
			ob_start();
			echo $elementor_instance->frontend->get_builder_content_for_display( $post_id );
			wp_reset_postdata();
			return ob_get_clean();
		}
		if ( self::is_vc_activated( $post_id ) ) {
			ob_start();
			echo do_shortcode( $post->post_content );
			wp_reset_postdata();
			return ob_get_clean();
		}

		ob_start();
		echo do_shortcode( $post->post_content );
		wp_reset_postdata();
		return ob_get_clean();

	}

	/**
	 * Check is elementor activated.
	 *
	 * @param int $id Post/Page Id.
	 * @return boolean
	 */
	public static function is_elementor_activated( $id ) {

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		if ( version_compare( ELEMENTOR_VERSION, '1.5.0', '<' ) ) {
			return ( 'builder' === Elementor\Plugin::$instance->db->get_edit_mode( $id ) );
		} else {
			return Elementor\Plugin::$instance->db->is_built_with_elementor( $id );
		}

		return false;
	}

	/**
	 * Check VC activated or not on post.
	 *
	 * @param  int $post_id Post Id.
	 * @return boolean
	 */
	public static function is_vc_activated( $post_id ) {

		$post      = get_post( $post_id );
		$vc_active = get_post_meta( $post_id, '_wpb_vc_js_status', true );

		if ( class_exists( 'Vc_Manager' ) && ( 'true' == $vc_active || has_shortcode( $post->post_content, 'vc_row' ) ) ) {
			return true;
		}

		return false;
	}

}

LCL::instance();

