<?php
/**
 * Plugin Name: PDF Invoices & Packing Slips for WooCommerce - Number Tools
 * Plugin URI: https://www.wpovernight.com/
 * Description: Provides debugging tools for invoice numbers
 * Version: 2.4.2
 * Author: Ewout Fernhout
 * Author URI: https://www.wpovernight.com/
 * License: GPLv2 or later
 * License URI: http://www.opensource.org/licenses/gpl-license.php
 * Text Domain: woocommerce-pdf-ips-number-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists( 'WPO_WCPDF_Number_Tools' ) ) :

class WPO_WCPDF_Number_Tools {
	protected static $_instance = null;

	/**
	 * Main Plugin Instance
	 *
	 * Ensures only one instance of plugin is loaded or can be loaded.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array($this, 'load_textdomain') );
		add_filter( 'wpo_wcpdf_settings_tabs', array( $this, 'number_tools_tab' ), 10, 1);
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts_styles' ) ); // Load scripts & styles
		add_action( 'wpo_wcpdf_settings_output_number_tools', '__return_true', 10, 1);
		add_action( 'wpo_wcpdf_after_settings_page', array( $this, 'number_tools_page' ), 10, 2);
		add_action( 'wp_ajax_renumber_or_delete_invoices', 'wpo_wcpdf_renumber_or_delete_invoices' );
		add_action( 'admin_notices', array( $this, 'deactivate_extension_notice' ) );

		// on activation
		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		// on deactivation
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Activation hook
	 */
	public function activate() {
		set_transient( 'wpo_wcpdf_number_tools_activated', current_time( 'timestamp' ) );
	}

	/**
	 * Deactivation hook
	 */
	public function deactivate() {
		delete_transient( 'wpo_wcpdf_number_tools_activated' );
	}

	public function deactivate_extension_notice() {
		if( $activation_timestamp = get_transient( 'wpo_wcpdf_number_tools_activated' ) ) {
			$message         = __( "The <strong>Number Tools</strong> extension for <strong>PDF Invoices & Packing Slips for WooCommerce</strong> has been active for more than a week now. If you don't plan to use it, we recommend that you remove it from your site!", 'woocommerce-pdf-ips-number-tools' );
			$activation_date = new DateTime();
			$activation_date->setTimestamp( $activation_timestamp );
			$current_date    = new DateTime( 'now' );
			$difference      = $activation_date->diff( $current_date );

			if( $difference->days > 30 ) {
				ob_start();
				?>
				<div class="notice notice-info">
					<p><?= $message; ?></p>
					<p><a href="<?php echo esc_url( add_query_arg( 'wpo_wcpdf_number_tools_activated_notice', 'true' ) ); ?>"><?php _e( 'Hide this message', 'woocommerce-pdf-ips-number-tools' ); ?></a></p>
				</div>
				<?php
				echo ob_get_clean();
			}
		}

		// delete transient on dismiss
		if ( isset( $_GET['wpo_wcpdf_number_tools_activated_notice'] ) ) {
			delete_transient( 'wpo_wcpdf_number_tools_activated' );
			wp_redirect( 'admin.php?page=wpo_wcpdf_options_page' );
			exit;
		}
	}

	/**
		* Load plugin textdomain.
		*
		* @return void
		*/
		public function load_textdomain() {
			load_plugin_textdomain( 'woocommerce-pdf-ips-number-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

	public function load_scripts_styles( $hook ) {
		$tab = isset($_GET['tab']) ? $_GET['tab'] : '';
		$page = isset($_GET['page']) ? $_GET['page'] : '';
		if( $page != 'wpo_wcpdf_options_page' || $tab != 'number_tools') {
			return;
		}
		wp_enqueue_style(
			'woocommerce-pdf-ips-number-tools-jquery-ui-style',
			'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css'
		);
		wp_enqueue_script( 'jquery-ui-datepicker' );
	}

	public function number_tools_tab( $tabs ) {
		$tabs['number_tools'] = __( 'Number Tools', 'woocommerce-pdf-ips-number-tools' );
		return $tabs;
	}

	public function number_tools_page( $active_tab = '', $active_section = '' ) {
		if ( $active_tab !== 'number_tools' ) {
			return;
		}
		if ( empty($active_section) ) {
			$active_section = 'numbers';
		}
		$sections = [
			'numbers' => __( 'Document Numbers', 'woocommerce-pdf-ips-number-tools' ),
			'tools'   => __( 'Tools', 'woocommerce-pdf-ips-number-tools' ),
		];
		?>
		<div class="wcpdf-settings-sections">
			<ul>
				<?php
				foreach ($sections as $section => $title) {
					$url = remove_query_arg( 's', add_query_arg( 'section', $section ) );
					printf('<li><a href="%s" class="%s">%s</a></li>', esc_url( $url ), $section == $active_section ? 'active' : '', $title );
				}
				?>
			</ul>
		</div>
		<?php
		switch ( $active_section ) {
			case 'numbers':
			default:
				$this->number_store_overview( 'invoice_number' );
				break;
			case 'tools':
				$this->number_tools();
				break;
		}

	}

	public function number_store_overview( $store_name ) {
		global $wpdb;
		include_once( plugin_dir_path( __FILE__ ) . 'number-store-list-table.php' );
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';

		$number_store_tables = $this->get_number_store_tables();
		if ( isset( $_GET['table_name'] ) ) {
			$selected_table_name = $_GET['table_name'];
		} else {
			$_GET['table_name'] = $selected_table_name = apply_filters( "wpo_wcpdf_number_store_table_name", "{$wpdb->prefix}wcpdf_{$store_name}", $store_name, null ); // i.e. wp_wcpdf_invoice_number or wp_wcpdf_invoice_number_2021
			if( ! isset( $number_store_tables[ $_GET['table_name'] ] ) ) {
				$_GET['table_name'] = $selected_table_name = null;
			}
		}

		$list_table = new WPO_WCPDF_Number_Tools_List_Table();
		$list_table->prepare_items();
		?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php _e( 'Choose a number store', 'woocommerce-pdf-ips-number-tools' ); ?></th>
					<td>
						<form id="wpo_wcpdf_number_tools-store" method="get" action="<?= esc_url( add_query_arg( array() ) ) ?>">
							<select name="table_name">
								<option selected disabled><?php _e( 'Select…', 'woocommerce-pdf-ips-number-tools' ); ?></option>
								<?php foreach( $number_store_tables as $table_name => $title ) : ?>
									<?php if( isset( $_GET['table_name'] ) && $_GET['table_name'] == $table_name ) : ?>
										<option value="<?= $table_name; ?>" selected><?= $title; ?></option>
									<?php else : ?>
										<option value="<?= $table_name; ?>"><?= $title; ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<?php
								$query_args = array( 'page', 'tab', 'section' );
								foreach ($query_args as $query_arg) {
									$value = isset( $_GET[$query_arg]) ? $_GET[$query_arg] : '';
									printf('<input type="hidden" name="%s" value="%s" />', $query_arg, $value);
								}
							?>
							<button class="button"><?php _e('View', 'woocommerce-pdf-ips-number-tools'); ?></button>
						</form>
					</td>
				</tr>
			</tbody>
		</table>
		<?php // $list_table->views(); ?>
		<?php if( ! empty( $selected_table_name ) && ! empty( $number_store_tables[$selected_table_name] ) ) : ?>
			<p><?php _e('Below is a list of all the document numbers generated since the last reset (which happens when you set the "next {document name} number" value in the settings). Numbers may have been assigned to orders before this.', 'woocommerce-pdf-ips-number-tools'); ?></p>
			<div>
				<form id="wpo_wcpdf_number_tools-filter" method="get" action="<?= esc_url( add_query_arg( array() ) ) ?>">
					<?php
					$query_args = array( 'page', 'tab', 'section', 'table_name' );
					foreach ($query_args as $query_arg) {
						$value = isset( $_GET[$query_arg]) ? $_GET[$query_arg] : '';
						printf('<input type="hidden" name="%s" value="%s" />', $query_arg, $value);
					}
					$list_table->search_box( __( 'Search number', 'woocommerce-pdf-ips-number-tools' ), 'wpo_wcpdf_number_tools' );
					?>
				</form>
			
				<form id="wpo_wcpdf_number_tools-action" method="post">
					<?php $list_table->display(); ?>
				</form>
			</div>	
		<?php else : ?>
			<div class="notice notice-info inline">
				<p><?php _e( 'Please select a number store!', 'woocommerce-pdf-ips-number-tools' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	private function get_number_store_tables() {
		global $wpdb;
		$tables = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}wcpdf_%'" );
		$document_titles = $this->get_document_titles();
		$table_names = array();
		foreach( $tables as $table ) {
			foreach( $table as $table_name ) {
				if ( ! empty ( $table_name ) ) {
					// strip the default prefix
					$store_name = $full_store_name = substr( $table_name, strpos( $table_name, 'wcpdf_' ) + strlen( 'wcpdf_' ) );
					// strip year suffix, if present
					if ( is_numeric( substr( $full_store_name, -4 ) ) ) {
						$store_name = trim( substr( $full_store_name, 0, -4 ), '_' );
					}
					// strip '_number' and other remaining suffixes
					$suffix = substr( $full_store_name, strpos( $full_store_name, '_number' ) + strlen( '_number' ) );
					$clean_suffix = trim( str_replace( '_number', '', $suffix ), '_' );
					$name = substr( $store_name, 0, strpos( $store_name, '_number' ) );
					if ( ! empty ( $document_titles[$name] ) ) {
						$title = $document_titles[$name];
					} else {
						$title = ucwords( str_replace( array( "__", "_", "-" ), ' ', $name ) );
					}
					if ( ! empty ( $suffix ) ) {
						$title = "{$title} ({$clean_suffix})";
					}
					$table_names[ $table_name ] = $title;
				}
			}
		}

		ksort( $table_names );

		return $table_names;
	}

	private function get_document_titles() {
		$titles = array();
		foreach ( WPO_WCPDF()->documents->get_documents() as $document ) {
			$title = $document->get_title();
			$titles[$document->slug] = $title;
			$titles[$document->type] = $title;
		}
		return $titles;
	}

	public function number_tools() {
		$number_tools_nonce = wp_create_nonce( 'wpo_wcpdf_number_tools_nonce' );
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';
		?>
		<script>
			jQuery( document ).ready( function( $ ) {
				$( '#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to' ).datepicker( { dateFormat: 'yy-mm-dd' } );

				$( '.number-tools-btn' ).click( function( event ) {
					event.preventDefault();

					let documentType     = '';
					let dateFrom         = '';
					let dateTo           = '';
					let deleteOrRenumber = '';
					let pageCount        = 1;
					let documentCount    = 0;

					if ( 'renumber-documents-btn' === this.id ) {
						documentType     = $( '#renumber-document-type' ).val();
						dateFrom         = $( '#renumber-date-from' ).val();
						dateTo           = $( '#renumber-date-to' ).val();
						deleteOrRenumber = 'renumber';
						
						$( '.renumber-spinner' ).css( 'visibility', 'visible' );
					
					} else if ( 'delete-documents-btn' === this.id ) {
						documentType     = $( '#delete-document-type' ).val();
						dateFrom         = $( '#delete-date-from' ).val();
						dateTo           = $( '#delete-date-to' ).val();
						deleteOrRenumber = 'delete';
						
						$( '.delete-spinner' ).css( 'visibility', 'visible' );
					}
					
					$( '#renumber-documents-btn, #delete-documents-btn' ).attr( 'disabled', true );
					$( '#renumber-document-type, #renumber-date-from, #renumber-date-to, #delete-document-type, #delete-date-from, #delete-date-to' ).prop( 'disabled', true );

					// first call
					renumberOrDeleteDocuments( documentType, dateFrom, dateTo, pageCount, documentCount, deleteOrRenumber );

					function renumberOrDeleteDocuments( documentType, dateFrom, dateTo, pageCount, documentCount, deleteOrRenumber ) {
						let data = {
							'action':             'renumber_or_delete_invoices',
							'delete_or_renumber': deleteOrRenumber,
							'document_type':      documentType,
							'date_from':          dateFrom,
							'date_to':            dateTo,
							'page_count':         pageCount,
							'document_count':     documentCount,
							'security':           '<?php echo $number_tools_nonce; ?>'
						};

						$.ajax( {
							type:     'POST',
							url:      ajaxurl,
							data:     data,
							dataType: 'json',
							success: function( response ) {
								if ( false === response.data.finished ) {
									// update page count and invoice count
									documentType  = response.data.documentType;
									pageCount     = response.data.pageCount;
									documentCount = response.data.documentCount;
									
									// recall function
									renumberOrDeleteDocuments( documentType, dateFrom, dateTo, pageCount, documentCount, deleteOrRenumber );
									
								} else {
									$( '.renumber-spinner, .delete-spinner' ).css( 'visibility', 'hidden' );
									$( '#renumber-documents-btn, #delete-documents-btn' ).removeAttr( 'disabled' );
									$( '#renumber-document-type, #renumber-date-from, #renumber-date-to, #delete-document-type, #delete-date-from, #delete-date-to' ).prop( 'disabled', false );
									let message = response.data.message;
									alert( documentCount + message );
								}
							},
							error: function( xhr, ajaxOptions, thrownError ) {
								alert( xhr.status + ':'+ thrownError );
							}
						} );
					};
				} );
			} );
		</script> 

		<div class="wpo-wcpdf-number-tools">
			<div class="notice notice-warning inline">
				<p><?php _e( '<strong>IMPORTANT:</strong> Create a backup before using this tools, the actions they performs are irreversible!', 'woocommerce-pdf-ips-number-tools' ); ?></p>
			</div>
			<form id="number-tools" >
				<?php $documents = WPO_WCPDF()->documents->get_documents( 'all' ); ?>
				<div class="tool renumber-documents">
					<strong class="name"><?php _e( 'Renumber existing documents', 'woocommerce-pdf-ips-number-tools' ); ?></strong>
					<p class="description"><?php _e( 'This tool will renumber existing documents within the selected order date range, while keeping the assigned document date.', 'woocommerce-pdf-ips-number-tools' ); ?></p>
					<div class="document-type">
						<span><?php _e( 'Document type:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<select id="renumber-document-type" name="renumber-document-type">
							<option value=""><?php _e( 'Select', 'woocommerce-pdf-ips-number-tools' ); ?>...</option>
							<?php foreach ( $documents as $document ) : ?>
								<option value="<?php echo $document->get_type(); ?>"><?php echo $document->get_title(); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="date-range">
						<span><?php _e( 'From:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<input type="text" id="renumber-date-from" name="renumber-date-from" value="<?php echo date( 'Y-m-d' ); ?>" size="10"><span class="add-info"><?php _e( '(as: yyyy-mm-dd)', 'woocommerce-pdf-ips-number-tools' ); ?></span>
					</div>
					<div class="date-range">
						<span><?php _e( 'To:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<input type="text" id="renumber-date-to" name="renumber-date-to" value="<?php echo date( 'Y-m-d' ); ?>" size="10"><span class="add-info"><?php _e( '(as: yyyy-mm-dd)', 'woocommerce-pdf-ips-number-tools' ); ?></span>
					</div>
					<button class="button button-large number-tools-btn" id="renumber-documents-btn"><?php _e( 'Renumber documents', 'woocommerce-pdf-ips-number-tools' ); ?></button>
					<div class="spinner renumber-spinner"></div>
				</div>
				<div class="tool delete-documents">
					<strong class="name"><?php _e( 'Delete existing documents', 'woocommerce-pdf-ips-number-tools' ); ?></strong>
					<p class="description"><?php _e( 'This tool will delete existing documents within the selected order date range.', 'woocommerce-pdf-ips-number-tools' ); ?></p>
					<div class="document-type">
						<span><?php _e( 'Document type:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<select id="delete-document-type" name="delete-document-type">
							<option value=""><?php _e( 'Select', 'woocommerce-pdf-ips-number-tools' ); ?>...</option>
							<?php foreach ( $documents as $document ) : ?>
								<option value="<?php echo $document->get_type(); ?>"><?php echo $document->get_title(); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="date-range">
						<span><?php _e( 'From:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<input type="text" id="delete-date-from" name="delete-date-from" value="<?php echo date( 'Y-m-d' ); ?>" size="10"><span class="add-info"><?php _e( '(as: yyyy-mm-dd)', 'woocommerce-pdf-ips-number-tools' ); ?></span>
					</div>
					<div class="date-range">
						<span><?php _e( 'To:', 'woocommerce-pdf-ips-number-tools' ); ?></span>
						<input type="text" id="delete-date-to" name="delete-date-to" value="<?php echo date( 'Y-m-d' ); ?>" size="10"><span class="add-info"><?php _e( '(as: yyyy-mm-dd)', 'woocommerce-pdf-ips-number-tools' ); ?></span>
					</div>
					<button class="button button-large number-tools-btn" id="delete-documents-btn"><?php _e( 'Delete documents', 'woocommerce-pdf-ips-number-tools' ); ?></button>
					<div class="spinner delete-spinner"></div>
				</div>

			</form>
		</div>
		<?php
	}
}

function wpo_wcpdf_renumber_or_delete_invoices() {
	check_ajax_referer( 'wpo_wcpdf_number_tools_nonce', 'security' );

	$from_date          = date_i18n( 'Y-m-d', strtotime( $_POST['date_from'] ) );
	$to_date            = date_i18n( 'Y-m-d', strtotime( $_POST['date_to'] ) );
	$document_type      = esc_attr( $_POST['document_type'] );
	$document_title     = ucfirst( str_replace( '-', ' ', $document_type ) );
	$page_count         = absint( $_POST['page_count'] );
	$document_count     = absint( $_POST['document_count'] );
	$delete_or_renumber = esc_attr( $_POST['delete_or_renumber'] );
	$message            = ( 'delete' === $delete_or_renumber ) ? " {$document_title} " . __( 'documents deleted.', 'woocommerce-pdf-ips-number-tools' ) : " {$document_title} " . __( 'documents renumbered.', 'woocommerce-pdf-ips-number-tools' );
	$finished           = false;

	$args = array(
		'return'         => 'ids',
		'type'           => 'shop_order',
		'limit'          => -1,
		'order'          => 'ASC',
		'paginate'       => true,
		'posts_per_page' => 50,
		'page'           => $page_count,
		'date_created'   => $from_date . '...' . $to_date,
	);

	$results   = wc_get_orders( $args );
	$order_ids = $results->orders;
	
	if ( ! empty( $order_ids ) && ! empty( $document_type ) ) {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( empty( $order ) ) {
				continue;
			}
			
			$document = wcpdf_get_document( $document_type, $order );
			
			if ( $document && $document->exists() ) {
				if ( 'renumber' === $delete_or_renumber && is_callable( array( $document, 'init_number' ) ) ) {
					$document->init_number();
					$document->save();
				} elseif ( 'delete' === $delete_or_renumber && is_callable( array( $document, 'delete' ) ) ) {
					$document->delete();
				}
				$document_count++;
			}
		}
		$page_count++;

	// no more order IDs
	} else {
		$finished = true;
	}

	$response = array(
		'finished'      => $finished,
		'documentType'  => $document_type,
		'pageCount'     => $page_count,
		'documentCount' => $document_count,
		'message'       => $message,
	);
	
	wp_send_json_success( $response );	
		
	wp_die(); // this is required to terminate immediately and return a proper response
}

endif; // class_exists

/**
 * Returns the main instance of the plugin to prevent the need to use globals.
 *
 * @since  1.0
 * @return WPO_WCPDF_Number_Tools
 */
function WPO_WCPDF_Number_Tools() {
	return WPO_WCPDF_Number_Tools::instance();
}

WPO_WCPDF_Number_Tools(); // load plugin
