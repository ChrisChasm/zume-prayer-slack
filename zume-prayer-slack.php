<?php
/*
* Plugin Name: Zume Prayer Slack
* Plugin URI: https://github.com/ChrisChasm/zume-prayer-slack
* Author: Chasm Solutions
* Author URI: https://chasm.solutions
* Description: A support plugins to send Slack notifications to Zume Prayer Slack for key site events.
* Version: 1.0
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once('wp-async-request.php');

function zume_prayer_slack() {
    $current_theme = get_option( 'current_theme' );
    if ( 'Zúme Project' == $current_theme ) {
        require_once( get_theme_file_path('/functions/geocoding-api.php' ) );

        return Zume_Prayer_Slack::instance();
    }
    else {
        add_action( 'admin_notices', 'zume_not_found' );
        return new WP_Error( 'current_theme_not_zume', 'Zume Project Theme not active.' );
    }
}
add_action( 'after_setup_theme', 'zume_prayer_slack' );

class Zume_Prayer_Slack
{
    private static $_instance = null;
    public $slack_send;

    /**
     * Zume_Prayer_Slack Instance
     * Ensures only one instance of Zume_Prayer_Slack is loaded or can be loaded.
     *
     * @since 0.1.0
     * @static
     * @return Zume_Prayer_Slack instance
     */
    public static function instance()
    {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    } // End instance()

    /**
     * Constructor function.
     *
     * @access  public
     * @since   0.1.0
     */
    public function __construct()
    {
        add_action( "admin_menu", [ $this, "register_menu" ] );

        // hooks
        add_action( 'user_register', [ &$this, 'hooks_user_register' ] );
        add_action( 'zume_create_group', [ &$this, 'hooks_create_group' ], 10, 3 );
        add_action( 'zume_coleader_invitation_response', [ &$this, 'hooks_coleader_invitation_response' ], 99, 3 );
        add_action( 'zume_update_three_month_plan', [ &$this, 'hooks_update_three_month_plan' ], 10, 2 );
        add_action( 'zume_session_complete', [ &$this, 'hooks_session_complete' ], 10, 4 );

    } // End __construct()

    public function register_menu() {
        add_menu_page( __( 'Zume Prayer Slack' ), __( 'Zume Prayer Slack' ), 'manage_options', 'zume-prayer-slack', [ $this, 'content' ], 'dashicons-admin-generic', 59 );
    }

    /**
     * Options page
     */
    public function content() {
        if ( isset( $_POST['zume_prayer_slack_nonce'] ) && ! empty( $_POST['zume_prayer_slack_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zume_prayer_slack_nonce'] ) ), 'zume_prayer_slack'. get_current_user_id() ) ) {
            dt_write_log( $_POST );

            $hook_url = trim( sanitize_text_field( wp_unslash( $_POST['hook_url'] ) ) );
            $channel = trim( sanitize_text_field( wp_unslash( $_POST['channel'] ) ) );

            update_option('zume_prayer_slack', $hook_url, true );
            update_option('zume_prayer_slack_channel', $channel, true );
        }

        $hook_url = get_option( 'zume_prayer_slack' );
        $channel = get_option( 'zume_prayer_slack_channel' );

        // begin columns template
        $this->template( 'begin' );
        // Build metabox
        $this->box( 'top', 'Slack Hook', [
            'col_span' => 2,
            'row_container' => false
        ] );
        ?>
        <h2>Zume Prayer Slack</h2>
        <br>
        <form method="post" action="">
            <?php wp_nonce_field( 'zume_prayer_slack'. get_current_user_id(), 'zume_prayer_slack_nonce', false, true ) ?>
            <tr>
                <td><label>Zume Hook URL</label></td>
                <td><input type="text" name="hook_url" value="<?php echo esc_attr( $hook_url ) ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <td><label>Channel</label></td>
                <td><input type="text" name="channel" value="<?php echo esc_attr( $channel ) ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <td colspan="2">
                    <button class="button" type="submit" style="float:right"><?php esc_html_e( 'Save' ) ?></button>
                </td>
            </tr>
        </form>

        <?php
        $this->box( 'bottom' );
        // end metabox
        // begin right column template
        $this->template( 'right_column' );
        // end columns template
        $this->template( 'end' );
    }

    public function hooks_user_register( $user_id ) {
        $initials = $this->create_alias( $user_id );
        if ( ! $initials ) {
            return;
        }
        $raw_ip_location = get_user_meta( $user_id, 'zume_raw_location_from_ip', true );
        if ( $raw_ip_location ) {
            if ( class_exists( 'Disciple_Tools_Google_Geocode_API') ) {
                $country = Disciple_Tools_Google_Geocode_API::parse_raw_result( $raw_ip_location, 'country' );
                $admin1 = Disciple_Tools_Google_Geocode_API::parse_raw_result( $raw_ip_location, 'administrative_area_level_1' );
                $address = $admin1 . ( ! empty( $admin1 ) ? ', ': '' ) . $country;
            } else {
                $address = '';
            }
        }

        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $initials . ( ! empty( $address ) ? ', from ' . $address . ',' : '' ) . " just joined Zúme",
                    'channel'    => '',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

    public function hooks_create_group( $user_id, $group_key, $new_group ) {
        $initials = $this->create_alias( $user_id );
        if ( ! $initials ) {
            return;
        }
        $group = get_user_meta( $user_id, $group_key, true );
        $title = '';
        if ( $group ) {
            // add title
            $title = $group['group_name'] ?? '';
            // add location
            $location = $this->get_group_location( $group['ip_raw_location'] ?? '' );
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $initials . " created a new group" . ( ! empty( $title ) ? ' called '. $title .'' : '') . ( ! empty( $location ) ? "\n (".$location.")" : ''),
                    'channel'    => '',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

    public function hooks_update_three_month_plan( $user_id, $plan ) {
        $initials = $this->create_alias( $user_id );
        if ( ! $initials ) {
            return;
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $initials . " is working on their 3 month plan.",
                    'channel'    => '',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

    public function hooks_coleader_invitation_response( $user_id, $group_key, $decision ) {
        $initials = $this->create_alias( $user_id );
        if ( ! $initials ) {
            return;
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $initials . " " . $decision . " an invitation to join a group.",
                    'channel'    => '',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

    public function hooks_session_complete( $zume_group_key, $zume_session, $owner_id, $current_user_id ) {
        $initials = $this->create_alias( $current_user_id );
        if ( ! $initials ) {
            return;
        }
        $group_members = '';
        $group = get_user_meta( $owner_id, $zume_group_key, true );
        if ( $group ) {
            $count = $group['members'] ?? 0;
            if ( ! $count ) {
                $group_members = ' of ' . $count;
            }
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $initials . " is leading a group". $group_members ." through session " . $zume_session . " right now!",
                    'channel'    => '',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

    public function get_user_location( ) {

    }

    public function get_group_location( $ip_raw_location ) {
        if ( empty( $ip_raw_location ) ) {
            return '';
        }

        $geocode = new Disciple_Tools_Google_Geocode_API();
        if ( $geocode::check_valid_request_result( $ip_raw_location ) ) {
            $country = $geocode::parse_raw_result( $ip_raw_location, 'country' );
            $admin1 = $geocode::parse_raw_result( $ip_raw_location, 'admin1' );

            return $admin1 . ( ! empty( $admin1 ) ? ', ' : '' ) . $country;
        }

        return '';
    }

    /**
     * Implementation of Template
     * The template is intended to reduce the HTML needed for repeatable WP admin page framework
     *
     * Two Column Implementation
    $this->template( 'begin' );
    $this->template( 'right_column' );
    $this->template( 'end' );
     *
     * One Column Implementation
    $this->template( 'begin' );
    $this->template( 'end' );
     *
     * @param     $section
     * @param int $columns
     */
    public function template( $section, $columns = 2 ) {
        switch ( $columns ) {

            case '1':
                switch ( $section ) {
                    case 'begin':
                        ?>
                        <div class="wrap">
                        <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-1">
                        <div id="post-body-content">
                        <!-- Main Column -->
                        <?php
                        break;


                    case 'end':
                        ?>
                        </div><!-- postbox-container 1 -->
                        </div><!-- post-body meta box container -->
                        </div><!--poststuff end -->
                        </div><!-- wrap end -->
                        <?php
                        break;
                }
                break; // end case 1

            case '2':
                switch ( $section ) {
                    case 'begin':
                        ?>
                        <div class="wrap">
                        <div id="poststuff">
                        <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                        <!-- Main Column -->
                        <?php
                        break;

                case 'right_column':
                    ?>
                    <!-- End Main Column -->
                    </div><!-- end post-body-content -->
                    <div id="postbox-container-1" class="postbox-container">
                    <!-- Right Column -->
                    <?php
                    break;

                    case 'end':
                        ?>
                        </div><!-- postbox-container 1 -->
                        </div><!-- post-body meta box container -->
                        </div><!--poststuff end -->
                        </div><!-- wrap end -->
                        <?php
                        break;
                }
                break; // end case 2
        }
    }

    /**
     * @param        $section
     * @param string $title
     * @param array  $args
     *                    row_container removes the default containing row
     *                    col_span sets the number of columns the header should span
     *                    striped can remove the striped class from the table
     */
    public function box( $section, $title = '', $args = [] ) {

        $args = wp_parse_args( $args, [
            'row_container' => true,
            'col_span' => 1,
            'striped' => true,
        ] );

        switch ( $section ) {
            case 'top':
                ?>
                <!-- Begin Box -->
                <table class="widefat <?php echo $args['striped'] ? 'striped' : '' ?>">
                <thead><th colspan="<?php echo esc_attr( $args['col_span'] ) ?>"><?php echo esc_html( $title ) ?></th></thead>
                <tbody>

                <?php
                echo $args['row_container'] ? '<tr><td>' : '';

                break;
            case 'bottom':

                echo $args['row_container'] ? '</tr></td>' : '';
                ?>
                </tbody></table><br>
                <!-- End Box -->
                <?php
                break;
        }
    }

    public function create_alias( $user_id ) {
        $user = get_user_by('id', $user_id );
        if ( ! $user->exists() ) {
            return 'User-' . $user_id;
        }

        $first_name = $user->__get('first_name' );
        $last_name = $user->__get('last_name' );
        if ( ! empty( $first_name ) ||  ! empty( $last_name ) ) {
            $first_initial = empty( $first_name ) ? '' : substr( strtoupper( $first_name ), 0, 1 );
            $last_initial = empty( $last_name ) ? '' : substr( strtoupper( $last_name ), 0, 1 );

            $initials = $first_initial.$last_initial;
            return $initials; // returns first and last initials
        }

        $login_initials = substr( strtoupper( $user->user_login ), 0, 2 );
        return $login_initials; // returns first two letters of login name
    }
}

/**
 * Function checker for async post requests
 * This runs on every page load looking for an async post request
 */
function zume_prayer_slack_async_send()
{
    // check for create new contact
    if ( isset( $_POST['_wp_nonce'] )
        && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wp_nonce'] ) ) )
        && isset( $_POST['action'] )
        && sanitize_key( wp_unslash( $_POST['action'] ) ) == 'dt_async_prayer_slack' ) {
        try {
            $send_to_slack = new Zume_Prayer_Slack_Send();
            $send_to_slack->send();
        } catch ( Exception $e ) {
            dt_write_log( 'Caught exception: '. $e->getMessage() . "\n" );
        }
    }

}
add_action( 'init', 'zume_prayer_slack_async_send' );


/**
 * Class Disciple_Tools_Insert_Location
 */
class Zume_Prayer_Slack_Send extends Disciple_Tools_Async_Task
{
    protected $action = 'prayer_slack';

    protected function prepare_data( $data ) { return $data; }

    public function send()
    {
        // @codingStandardsIgnoreStart
        if( isset( $_POST[ 'action' ] )
            && sanitize_key( wp_unslash( $_POST[ 'action' ] ) ) == 'dt_async_'.$this->action
            && isset( $_POST[ '_nonce' ] )
            && $this->verify_async_nonce( sanitize_key( wp_unslash( $_POST[ '_nonce' ] ) ) ) ) {

            $message = $_POST[0]['message'] ?? '';
            $channel = get_option( 'zume_prayer_slack_channel' ); // $channel = $_POST[0]['channel'] ?? '';
            $username = $_POST[0]['username'] ?? '';
            $icon_emoji = $_POST[0]['icon_emoji'] ?? '';
            // @codingStandardsIgnoreEnd

            // Slack webhook endpoint from Slack settings
            $slack_endpoint = get_option( 'zume_prayer_slack' );
            if ( empty( $slack_endpoint ) ) {
                dt_write_log( 'Missing slack endpoint. Can not send zume slack notification.');
                return;
            }

            // Prepare the data / payload to be posted to Slack
            $data = array(
                'payload'   => json_encode( array(
                        "channel"       =>  $channel,
                        "text"          =>  $message,
                        "username"	    =>  $username,
                        "icon_emoji"    =>  $icon_emoji
                    )
                )
            );
            // Post our data via the slack webhook endpoint using wp_remote_post
            $posting_to_slack = wp_remote_post( $slack_endpoint, array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'body' => $data,
                    'cookies' => array()
                )
            );

//            dt_write_log( $posting_to_slack );

        } // end if check
        return;
    }

    protected function run_action(){}
}

/**
 * A simple function to assist with development and non-disruptive debugging.
 * -----------
 * -----------
 * REQUIREMENT:
 * WP Debug logging must be set to true in the wp-config.php file.
 * Add these definitions above the "That's all, stop editing! Happy blogging." line in wp-config.php
 * -----------
 * define( 'WP_DEBUG', true ); // Enable WP_DEBUG mode
 * define( 'WP_DEBUG_LOG', true ); // Enable Debug logging to the /wp-content/debug.log file
 * define( 'WP_DEBUG_DISPLAY', false ); // Disable display of errors and warnings
 * @ini_set( 'display_errors', 0 );
 * -----------
 * -----------
 * EXAMPLE USAGE:
 * (string)
 * write_log('THIS IS THE START OF MY CUSTOM DEBUG');
 * -----------
 * (array)
 * $an_array_of_things = ['an', 'array', 'of', 'things'];
 * write_log($an_array_of_things);
 * -----------
 * (object)
 * $an_object = new An_Object
 * write_log($an_object);
 */
if ( ! function_exists( 'dt_write_log' ) ) {
    /**
     * A function to assist development only.
     * This function allows you to post a string, array, or object to the WP_DEBUG log.
     *
     * @param $log
     */
    // @codingStandardsIgnoreLine
    function dt_write_log( $log )
    {
        if ( true === WP_DEBUG ) {
            if ( is_array( $log ) || is_object( $log ) ) {
                error_log( print_r( $log, true ) );
            } else {
                error_log( $log );
            }
        }
    }
}

function zume_not_found()
{
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( "'Zume Prayer Slack' plugin requires 'Zume Project' theme to work. Please activate 'Zume Project' theme or deactivate 'Zume Prayer Slack' plugin." ); ?></p>
    </div>
    <?php
}


