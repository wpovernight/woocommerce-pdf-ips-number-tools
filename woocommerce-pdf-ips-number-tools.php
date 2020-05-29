<?php
/**
 * Plugin Name: WooCommerce PDF Invoices & Packing Slips number tools
 * Plugin URI: http://www.wpovernight.com
 * Description: Provides debugging tools for invoice numbers
 * Version: 2.0
 * Author: Ewout Fernhout
 * Author URI: http://www.wpovernight.com
 * License: GPLv2 or later
 * License URI: http://www.opensource.org/licenses/gpl-license.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists( 'WPO_WCPDF_Diagnostic_Tools' ) ) :

class WPO_WCPDF_Diagnostic_Tools {
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
		add_filter( 'wpo_wcpdf_settings_tabs', array( $this, 'diagnostic_tools_tab' ), 10, 1);
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts_styles' ) ); // Load scripts & styles
		add_action( 'wpo_wcpdf_settings_output_diagnostic_tools', '__return_true', 10, 1);
		add_action( 'wpo_wcpdf_after_settings_page', array( $this, 'diagnostic_tools_page' ), 10, 2);
		add_action( 'wp_ajax_renumber_or_delete_invoices', 'wpo_wcpdf_renumber_or_delete_invoices' );
		add_action( 'wp_ajax_remove_options', 'wpo_wcpdf_remove_options' );
	}

	public function load_scripts_styles( $hook ) {
		$tab = isset($_GET['tab']) ? $_GET['tab'] : '';
		$page = isset($_GET['page']) ? $_GET['page'] : '';
		if( $page != 'wpo_wcpdf_options_page' || $tab != 'diagnostic_tools') {
			return;
		}
		wp_enqueue_style(
			'woocommerce-pdf-ips-number-tools-jquery-ui-style',
			'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css'
		);
		wp_enqueue_script( 'jquery-ui-datepicker' );
	}

	public function diagnostic_tools_tab( $tabs ) {
		$tabs['diagnostic_tools'] = 'Diagnostic Tools';
		return $tabs;
	}

	public function diagnostic_tools_page( $active_tab = '', $active_section = '' ) {
		if ( $active_tab !== 'diagnostic_tools' ) {
			return;
		}
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
					$url = remove_query_arg( 's', add_query_arg( 'section', $section ) );
					printf('<li><a href="%s" class="%s">%s</a></li>', $url, $section == $active_section ? 'active' : '', $title );
				}
				?>
			</ul>
		</div>
		<?php
		switch ( $active_section ) {
			case 'tools':
			default:
				$this->number_tools();
				$this->remove_options();
				break;
			case 'invoice_numbers':
				$this->number_store_overview( 'invoice_number' );
				break;
		}

	}

	public function number_store_overview( $store_name ) {
		global $wpdb;
		include_once( plugin_dir_path( __FILE__ ) . 'number-store-list-table.php' );
		echo '<style type="text/css">';
		include( plugin_dir_path( __FILE__ ) . 'css/styles.css' );
		echo '</style>';

		$list_table = new WPO_WCPDF_Number_Tools_List_Table();
		$list_table->prepare_items();
		?>
		<p>Below is a list of all the invoice numbers generated since the last reset (which happens when you set the "next invoice number" value in the settings). Numbers may have been assigned to orders before this.</p>
		<div>
		<?php // $list_table->views(); ?>
		<form id="wpo_wcpdf_number_tools-filter" method="get" action="<?= add_query_arg( array() ) ?>">
			<?php
			$query_args = array( 'page', 'tab', 'section' );
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
		<?php
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

				<div class="renumber-invoices wpo-wcpdf-diagnostic-tool">
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

				<div class="delete-invoices wpo-wcpdf-diagnostic-tool">
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

	public function remove_options() {
		$remove_options_nonce = wp_create_nonce( "wpo_wcpdf_remove_options_nonce" );
		//Check active PDF Invoices extensions
		$active_pro_plugins = array( 
			'professional' => class_exists( 'WooCommerce_PDF_IPS_Pro' ) ? true : false,
			'templates' => class_exists( 'WooCommerce_PDF_IPS_Templates' ) || class_exists( 'WPO_WCPDF_Templates' ) ? true : false,
		);

		?>
		<script type="text/javascript" >
		jQuery(document).ready(function($) {

			$('.wpo-wcpdf-remove-options button').click(function() {

				let data = {
					'action': 'remove_options',
					'remove_options_from': $(this).data('remove'),
					'security': '<?php echo $remove_options_nonce; ?>'
				};

				jQuery.post(ajaxurl, data, function(response) {
					let removedOptions = response.data.removedOptions;
					let message = response.data.message;
					alert(removedOptions.join(', ') + ' ' + message);
				});
			
			});

		});
		</script> 

		<div class="wpo-wcpdf-remove-options wpo-wcpdf-diagnostic-tool">
			<strong class="name">Remove plugin options</strong>
			<p class="description">This tool will remove the plugin options from the wp_options table.</p>

			<table>

				<tr class="remove-option">
					<td><span>WooCommerce PDF Invoices & Packing Slips</span></td>
					<td><button class="button button-large remove-free-options" data-remove="free">Remove options</button></td>
				</tr>
				
				<?php if ( $active_pro_plugins['professional'] ) : ?>
					<tr class="remove-option">
						<td><span>WooCommerce PDF Invoices & Packing Slips Professional</span></td>
						<td><button class="button button-large remove-professional-options" data-remove="professional">Remove options</button></td>
					</tr>
				<?php endif; ?>

				<?php if ( $active_pro_plugins['templates'] ) : ?>
					<tr class="remove-option">
						<td><span>WooCommerce PDF Invoices & Packing Slips Premium Templates</span></td>
						<td><button class="button button-large remove-templates-options" data-remove="templates">Remove options</button></td>
					</tr>
				<?php endif; ?>

			</table>
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

function wpo_wcpdf_remove_options() {
	
	//Check nonce
	check_ajax_referer( 'wpo_wcpdf_remove_options_nonce', 'security' );

	$removable_options = array(

		'free' => array(
			'wpo_wcpdf_settings_general', 
			'wpo_wcpdf_settings_debug', 
			'wpo_wcpdf_documents_settings_invoice',
			'wpo_wcpdf_documents_settings_packing-slip',
		),
		'professional' => array(
			'wpo_wcpdf_settings_pro',
			'wpo_wcpdf_documents_settings_proforma',
			'wpo_wcpdf_documents_settings_credit-note', 
			'wpo_wcpdf_dropbox_api_v2', 
			'wpo_wcpdf_dropbox_last_export', 
			'wpo_wcpdf_dropbox_license',
			'wpo_wcpdf_dropbox_queue', 
			'wpo_wcpdf_dropbox_settings', 
			'wpo_wcpdf_dropbox_version',
		),
		'templates' => array(
			'wpo_wcpdf_editor_settings',
		),

	);

	$remove_options_from = sanitize_text_field( $_POST['remove_options_from'] );

	foreach ( $removable_options[$remove_options_from] as $option ) {
		delete_option( $option );
	}

	$message = 'removed from wp_options table.';

	$response = array(
		'message'			=> $message,
		'removedOptions'	=> $removable_options[$remove_options_from],
	);
	wp_send_json_success( $response );	
		
	wp_die(); // this is required to terminate immediately and return a proper response
}

endif; // class_exists

/**
 * Returns the main instance of the plugin to prevent the need to use globals.
 *
 * @since  1.0
 * @return WPO_WCPDF_Diagnostic_Tools
 */
function WPO_WCPDF_Diagnostic_Tools() {
	return WPO_WCPDF_Diagnostic_Tools::instance();
}

WPO_WCPDF_Diagnostic_Tools(); // load plugin
