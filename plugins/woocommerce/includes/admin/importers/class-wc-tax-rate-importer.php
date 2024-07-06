<?php
/**
 * Tax importer class file
 *
 * @version 2.3.0
 * @package WooCommerce\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Importer' ) ) {
	return;
}

/**
 * Tax Rates importer - import tax rates and local tax rates into WooCommerce.
 *
 * @package     WooCommerce\Admin\Importers
 * @version     2.3.0
 */
class WC_Tax_Rate_Importer extends WP_Importer {

	/**
	 * The current file id.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * The current file url.
	 *
	 * @var string
	 */
	public $file_url;

	/**
	 * The current import page.
	 *
	 * @var string
	 */
	public $import_page;

	/**
	 * The current delimiter.
	 *
	 * @var string
	 */
	public $delimiter;

	/**
	 * Error message for import.
	 *
	 * @var string
	 */
	public $import_error_message;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->import_page = 'woocommerce_tax_rate_csv';
		$this->delimiter   = empty( $_POST['delimiter'] ) ? ',' : (string) wc_clean( wp_unslash( $_POST['delimiter'] ) ); // WPCS: CSRF ok.
	}

	/**
	 * Registered callback function for the WordPress Importer.
	 *
	 * Manages the three separate stages of the CSV import process.
	 */
	public function dispatch() {

		$this->header(); //1

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step']; //2 && 3

		switch ( $step ) {

			case 0://4
				$this->greet();//5
				break;//6

			case 1://7
				check_admin_referer( 'import-upload' );//8

				if ( $this->handle_upload() ) {//9

					if ( $this->id ) {//10
						$file = get_attached_file( $this->id );//11
					} else {
						$file = ABSPATH . $this->file_url;//12
					}

					add_filter( 'http_request_timeout',
					 array( $this, 'bump_request_timeout' ) );//13

					$this->import( $file );//14
				} else {
					$this->import_error( $this->import_error_message );//15
				}
				break;//16
		}

		$this->footer();//17
	}

	/**
	 * Import is starting.
	 */
	private function import_start() {
		if ( function_exists( 'gc_enable' ) ) {
			gc_enable(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.gc_enableFound
		}
		wc_set_time_limit( 0 );
		@ob_flush();
		@flush();
	}

	/**
	 * UTF-8 encode the data if `$enc` value isn't UTF-8.
	 *
	 * @param mixed  $data Data.
	 * @param string $enc Encoding.
	 * @return string
	 */
	public function format_data_from_csv( $data, $enc ) {
		return ( 'UTF-8' === $enc ) ? $data : utf8_encode( $data );
	}

	/**
	 * Import the file if it exists and is valid.
	 *
	 * @param mixed $file File.
	 */
	public function import( $file ) {
		if ( ! is_file( $file ) ) { //1
			$this->import_error( __( 'The file does not exist, please try again.'
			, 'woocommerce' ) );//2
		}

		$this->import_start();//3

		$loop   = 0;//4
		$handle = fopen( $file, 'r' );//5

		if ( false !== $handle ) {//6

			$header = fgetcsv( $handle, 0, $this->delimiter );//7
			$count  = is_countable( $header ) ? count( $header ) : 0;//8 , 9 
			if ( 10 === $count ) {//10

				$row = fgetcsv( $handle, 0, $this->delimiter ); //11

				while ( false !== $row ) { //12

					list( $country, $state, $postcode, $city, $rate
					, $name, $priority, $compound, $shipping, $class ) = $row; //13

					$tax_rate = array(
						'tax_rate_country'  => $country,
						'tax_rate_state'    => $state,
						'tax_rate'          => $rate,
						'tax_rate_name'     => $name,
						'tax_rate_priority' => $priority,
						'tax_rate_compound' => $compound ? 1 : 0,
						'tax_rate_shipping' => $shipping ? 1 : 0,
						'tax_rate_order'    => $loop ++,
						'tax_rate_class'    => $class,
					);//14

					$tax_rate_id = WC_Tax::_insert_tax_rate( $tax_rate );//15
					WC_Tax::_update_tax_rate_postcodes( $tax_rate_id, wc_clean( $postcode ) );//16
					WC_Tax::_update_tax_rate_cities( $tax_rate_id, wc_clean( $city ) );//17

					$row = fgetcsv( $handle, 0, $this->delimiter );//18
				}
			} else {
				$this->import_error( __( 'The CSV is invalid.', 'woocommerce' ) );//19
			}

			fclose( $handle );//20
		}

		// Show Result.
		echo '<div class="updated settings-error"><p>';//21
		printf(
			/* translators: %s: tax rates count */
			esc_html__( 'Import complete - imported %s tax rates.', 'woocommerce' ),
			'<strong>' . absint( $loop ) . '</strong>'
		);//22
		echo '</p></div>';//23

		$this->import_end();//24
	}

	/**
	 * Performs post-import cleanup of files and the cache.
	 */
	public function import_end() {
		echo '<p>' . esc_html__( 'All done!', 'woocommerce' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax' ) ) . '">' . esc_html__( 'View tax rates', 'woocommerce' ) . '</a></p>';

		do_action( 'import_end' );
	}

	/**
	 * Set the import error message.
	 *
	 * @param string $message Error message.
	 */
	protected function set_import_error_message( $message ) {
		$this->import_error_message = $message;
	}

	/**
	 * Handles the CSV upload and initial parsing of the file to prepare for.
	 * displaying author import options.
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	public function handle_upload() {
		//1 : "isset" , 2:"wc_clean" , 3 :"''" 
		$file_url=isset($_POST['file_url'])?wc_clean(wp_unslash($_POST['file_url'])):'';

		if ( empty( $file_url ) ) {//4
			$file = wp_import_handle_upload();//5

			if ( isset( $file['error'] ) ) {//6
				$this->set_import_error_message( $file['error'] );//7

				return false;//8
			}

			if ( ! wc_is_file_valid_csv( $file['file'], false ) ) {//9
				// Remove file if not valid.
				wp_delete_attachment( $file['id'], true );//10

				$this->set_import_error_message( __
				( 'Invalid file type. The importer supports CSV and TXT file formats.'
				, 'woocommerce' ) );//11

				return false;//12
			}

			$this->id = absint( $file['id'] );//13
		} elseif (
			( 0 === stripos( realpath( ABSPATH . $file_url ), ABSPATH ) ) &&
			file_exists( ABSPATH . $file_url )//14:"0===stripos" && 15:"file_exists"
		) {
			if ( ! wc_is_file_valid_csv( ABSPATH . $file_url ) ) {//16
				$this->set_import_error_message( __
				( 'Invalid file type. The importer supports CSV and TXT file formats.'
				, 'woocommerce' ) );//17

				return false;//18
			}

			$this->file_url = esc_attr( $file_url );//19
		} else {
			return false;//20
		}

		return true;//21
	}

	/**
	 * Output header html.
	 */
	public function header() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import tax rates', 'woocommerce' ) . '</h1>';
	}

	/**
	 * Output footer html.
	 */
	public function footer() {
		echo '</div>';
	}

	/**
	 * Output information about the uploading process.
	 */
	public function greet() {
		//1
		echo '<div class="narrow">';

		//2
		echo '<p>' . esc_html__( 'Hi there! Upload a CSV file containing tax rates to import the contents into your shop. Choose a .csv file to upload, then click "Upload file and import".', 'woocommerce' ) . '</p>';

		//3
		echo '<p>' . sprintf( esc_html__( 'Your CSV needs to include columns in a specific order. %1$sClick here to download a sample%2$s.', 'woocommerce' ), '<a href="' . esc_url( WC()->plugin_url() ) . '/sample-data/sample_tax_rates.csv">', '</a>' ) . '</p>';
		//4
		$action = 'admin.php?import=woocommerce_tax_rate_csv&step=1';
		//5
		$bytes      = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		//6
		$size       = size_format( $bytes );
		//7
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) ://8
			?>
			<div class="error">
				<p><?php esc_html_e( 'Before you can upload your import file, you will need to fix the following error:', 'woocommerce' ); ?></p>
				<p><strong><?php echo esc_html( $upload_dir['error'] ); ?></strong></p>
			</div>//9
		<?php else : ?>//10
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( wp_nonce_url( $action, 'import-upload' ) ); ?>">
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php esc_html_e( 'Choose a file from your computer:', 'woocommerce' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="save" />
								<input type="hidden" name="max_file_size" value="<?php echo absint( $bytes ); ?>" />
								<small>
									<?php
									printf(
										/* translators: %s: maximum upload size */
										esc_html__( 'Maximum size: %s', 'woocommerce' ),
										esc_attr( $size )
									);
									?>
								</small>
							</td>
						</tr>
						<tr>
							<th>
								<label for="file_url"><?php esc_html_e( 'OR enter path to file:', 'woocommerce' ); ?></label>
							</th>
							<td>
								<?php echo ' ' . esc_html( ABSPATH ) . ' '; ?><input type="text" id="file_url" name="file_url" size="25" />
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Delimiter', 'woocommerce' ); ?></label><br/></th>
							<td><input type="text" name="delimiter" placeholder="," size="2" /></td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<button type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'woocommerce' ); ?>"><?php esc_html_e( 'Upload file and import', 'woocommerce' ); ?></button>
				</p>
			</form>
			<?php
		endif;

		echo '</div>';//11
	}

	/**
	 * Show import error and quit.
	 *
	 * @param  string $message Error message.
	 */
	private function import_error( $message = '' ) {
		echo '<p><strong>' . esc_html__( 'Sorry, there has been an error.', 'woocommerce' ) . '</strong><br />';
		if ( $message ) {
			echo esc_html( $message );
		}
		echo '</p>';
		$this->footer();
		die();
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import.
	 *
	 * @param  int $val Value.
	 * @return int 60
	 */
	public function bump_request_timeout( $val ) {
		return 60;
	}
}
