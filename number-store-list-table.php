<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// Load WP_List_Table if not loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WPO_WCPDF_Number_Tools_List_Table Class
 *
 * Renders the Number store table
 *
 * @since 2.0
 */
class WPO_WCPDF_Number_Tools_List_Table extends \WP_List_Table {
	/**
	 * Number of items per page
	 *
	 * @var int
	 * @since 2.0
	 */
	public $per_page = 50;

	/**
	 * The arguments for the data set
	 *
	 * @var array
	 * @since 2.0
	 */
	public $args = array();

	/**
	 * Get things started
	 *
	 * @since 2.0
	 * @see WP_List_Table::__construct()
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'number',
			'plural'   => 'numbers',
			'ajax'     => false
		) );

		$this->process_bulk_action();
	}

	/**
	 * Show the search field
	 *
	 * @since 2.0
	 *
	 * @param string $text Label for the search box
	 * @param string $input_id ID of the search box
	 *
	 * @return void
	 */
	public function search_box( $text, $input_id ) {
		$input_id = $input_id . '-search-input';
		if ( ! empty( $_REQUEST['orderby'] ) )
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $_REQUEST['orderby'] ) . '" />';
		if ( ! empty( $_REQUEST['order'] ) )
			echo '<input type="hidden" name="order" value="' . esc_attr( $_REQUEST['order'] ) . '" />';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo $input_id ?>"><?php echo $text; ?>:</label>
			<input type="search" id="<?php echo $input_id ?>" name="s" value="<?php _admin_search_query(); ?>" />
			<?php submit_button( $text, 'button', false, false, array('ID' => 'search-submit') ); ?>
		</p>
		<?php
	}

	/**
	 * This function renders most of the columns in the list table.
	 *
	 * @since 2.0
	 *
	 * @param array $item Contains all the data of the numbers
	 * @param string $column_name The name of the column
	 *
	 * @return string Column Name
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {

			case 'number' :
				$value = $item->id;
				break;
			case 'calculated_number' :
				$value = isset($result->calculated_number) ? $item->calculated_number : '-';
				break;
			case 'date' :
				$value = $item->date;
				break;	
			case 'order' :
				if ( !empty( $item->order_id ) ) {
					$url = sprintf('post.php?post=%s&action=edit', $item->order_id);
					$value = sprintf('<a href="%s">#%s</a>', $url, $item->order_id);
				} else {
					$value = '-';
				}
				break;
			case 'order_status' :
				$order = wc_get_order( $item->order_id );
				if (!empty($order)) {
					$order_status = $order->get_status();
					$value = sprintf( '<mark class="order-status %s"><span>%s</span></mark>', esc_attr( sanitize_html_class( 'status-' . $order->get_status() ) ), esc_html( wc_get_order_status_name( $order->get_status() ) ) );
				} else {
					$value = "<strong>unknown</strong>";
				}
				break;
			default:
				$value = isset( $item->$column_name )
					? $item->$column_name
					: null;
				break;
		}
		if ( empty( $value ) ) {
			$value = '-';
		}
		return apply_filters( 'wpo_wcpdf_number_tools_column_content_' . $column_name, $value, $item );
	}

	/**
	 * Retrieve the view types
	 *
	 * @access public
	 * @since 2.0
	 *
	 * @return array $views All the views available
	 */
	public function get_views() {
		$base          = $this->get_base_url();
		$current       = isset( $_GET['number_store'] ) ? sanitize_key( $_GET['number_store'] ) : 'invoice_number';

		return array(
			'invoice_number' => sprintf( '<a href="%s"%s>%s</a>',
				esc_url( remove_query_arg( 'number_store', $base ) ),
				$current == 'invoice_number' ? ' class="current"' : '',
				'Invoice'
			),
		);
	}

	/**
	 * Retrieve the table columns
	 *
	 * @since 2.0
	 * @return array $columns Array of all the list table columns
	 */
	public function get_columns() {
		return apply_filters( 'wpo_wcpdf_number_tools_columns', array(
			'number'            => __( 'Number',           'woocommerce-pdf-ips-number-tools' ),
			'calculated_number' => __( 'Calculated',       'woocommerce-pdf-ips-number-tools' ),
			'date'              => __( 'Date',             'woocommerce-pdf-ips-number-tools' ),
			'order'             => __( 'Order',            'woocommerce-pdf-ips-number-tools' ),
			'order_status'      => __( 'Order Status',           'woocommerce-pdf-ips-number-tools' ),
		) );
	}

	/**
	 * Get the sortable columns
	 *
	 * @since 2.0
	 * @return array Array of all the sortable columns
	 */
	public function get_sortable_columns() {
		return array(
			'number'     => array( 'id', true ),
		);
	}

	/**
	 * Retrieve the bulk actions
	 *
	 * @access public
	 * @since 2.0
	 * @return array Array of the bulk actions
	 */
	public function get_bulk_actions() {
		return array();
	}

	/**
	 * Retrieve the current page number
	 *
	 * @since 2.0
	 * @return int Current page number
	 */
	public function get_paged() {
		return isset( $_GET['paged'] )
			? absint( $_GET['paged'] )
			: 1;
	}

	/**
	 * Retrieves the search query string
	 *
	 * @since 2.0
	 * @return mixed string If search is present, false otherwise
	 */
	public function get_search() {
		return ! empty( $_GET['s'] )
			? urldecode( trim( $_GET['s'] ) )
			: false;
	}

	/**
	 * Build all the number data
	 *
	 * @since 2.0
	  * @global object $wpdb Used to query the database using the WordPress
	 *   Database API
	 * @return array $numbers All the data for number list table
	 */
	public function get_numbers() {
		global $wpdb;

		$results      = array();
		$paged        = $this->get_paged();
		$offset       = $this->per_page * ( $paged - 1 );
		$search       = $this->get_search();
		$table_name   = isset( $_GET['table_name']  ) ? sanitize_text_field( $_GET['table_name']  ) : null;
		$order        = isset( $_GET['order']   ) ? sanitize_text_field( $_GET['order']   ) : 'DESC';
		$orderby      = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'id';

		// $args    = array(
		// 	'number'  => $this->per_page,
		// 	'offset'  => $offset,
		// 	'order'   => $order,
		// 	'orderby' => $orderby,
		// 	'status'  => $status
		// );

		if( ! empty( $table_name ) ) {
			if ( $search ) {
				$results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE `id` LIKE $search OR `order_id` LIKE $search ORDER BY $orderby $order LIMIT %d OFFSET %d", $this->per_page, $offset));
			} else {
				$results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY $orderby $order LIMIT %d OFFSET %d", $this->per_page, $offset));
			}
		} else {
			$results = 0;
		}

		return $results;
	}

	/**
	 * Setup the final data for the table
	 *
	 * @since 2.0
	 * @uses self::get_columns()
	 * @uses WP_List_Table::get_sortable_columns()
	 * @uses self::get_pagenum()
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns()
		);

		$table_name = isset( $_GET['table_name'] )
			? sanitize_key( $_GET['table_name'] )
			: null;

		if( ! empty( $table_name ) ) {

			$this->items = $this->get_numbers();
			if ( $search = $this->get_search() ) {
				$total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}  WHERE `id` LIKE $search OR `order_id` LIKE $search");
			} else {
				$total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
			}
		} else {
			$total_items = 0;
		}

		// Setup pagination
		$this->set_pagination_args( array(
			'total_pages' => ceil( $total_items / $this->per_page ),
			'total_items' => $total_items,
			'per_page'    => $this->per_page
		) );
	}
}