<?php
/**
 * Plugin Name: WooCommerce PDF Invoices & Packing Slips number tools
 * Plugin URI: http://www.wpovernight.com
 * Description: Provides debugging tools for invoice numbers
 * Version: 1.0
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
		add_action( 'wpo_wcpdf_settings_output_number_tools', array( $this, 'number_tools_page' ), 10, 1);
		add_action( 'wp_ajax_renumber_or_delete_invoices', 'wpo_wcpdf_renumber_or_delete_invoices' );
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

	public function number_tools_page( $active_section = '' ) {
		if ( empty($active_section) ) {
			$active_section = 'tools';
		}
		$sections = [
			'tools'           => __('Tools'),
			'invoice_numbers' => __('Invoice Numbers'),
		];
		?>
		<div class="wcpdf_document_settings_sections">
			<ul>
				<?php
				foreach ($sections as $section => $title) {
					printf('<li><a href="%s" class="%s">%s</a></li>', add_query_arg( 'section', $section ), $section == $active_section ? 'active' : '', $title );
				}
				?>
			</ul>
		</div>
		<?php
		switch ( $active_section ) {
			case 'tools':
			default:
				$this->number_tools();
				break;
			case 'invoice_numbers':
				$this->number_store_overview( 'invoice_number');
				break;
		}

	}

	public function number_store_overview( $store_name ) {
		global $wpdb;
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';
		?>
		<p>Below is a list of all the invoice numbers generated since the last reset (which happens when you set the "next invoice number" value in the settings). Numbers may have been assigned to orders before this.</p>
		<?php
		$table_name = apply_filters( "wpo_wcpdf_number_store_table_name", "{$wpdb->prefix}wcpdf_{$store_name}", $store_name, null ); // i.e. wp_wcpdf_invoice_number
		$results = $wpdb->get_results( "SELECT * FROM {$table_name}" );
		echo '<table class="wcpdf-invoice-number-store">';
		echo "<tr><th>Number</th><th>Calculated</th><th>Date</th><th>Order ID</th><th>Status</th></tr>";
		foreach ($results as $result) {
			$order = wc_get_order( $result->order_id );
			if (!empty($order)) {
				$order_status = $order->get_status();
				$status = sprintf( '<mark class="order-status %s"><span>%s</span></mark>', esc_attr( sanitize_html_class( 'status-' . $order->get_status() ) ), esc_html( wc_get_order_status_name( $order->get_status() ) ) );
			} else {
				$status = "<strong>unknown</strong>";

			}

			$url = sprintf('post.php?post=%s&action=edit', $result->order_id);
			$link = sprintf('<a href="%s">#%s</a>', $url, $result->order_id);
			$calculated = isset($result->calculated_number) ? $result->calculated_number : '-';
			printf("<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>", $result->id, $calculated, $result->date, $link, $status);
		}
		echo '</table>';

	}

	public function number_tools() {
		$number_tools_nonce = wp_create_nonce( "wpo_wcpdf_number_tools_nonce" );
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';
		?>
		<script type="text/javascript" >
		jQuery(document).ready(function($) {
			$( "#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to" ).datepicker({ dateFormat: 'yy-mm-dd' });

			$('.number-tools-btn').click(function() {
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

					jQuery.post(ajaxurl, data, function(response) {
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
