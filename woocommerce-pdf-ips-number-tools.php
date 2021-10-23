<?php
/**
 * Plugin Name: WooCommerce PDF Invoices & Packing Slips number tools
 * Plugin URI: http://www.wpovernight.com
 * Description: Provides debugging tools for invoice numbers
 * Version: 2.2.1
 * Author: Ewout Fernhout
 * Author URI: http://www.wpovernight.com
 * License: GPLv2 or later
 * License URI: http://www.opensource.org/licenses/gpl-license.php
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
			$message         = __( "You have the PDF Number Tools extension activated for more than a week now. If you don't plan to use it, we recommend you to deactivate it!", 'wpo_wcpdf_number_tools' );
			$activation_date = new DateTime();
			$activation_date->setTimestamp( $activation_timestamp );
			$current_date    = new DateTime( 'now' );
			$difference      = $activation_date->diff( $current_date );

			if( $difference->days > 30 ) {
				ob_start();
				?>
				<div class="notice notice-info">
					<p><?= $message; ?></p>
					<p><a href="<?php echo esc_url( add_query_arg( 'wpo_wcpdf_number_tools_activated_notice', 'true' ) ); ?>"><?php _e( 'Hide this message', 'wpo_wcpdf_number_tools' ); ?></a></p>
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
		$tabs['number_tools'] = 'Number Tools';
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
			'numbers' => __('Document Numbers'),
			'tools'   => __('Tools'),
		];
		?>
		<div class="wcpdf_document_settings_sections">
			<ul>
				<?php
				foreach ($sections as $section => $title) {
					$url = remove_query_arg( 's', add_query_arg( 'section', $section ) );
					printf('<li><a href="%s" class="%s">%s</a></li>', $url, $section == $active_section ? 'active' : '', $title );
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
					<th scope="row"><?php _e( 'Choose a number store', 'wpo_wcpdf_number_tools' ); ?></th>
					<td>
						<form id="wpo_wcpdf_number_tools-store" method="get" action="<?= add_query_arg( array() ) ?>">
							<select name="table_name">
								<option selected disabled><?php _e( 'Select', 'wpo_wcpdf_number_tools' ); ?> ...</option>
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
							<button class="button">View</button>
						</form>
					</td>
				</tr>
			</tbody>
		</table>
		<?php // $list_table->views(); ?>
		<?php if( ! empty( $selected_table_name ) && ! empty( $number_store_tables[$selected_table_name] ) ) : ?>
			<p>Below is a list of all the document numbers generated since the last reset (which happens when you set the "next {document name} number" value in the settings). Numbers may have been assigned to orders before this.</p>
			<div>
				<form id="wpo_wcpdf_number_tools-filter" method="get" action="<?= add_query_arg( array() ) ?>">
					<?php
					$query_args = array( 'page', 'tab', 'section', 'number_store' );
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
				<p><?php _e( 'Please select a number store!', 'wpo_wcpdf_number_tools' ); ?></p>
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
		$number_tools_nonce = wp_create_nonce( "wpo_wcpdf_number_tools_nonce" );
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';
		?>
		<script>
		jQuery(document).ready(function($) {
			$( "#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to" ).datepicker({ dateFormat: 'yy-mm-dd' });

			$('.number-tools-btn').click(function( event ) {
				event.preventDefault();

				let dateFrom = '';
				let dateTo = '';
				let deleteOrRenumber = '';
				let pageCount = 1;
				let invoiceCount = 0;

				if (this.id == 'renumber-invoices-btn') {
					dateFrom = $('#renumber-date-from').val();
					dateTo = $('#renumber-date-to').val();
					deleteOrRenumber = 'renumber';
					$('.renumber-spinner').css('visibility', 'visible');
					$('#renumber-invoices-btn, #delete-invoices-btn').attr('disabled', true)
					$('#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to').prop('disabled', true);
				} else if (this.id == 'delete-invoices-btn') {
					dateFrom = $('#delete-date-from').val();
					dateTo = $('#delete-date-to').val();
					deleteOrRenumber = 'delete';
					$('.delete-spinner').css('visibility', 'visible');
					$('#renumber-invoices-btn, #delete-invoices-btn').attr('disabled', true)
					$('#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to').prop('disabled', true);
				}

				//First call
				renumberOrDeleteInvoices(dateFrom, dateTo, pageCount, invoiceCount, deleteOrRenumber);

				function renumberOrDeleteInvoices(dateFrom, dateTo, pageCount, invoiceCount, deleteOrRenumber) {
					let data = {
						'action': 'renumber_or_delete_invoices',
						'delete_or_renumber': deleteOrRenumber,
						'date_from': dateFrom,
						'date_to': dateTo,
						'page_count': pageCount,
						'invoice_count': invoiceCount,
						'security': '<?php echo $number_tools_nonce; ?>'
					};

					$.ajax({
						type:		'POST',
						url:		ajaxurl,
						data:		data,
						dataType:	'json',
						success: function(response){
							if (response.data.finished === false ) {
								//update page count and invoice count
								pageCount = response.data.pageCount;
								invoiceCount = response.data.invoiceCount;
								//recall function
								renumberOrDeleteInvoices(dateFrom, dateTo, pageCount, invoiceCount, deleteOrRenumber);
							} else {
								$('.renumber-spinner, .delete-spinner').css('visibility', 'hidden');
								$('#renumber-invoices-btn, #delete-invoices-btn').removeAttr('disabled');
								$('#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to').removeProp('disabled');
								let message = response.data.message;
								alert(invoiceCount + message);
							}
						},
						error: function(xhr, ajaxOptions, thrownError) {
							alert(xhr.status+ ':'+ thrownError);
						}
					});
				};
			});
		});
		</script> 

		<div class="wpo-wcpdf-number-tools">
			<form id="number-tools" >

				<div class="renumber-invoices">
					<strong class="name">Renumber existing PDF invoices</strong>
					<p class="description">This tool will renumber existing PDF invoices within the selected order date range, while keeping the assigned invoice date.<br>Set the "next invoice number" setting (WooCommerce > PDF Invoices > Documents > Invoice) to the number you want to use for the first invoice.</p>
						<div class="date-range">
							<span>From:</span>
							<input type="text" id="renumber-date-from" name="renumber-date-from" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
						</div>
						<div class="date-range">
							<span>To:</span>
							<input type="text" id="renumber-date-to" name="renumber-date-to" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
						</div>
						<button class="button button-large number-tools-btn" id="renumber-invoices-btn">Renumber invoices</button>
						<div class="spinner renumber-spinner"></div>
					<p class="warning"><strong>IMPORTANT:</strong> Create a backup before using this tool, the actions it performs are irreversable!</p>
				</div>

				<div class="delete-invoices">
					<strong class="name">Delete existing PDF invoices</strong>
					<p class="description">This tool will delete existing PDF invoices within the selected order date range.</p>
					<div class="date-range">
						<span>From:</span>
						<input type="text" id="delete-date-from" name="delete-date-from" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
					</div>
					<div class="date-range">
						<span>To:</span>
						<input type="text" id="delete-date-to" name="delete-date-to" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
					</div>
					<button class="button button-large number-tools-btn" id="delete-invoices-btn">Delete invoices</button>
					<div class="spinner delete-spinner"></div>
					<p class="warning"><strong>IMPORTANT:</strong> Create a backup before using this tool, the actions it performs are irreversable!</p>
				</div>

			</form>
		</div>
		<?php
	}
}

function wpo_wcpdf_renumber_or_delete_invoices() {
	//Check nonce
	check_ajax_referer( 'wpo_wcpdf_number_tools_nonce', 'security' );

	$from_date = date_i18n( 'Y-m-d', strtotime( $_POST['date_from'] ) );
	$to_date = date_i18n( 'Y-m-d', strtotime( $_POST['date_to'] ) );
	$page_count = $_POST['page_count'];
	$invoice_count = $_POST['invoice_count'];
	$delete_or_renumber = $_POST['delete_or_renumber'];
	$message = $delete_or_renumber == 'delete' ? ' invoices deleted.' : ' invoices renumbered.';
	$finished = false;

	$args = array(
		'return'			=> 'ids',
		'type'				=> 'shop_order',
		'limit'				=> -1,
		'order'				=> 'ASC',
		'paginate'			=> true,
		'posts_per_page' 	=> 50,
		'page'				=> $page_count,
		'date_created'		=> $from_date . '...' . $to_date,
	);

	$results = wc_get_orders( $args );
	$order_ids = $results->orders;
	
	if ( !empty( $order_ids ) ) {
		foreach ($order_ids as $order_id) {
			$order = wc_get_order( $order_id );
			if ( $invoice = wcpdf_get_invoice( $order ) ) {
				if ( $invoice->exists() ) {
					if ( $delete_or_renumber == 'renumber' ) {
						$invoice->init_number();
						$invoice->save();
					} elseif ( $delete_or_renumber == 'delete' ) {
						$invoice->delete();
					}
					$invoice_count++;
				}
			}
		}
		$page_count++;

	//No more order IDs
	} else {
		$finished = true;
	}

	$response = array(
		'finished'		=> $finished,
		'pageCount' 	=> $page_count,
		'invoiceCount'	=> $invoice_count,
		'message'		=> $message
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
