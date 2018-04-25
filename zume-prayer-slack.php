<?php
/*
* Plugin Name: Zume Prayer Slack
* Plugin URI: https://github.com/ChrisChasm/zume-prayer-slack
* Author: Chasm Solutions
* Author URI: https://chasm.solutions
* Description:
* Version: 1.1
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once('wp-async-request.php');

function zume_prayer_slack() {
    return Zume_Prayer_Slack::instance();
}
add_action( 'plugins_loaded', 'zume_prayer_slack' );

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

    public function content() {
        ?>
        Zume Prayer Slack
        <?php
    }

    public function hooks_user_register( $user_id ) {
        $user = get_user_by('id', $user_id );
        if ( ! $user ) {
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
                    'message'    => $user->user_nicename . ( ! empty( $address ) ? ', from ' . $address . ',' : '' ) . " just joined Zúme",
                    'channel'    => 'activity',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
        }
    }

    public function hooks_create_group( $user_id, $group_key, $new_group ) {
        $user = get_user_by('id', $user_id );
        $group = get_user_meta( $user_id, $group_key, true );
        $title = '';
        if ( ! $user ) {
            return;
        }
        if ( $group ) {
            $title = $group['group_name'] ?? '';
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $user->user_nicename . " created a new group" . ( ! empty( $title ) ? ' called '. $title .'' : ''),
                    'channel'    => 'activity',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
        }
    }

    public function hooks_update_three_month_plan( $user_id, $plan ) {
        $user = get_user_by('id', $user_id );
        if ( ! $user ) {
            return;
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $user->user_nicename . " is working on their 3 month plan.",
                    'channel'    => 'activity',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
        }
    }

    public function hooks_coleader_invitation_response( $user_id, $group_key, $decision ) {
        $user = get_user_by('id', $user_id );
        if ( ! $user ) {
            return;
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $user->user_nicename . " " . $decision . " an invitation to join a group.",
                    'channel'    => 'activity',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
        }
    }

    public function hooks_session_complete( $zume_group_key, $zume_session, $owner_id, $current_user_id ) {
        $user = get_user_by('id', $current_user_id );
        if ( ! $user ) {
            return;
        }
        try {
            $send_slack = new Zume_Prayer_Slack_Send();
            $send_slack->launch(
                [
                    'message'    => $user->user_nicename . " is leading a group through session " . $zume_session . " right now!",
                    'channel'    => 'activity',
                    'username'   => '',
                    'icon_emoji' => '',
                ]
            );
        } catch ( Exception $e ) {
            dt_write_log( '@' . __METHOD__ );
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
        }
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
            dt_write_log( 'Caught exception: ', $e->getMessage(), "\n" );
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
            $channel = $_POST[0]['channel'] ?? '';
            $username = $_POST[0]['username'] ?? '';
            $icon_emoji = $_POST[0]['icon_emoji'] ?? '';
            // @codingStandardsIgnoreEnd

            // Slack webhook endpoint from Slack settings
            $slack_endpoint = "https://hooks.slack.com/services/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";

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

            dt_write_log( $posting_to_slack );

        } // end if check
        return;
    }

    protected function run_action(){}
}


