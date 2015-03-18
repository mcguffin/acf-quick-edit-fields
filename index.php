<?php

/*
Plugin Name: ACF QuickEdit Fields
Plugin URI: http://wordpress.org/
Description: Enter description here.
Author: Jörn Lund
Version: 1.0.0
Author URI: 
License: GPL3
*/

/*  Copyright 2015  Jörn Lund

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if ( ! class_exists( 'ACFToQuickEdit' ) ) :
class ACFToQuickEdit {
	private static $_instance = null;
	private $post_field_prefix = 'acf_qed_';

	private $column_fields = array();	
	private $quickedit_fields = array();	
	private $bulkedit_fields = array();	
	/**
	 * Getting a singleton.
	 *
	 * @return object single instance of SteinPostTypePerson
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) )
			self::$_instance = new self();
		return self::$_instance;
	}
	/**
	 * Private constructor
	 */
	private function __construct() {
		add_action( 'admin_init' , array(&$this,'admin_init') );
		add_action( 'admin_init' , array( &$this , 'init_columns' ) );
		add_action( 'load-admin-ajax.php' , array( &$this , 'init_columns' ) );
		add_action( 'wp_ajax_get_acf_post_meta' , array( &$this , 'ajax_get_acf_post_meta' ) );
		add_action( 'plugins_loaded' , array( &$this , 'load_textdomain' ) );
	}
	/**
	 * Hooked on 'plugins_loaded' 
	 * Load text domain
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'acf-quick-edit-fields' , false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
	
	function admin_init() {
		// ACF Field Settings
		$types = array( 'checkbox' , 'color_picker' , 'date_picker' , 'email' , 'number' , 'radio' , 'select' , 'text' , 'true_false' , 'url' );
		$types = apply_filters( 'acf_scalar_fields' , $types);
		foreach ( $types as $type )
			add_action( "acf/render_field_settings/type={$type}" , array( &$this , 'render_column_settings' ) );
	}
	
	function render_column_settings( $field ) {
		$post = get_post($field['ID']);
		$parent = get_post( $post->post_parent );
		
		if ( $parent->post_type == 'acf-field-group' ) {
			// show column: todo: allow sortable
			acf_render_field_setting( $field, array(
				'label'			=> __('Show Column','acf-quick-edit-fields'),
				'instructions'	=> '',
				'type'			=> 'true_false',
				'name'			=> 'show_column',
				'message'		=> __("Show a column in the posts list table", 'acf-quick-edit-fields')
			));
		
			// add to quick edit
			acf_render_field_setting( $field, array(
				'label'			=> __('Allow QuickEdit','acf-quick-edit-fields'),
				'instructions'	=> '',
				'type'			=> 'true_false',
				'name'			=> 'allow_quickedit',
				'message'		=> __("Allow editing this field in QuickEdit mode", 'acf-quick-edit-fields')
			));
		
			// add to bulk edit
			acf_render_field_setting( $field, array(
				'label'			=> __('Allow Bulk Edit','acf-quick-edit-fields'),
				'instructions'	=> '',
				'type'			=> 'true_false',
				'name'			=> 'allow_bulkedit',
				'message'		=> __("Allow editing this field in Bulk edit mode", 'acf-quick-edit-fields')
			));
		}
	}
	
	function init_columns( $cols ) {
		global $typenow;
		$post_type = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : $typenow;
		$field_groups = acf_get_field_groups( array(
			'post_type' => $post_type,
		) );

		foreach ( $field_groups as $field_group ) {
			$fields = acf_get_fields($field_group);
			foreach ( $fields as $field ) {
				if ( isset($field['show_column']) && $field['show_column'] ) {
					$this->column_fields[$field['name']] = $field;
				}
				if ( isset($field['allow_quickedit']) && $field['allow_quickedit'] ) {
					$this->quickedit_fields[$field['name']] = $field;
				}
				if ( isset($field['allow_bulkedit']) && $field['allow_bulkedit'] ) {
					$this->bulkedit_fields[$field['name']] = $field;
				}
			}
		}
		if ( count( $this->column_fields ) ) {
			if ( 'post' == $post_type ) {
				$cols_hook		= 'manage_posts_columns';
				$display_hook	= 'manage_posts_custom_column';
			} else if ( 'page' == $post_type ) {
				$cols_hook		= 'manage_pages_columns';
				$display_hook	= 'manage_pages_custom_column';
			} else if ( 'attachment' == $post_type ) {
				$cols_hook		= 'manage_media_columns';
				$display_hook	= 'manage_media_custom_column';
			} else {
				$cols_hook		= "manage_{$post_type}_posts_columns";
				$display_hook	= "manage_{$post_type}_posts_custom_column";
			}
			add_filter( $cols_hook ,    array( &$this , 'add_field_columns' ) );
			add_filter( $display_hook , array( &$this , 'display_field_column' ) , 10 , 2 );
		}
		if ( count( $this->quickedit_fields ) ) {
			add_action( 'quick_edit_custom_box',  array(&$this,'display_quick_edit') , 10, 2);
			add_action( 'save_post', array( &$this , 'quickedit_save_acf_meta' ) );
			wp_enqueue_script( 'acf-quick-edit', plugins_url('js/acf-quickedit.js', __FILE__), false, null, true );
		}
		
		if ( count( $this->bulkedit_fields ) ) {
			add_action( 'bulk_edit_custom_box', array( &$this , 'display_bulk_edit' ), 10, 2 );
// 			add_action( 'post_updated', array( &$this , 'quickedit_save_acf_meta' ) );
		}
	}
	
	function ajax_get_acf_post_meta() {
		header('Content-Type: application/json');
		if ( isset( $_REQUEST['post_id'] , $_REQUEST['acf_field_keys'] ) ) {
			$result = array();
			 
			$post_ids = (array) $_REQUEST['post_id'];
			array_filter( $post_ids,'intval');
			foreach ( $post_ids as $post_id ) {
				if ( current_user_can( 'edit_post' , $post_id ) ) {
					$field_keys = $_REQUEST['acf_field_keys'];
					foreach ( $field_keys as $key ) {
						$field_obj = get_field_object( $key , $post_id );
						if ( ! isset( $result[ $key ] ) || $result[ $key ] == $field_obj['value'] ) 
							$result[ $key ] = $field_obj['value'];
						else 
							$result[ $key ] = '';
					}
				}
			}
			echo json_encode( $result );
			exit();
		}
	}
	
	function add_field_columns( $columns ) {
		foreach ( $this->column_fields as $field_slug => $field ) {
			$columns[ $field_slug ] = $field['label'];
		}
		return $columns;
	}
	
	function display_field_column( $column , $post ) {
		if ( isset( $this->column_fields[$column] ) ) {
			$field = $this->column_fields[$column];
			the_field($column);
		}
	}
	
	function display_quick_edit( $column, $post_type ) {
		if ( $field = $this->quickedit_fields[$column] ) {
			$this->display_quickedit_field( $column, $post_type , $field  );
		}
	}
	function display_bulk_edit( $column, $post_type ) {
		if ( $field = $this->bulkedit_fields[$column] ) {
			$this->display_quickedit_field( $column, $post_type , $field  );
		}
	}
	function display_quickedit_field( $column, $post_type , $field ) {
		?><fieldset class="inline-edit-col-right inline-edit-<?php echo $post_type ?>"><?php 
			?><div class="inline-edit-col column-<?php echo $column; ?>"><?php 
				?><label class="inline-edit-group"><?php 
					?><span class="title"><?php echo $field['label']; ?></span><?php
					?><span class="input-text-wrap"><?php
						?><input type="text" class="acf-quick-edit" data-acf-field-key="<?php echo $field['key'] ?>" name="<?php echo $this->post_field_prefix . $column; ?>" /><?php
					?></span><?php
				?></label><?php 
			?></div><?php 
		?></fieldset><?php
	}
	
	function quickedit_save_acf_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( $this->quickedit_fields as $field_name => $field ) {
			if ( isset( $_REQUEST[ $this->post_field_prefix . $field['name'] ] ) ) {
				update_post_meta( $post_id , $field['name'] , $_REQUEST[ $this->post_field_prefix . $field['name'] ] );
			}
		}
	}
	
	
}

ACFToQuickEdit::instance();
endif;