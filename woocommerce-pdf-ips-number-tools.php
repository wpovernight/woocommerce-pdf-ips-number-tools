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
		add_action( 'wpo_wcpdf_settings_output_number_tools', array( $this, 'number_tools_page' ), 10, 1);
		add_action( 'wp_ajax_renumber_invoices', 'wpo_wcpdf_renumber_invoices' );
		add_action( 'wp_ajax_delete_invoices', 'wpo_wcpdf_delete_invoices' );
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
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';
		?>
		<script type="text/javascript" >
		jQuery(document).ready(function($) {
			$( "#renumber-date-from, #renumber-date-to, #delete-date-from, #delete-date-to" ).datepicker({ dateFormat: 'yy-mm-dd' });

			$('.renumber-invoices-btn').click(function() {
				let renumberFrom = $('#renumber-date-from').val();
				let renumberTo = $('#renumber-date-to').val();
				let data = {
					'action': 'renumber_invoices',
					'renumber_from': renumberFrom,
					'renumber_to': renumberTo
				};
				jQuery.post(ajaxurl, data, function(response) {
					alert(response);
				});
			});

			$('.delete-invoices-btn').click(function() {
				let deleteFrom = $('#delete-date-from').val();
				let deleteTo = $('#delete-date-to').val();
				let data = {
					'action': 'delete_invoices',
					'delete_from': deleteFrom,
					'delete_to': deleteTo
				};
				jQuery.post(ajaxurl, data, function(response) {
					alert(response);
				});
			});
		});
		</script> 

		<div class="wpo-wcpdf-number-tools">

			<form id="number-tools" >

				<div class="renumber-invoices">
					<strong class="name">Renumber existing PDF invoices</strong>
					<p class="description">This tool will renumber existing PDF invoices, while keeping the assigned invoice date. Set the "next invoice number" setting (WooCommerce > PDF Invoices > Documents > Invoice) to the number you want to use for the first invoice. Note that this process may need to run longer than your server supports, so it is advisable to do this in smaller batches (adjust the date range in the source of the snippet for this tool).</p>
					
						<div class="date-range">
							<span>From:</span>
							<input type="text" id="renumber-date-from" name="renumber-date-from" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
						</div>

						<div class="date-range">
							<span>To:</span>
							<input type="text" id="renumber-date-to" name="renumber-date-to" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
						</div>

						<span class="button button-large renumber-invoices-btn">Renumber invoices</span>

					<p class="warning"><strong>IMPORTANT:</strong> Create a backup before using this tool, the actions it performs are irreversable!</p>
				</div>

				<div class="delete-invoices">
					<strong class="name">Delete existing PDF invoices</strong>
					<p class="description">This tool will delete existing PDF invoices. Note that this process may need to run longer than your server supports, so it is advisable to do this in smaller batches (adjust the date range in the source of the snippet for this tool).</p>

					<div class="date-range">
						<span>From:</span>
						<input type="text" id="delete-date-from" name="delete-date-from" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
					</div>

					<div class="date-range">
						<span>To:</span>
						<input type="text" id="delete-date-to" name="delete-date-to" value="<?php echo date('Y-m-d'); ?>" size="10"><span class="add-info">(as: yyyy-mm-dd)</span>
					</div>

					<span class="button button-large delete-invoices-btn">Delete invoices</span>

					<p class="warning"><strong>IMPORTANT:</strong> Create a backup before using this tool, the actions it performs are irreversable!</p>
				</div>

			</form>

		</div>
		<?php
	}
}

function wpo_wcpdf_renumber_invoices() {
	$renumber_dates = array();
	$message = '';
	
	$renumber_dates['renumber_from_date'] = explode('-', $_POST['renumber_from'] );
	$renumber_dates['renumber_to_date'] = explode('-', $_POST['renumber_to'] );

	$valid_dates = wpo_wcpdf_valid_dates( $renumber_dates );

	if ( $valid_dates !== false ) {
		$page_count = 0;
		$invoice_count = 0;
		$order_ids = '';

		do { 
			$page_count++;

			$args = array(
				'return'			=> 'ids',
				'type'				=> 'shop_order',
				'limit'				=> -1,
				'order'				=> 'ASC',
				'paginate'			=> true,
				'posts_per_page' 	=> 50,
				'page'				=> $page_count,
				'date_created'		=> $_POST['renumber_from'] . '...' . $_POST['renumber_to'],
			);

			$results = wc_get_orders( $args );
			$order_ids = $results->orders;
			
			foreach ($order_ids as $order_id) {
				$order = wc_get_order( $order_id );
				if ( $invoice = wcpdf_get_invoice( $order ) ) {
					if ( $invoice->exists() ) {
						$invoice->init_number();
						$invoice->save();
						$invoice_count++;
					}
				}
			}

		} while ( !empty( $order_ids ) );
		$message = $invoice_count . ' invoices renumbered.';
	} else {
		$message = 'Invalid date(s) given!';
	}

	echo $message;
	wp_die(); // this is required to terminate immediately and return a proper response
}

function wpo_wcpdf_delete_invoices() {
	$delete_dates = array();
	$message = '';
	
	$delete_dates['delete_from_date'] = explode('-', $_POST['delete_from'] );
	$delete_dates['delete_to_date'] = explode('-', $_POST['delete_to'] );

	$valid_dates = wpo_wcpdf_valid_dates( $delete_dates );

	if ( $valid_dates !== false ) {
		$page_count = 0;
		$invoice_count = 0;
		$order_ids = '';

		do { 
			$page_count++;

			$args = array(
				'return'			=> 'ids',
				'type'				=> 'shop_order',
				'limit'				=> -1,
				'order'				=> 'ASC',
				'paginate'			=> true,
				'posts_per_page'	=> 50,
				'page'				=> $page_count,
				'date_created'		=> $_POST['delete_from'] . '...' . $_POST['delete_to'],
			);

			$results = wc_get_orders( $args );
			$order_ids = $results->orders;

			foreach ($order_ids as $order_id) {
				$order = wc_get_order( $order_id );
				if ( $invoice = wcpdf_get_invoice( $order ) ) {
					if ( $invoice->exists() ) {
						$invoice->delete();
						$invoice_count++;
					}
				}
			}

		} while ( !empty( $order_ids ) );
		$message = $invoice_count . ' invoices deleted.';
	} else {
		$message = 'Invalid date(s) given!';
	}

	echo $message;
	wp_die(); // this is required to terminate immediately and return a proper response
}

function wpo_wcpdf_valid_dates( $dates ) {
	$valid_dates = true;

	foreach( $dates as $date ) {
		if ( is_array( $date ) && count( $date ) == 3 ) {
			//check if each part of the date is numerical
			foreach ( $date as $date_part ) {
				if ( !ctype_digit( $date_part ) ) {
					$valid_dates = false;
				}
			}
			//check if date parts create an existing date
			if ( $valid_dates !== false && !checkdate( $date[1], $date[2], $date[0] ) ) {
					$valid_dates = false;
			}
		} else {
			$valid_dates = false;
		}
	}
	return $valid_dates;
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
