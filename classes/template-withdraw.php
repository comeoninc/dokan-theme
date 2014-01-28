<?php

/**
 * Dokan Withdraw class
 *
 * @author weDevs
 */
class Dokan_Template_Withdraw {

    /**
     * Initializes the Bed_IQ() class
     *
     * Checks for an existing Bed_IQ() instance
     * and if it doesn't find one, creates it.
     */
    public static function init() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new Dokan_Template_Withdraw();
        }

        return $instance;
    }

    function bulk_action_handler() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! isset( $_POST['dokan_withdraw_bulk'] ) ) {
            return;
        }

        $bulk_action = $_POST['dokan_withdraw_bulk'];

        if( ! isset( $_POST['id'] )  ) {
            return;
        }

        //if id empty then empty value return
        if( ! is_array( $_POST['id'] ) && ! count( $_POST['id'] ) ) {
            return;
        }

        $withdraw_ids = implode( "','", $_POST['id'] );
        $status = $_POST['status_page'];

        switch ($bulk_action) {
            case 'paypal':
                $this->generate_csv( $withdraw_ids );
                break;

            case 'delete':

                foreach ($_POST['id'] as $withdraw_id) {
                    $this->delete_withdraw( $withdraw_id );
                }

                wp_redirect( admin_url( 'admin.php?page=dokan-withdraw&message=trashed&status=' . $status ) );
                die();

                break;

            case 'cancel':

                foreach ($_POST['id'] as $key => $withdraw_id) {
                    $this->update_status( $withdraw_id, $_POST['user_id'][$key], 2 );
                }

                wp_redirect( admin_url( 'admin.php?page=dokan-withdraw&message=cancelled&status=' . $status ) );
                die();

                break;

            case 'approve':

                foreach ($_POST['id'] as $key => $withdraw_id) {
                    $this->update_status( $withdraw_id, $_POST['user_id'][$key], 1 );
                }

                wp_redirect( admin_url( 'admin.php?page=dokan-withdraw&message=approved&status=' . $status ) );

                break;

            case 'pending':

                foreach ($_POST['id'] as $key => $withdraw_id) {
                    $this->update_status( $withdraw_id, $_POST['user_id'][$key], 0 );
                }

                wp_redirect( admin_url( 'admin.php?page=dokan-withdraw&message=pending&status=' . $status ) );

                break;
        }


    }

    function generate_csv( $withdraw_ids ) {
        global $wpdb;

        $result = $wpdb->get_results(
            "SELECT * FROM {$wpdb->dokan_withdraw}
            WHERE id in('$withdraw_ids')"
        );

        if ( ! $result ) {
            return;
        }

        foreach( $result as $key => $obj ) {

            $data[] = array(
                'email' => get_user_by( 'id', $obj->user_id )->user_email,
                'amount' => $obj->amount,
                'currency' => get_option('woocommerce_currency') ,
            );

        }

        header('Content-type: html/csv');
        header('Content-Disposition: attachment; filename="withdraw-'.date('d-m-y').'.csv"');

        foreach ($data as $fields) {
            echo $fields['email']. ',';
            echo $fields['amount']. ',';
            echo $fields['currency'] . "\n";
        }
        die();
    }


    function cancel_pending() {

        if ( isset( $_GET['action'] ) && $_GET['action'] == 'dokan_cancel_withdrow' ) {

            if ( !wp_verify_nonce( $_GET['_wpnonce'], 'dokan_cancel_withdrow' ) ) {
                wp_die( __( 'Are you cheating?', 'dokan' ) );
            }

            global $current_user, $wpdb;

            $row_id = absint( $_GET['id'] );

            $this->update_status( $row_id, $current_user->ID, 2 );

            wp_redirect( add_query_arg( array( 'message' => 'request_cancelled' ), get_permalink() ) );
        }
    }

    function validate() {

        if ( !isset( $_POST['withdraw_submit'] ) ) {
            return false;
        }

        if( !wp_verify_nonce( $_POST['dokan_withdraw_nonce'], 'dokan_withdraw' ) ) {
            wp_die( __( 'Are you cheating?', 'dokan' ) );
        }

        $error = new WP_Error();

        if ( empty($_POST['witdraw_amount']) ) {
            $error->add('dokan_empty_withdrad', __('Withdraw amount required ', 'dokan' ));
        } else  {
            if( $_POST['witdraw_amount'] <= 49 ) {
                $error->add('dokan_withdraw_amount', __('Withdraw amount must be greater than 49', 'dokan' ));
            }
        }

        if( empty($_POST['withdraw_method']) ) {
            $error->add('dokan_withdraw_method', __('withdraw method required', 'dokan' ));
        }

        if ( $error->get_error_codes() ) {
            return $error;
        }

        return true;
    }

    function update_status( $row_id, $user_id, $status ) {
        global $wpdb;

        // 0 -> pending
        // 1 -> active
        // 2 -> cancelled

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->dokan_withdraw}
            SET status = %d WHERE user_id=%d AND id = %d",
            $status, $user_id, $row_id
        ) );
    }

    function insert_withdraw( $data = array() ) {
        global $wpdb;
        $wpdb->dokan_withdraw = $wpdb->prefix . 'dokan_withdraw';
        $data = array(
            'user_id' => $data['user_id'],
            'amount' => $data['amount'],
            'date' => current_time( 'mysql' ),
            'status' => $data['status'],
            'method' => $data['method'],
            'note' => $data['notes'],
            'ip' => $data['ip']
        );

        $format = array('%d', '%f', '%s', '%d', '%s', '%s', '%s');

        return $wpdb->insert( $wpdb->dokan_withdraw, $data, $format );
    }

    function insert_withdraw_info() {

        global $current_user, $wpdb;

        $data_info = array(
            'user_id' => $current_user->ID,
            'amount' => filter_var( $_POST['witdraw_amount'], FILTER_SANITIZE_NUMBER_FLOAT ),
            'status' => 0,
            'method' => $_POST['withdraw_method'],
            'ip' => dokan_get_client_ip(),
            'notes' => ''
        );

        $update = $this->insert_withdraw( $data_info );

        wp_redirect( add_query_arg( array( 'message' => 'request_success' ), get_permalink() ) );
    }

    function has_pending_request( $user_id ) {
        global $wpdb;

        $wpdb->dokan_withdraw = $wpdb->prefix . 'dokan_withdraw';

        $status = $wpdb->get_results( $wpdb->prepare(
            "SELECT id
             FROM $wpdb->dokan_withdraw
             WHERE user_id = %d AND status = 0", $user_id
        ) );

        if ( $status ) {
            return true;
        }

        return false;
    }

    function get_withdraw_requests( $user_id = '', $status = 0, $limit = 10, $offset = 0) {
        global $wpdb;

        $where = empty( $user_id ) ? '' : sprintf( "user_id ='%d' &&", $user_id );

        $sql = $wpdb->prepare( "SELECT * FROM {$wpdb->dokan_withdraw} WHERE $where status = %d LIMIT %d, %d", $status, $offset, $limit );
        $result = $wpdb->get_results( $sql );

        return $result;
    }

    function delete_withdraw( $id ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->dokan_withdraw} WHERE id = %d", $id ) );
    }

    function get_status_code( $status ) {
        switch ($status) {
            case 'pending':
                return 0;
                break;

            case 'completed':
                return 1;
                break;

            case 'cancelled':
                return 2;
                break;
        }
    }

    function admin_withdraw_list( $status ) {
        $pagenum = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $limit = 5;
        $offset = ( $pagenum - 1 ) * $limit;
        $result = $this->get_withdraw_requests( '', $this->get_status_code( $status ), $limit, $offset );
        ?>

        <?php if ( isset( $_GET['message'] ) ) {
            if ( $_GET['message'] == 'trashed' ) {
                ?>
                <div class="updated">
                    <p><strong><?php _e( 'Requests deleted!', 'dokan' ); ?></strong></p>
                </div>
                <?php
            }

            if ( $_GET['message'] == 'cancelled' ) {
                ?>
                <div class="updated">
                    <p><strong><?php _e( 'Requests cancelled!', 'dokan' ); ?></strong></p>
                </div>
                <?php
            }

            if ( $_GET['message'] == 'approved' ) {
                ?>
                <div class="updated">
                    <p><strong><?php _e( 'Requests approved!', 'dokan' ); ?></strong></p>
                </div>
                <?php
            }
        } ?>
        <form method="post" action="">
            <table class="widefat withdraw-table">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" class="dokan-withdraw-allcheck">
                        </th>
                        <th><?php _e( 'User Name', 'dokan' ); ?></th>
                        <th><?php _e( 'User Email', 'dokan' ); ?></th>
                        <th><?php _e( 'Amount', 'dokan' ); ?></th>
                        <th><?php _e( 'Method', 'dokan' ); ?></th>
                        <th><?php _e( 'Note', 'dokan' ); ?></th>
                        <th><?php _e( 'IP', 'dokan' ); ?></th>
                        <th><?php _e( 'Date', 'dokan' ); ?></th>
                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" class="dokan-withdraw-allcheck">
                        </th>
                        <th><?php _e( 'User Name', 'dokan' ); ?></th>
                        <th><?php _e( 'User Email', 'dokan' ); ?></th>
                        <th><?php _e( 'Amount', 'dokan' ); ?></th>
                        <th><?php _e( 'Method', 'dokan' ); ?></th>
                        <th><?php _e( 'Note', 'dokan' ); ?></th>
                        <th><?php _e( 'IP', 'dokan' ); ?></th>
                        <th><?php _e( 'Date', 'dokan' ); ?></th>
                    </tr>
                </tfoot>

            <?php
            if ( $result ) {
                $count = 0;
                foreach( $result as $key => $result_array ) {
                    $user_data = get_userdata($result_array->user_id);
                    ?>
                    <tr class="<?php echo ($count % 2) == 0 ? 'alternate': 'odd'; ?>">
                        <th class="check-column">
                            <input type="checkbox" name="id[]" value="<?php echo $result_array->id;?>">
                            <input type="hidden" name="user_id[]" value="<?php echo $result_array->user_id; ?>">
                        </th>
                        <th>
                            <strong><a href="<?php echo admin_url( 'user-edit.php?user_id=' . $user_data->ID ); ?>"><?php echo $user_data->user_login; ?></a></strong>
                        </th>
                        <th><?php echo $user_data->user_email; ?></th>
                        <th><?php echo wc_price( $result_array->amount ); ?></th>
                        <th>
                            <?php if ( $result_array->method == 'bank' ) {
                                echo __( 'Bank Transfer', 'dokan' );
                            } elseif ( $result_array->method == 'paypal' ) {
                                echo __( 'PayPal', 'dokan' );
                            } elseif ( $result_array->method == 'skrill' ) {
                                echo __( 'Skrill', 'dokan' );
                            } ?>
                        </th>
                        <th >
                            <div class="dokan-add-note" style="width: 130px;">
                                <p class="ajax_note"><?php echo $result_array->note; ?></p>
                                <input type="text" class="dokan-note-text" style="display: none;" name="note">
                                <a class="dokan-note-submit btn btn-info" style="display: none;" data-admin_url="<?php echo admin_url( 'admin-ajax.php' ); ?>" data-row_id=<?php echo $result_array->id; ?> data-user_id=<?php echo $result_array->user_id; ?> href="#" ><?php _e('Note', 'dokan' ); ?></a>
                                <a href="#" style="display: none; margin-left: 72px;" class="dokan-note-cancle button"><?php _e('X', 'dokan' ); ?></a>
                                <a href="#" class="dokan-note-field"><?php _e('Add note', 'dokan' ); ?></a>
                            </div>

                        </th>
                        <th><?php echo $result_array->ip; ?></th>
                        <th><?php echo date_i18n( 'M j, Y g:ia', strtotime( $result_array->date ) ); ?></th>
                    </tr>
                    <?php
                    $count++;
                }

            } else {
                ?>
                <tr>
                    <td colspan="8">
                        <?php _e( 'No result found', 'dokan' ) ?>
                    </td>
                </tr>
                <?php
            }
            ?>
            </table>

            <div class="tablenav bottom">

                <div class="alignleft actions bulkactions">
                    <select name="dokan_withdraw_bulk">
                        <option value="-1" selected="selected"><?php _e( 'Bulk Actions', 'dokan' ); ?></option>

                        <?php if ( $status == 'pending' ) { ?>

                            <option value="approve"><?php _e( 'Approve Requests', 'dokan' ); ?></option>
                            <option value="cancel"><?php _e( 'Mark as Cancelled', 'dokan' ); ?></option>

                        <?php } elseif ( $status == 'completed' ) { ?>

                            <option value="cancel"><?php _e( 'Mark as Cancelled', 'dokan' ); ?></option>
                            <option value="pending"><?php _e( 'Mark Pending', 'dokan' ); ?></option>

                        <?php } elseif ( $status == 'cancelled' ) { ?>

                            <option value="approve"><?php _e( 'Approve Requests', 'dokan' ); ?></option>
                            <option value="pending"><?php _e( 'Mark Pending', 'dokan' ); ?></option>

                        <?php } ?>

                        <?php if ( $result ) { ?>
                            <option value="delete"><?php _e( 'Delete', 'dokan' ); ?></option>
                            <option value="paypal"><?php _e( 'Download PayPal mass payment file', 'dokan' ); ?></option>
                        <?php } ?>
                    </select>

                    <input type="hidden" name="status_page" value="<?php echo $status; ?>">
                    <input type="submit" name="" id="doaction2" class="button button-primary" value="<?php esc_attr_e( 'Apply', 'dokan' ); ?>">
                </div>

                <?php if ( $result ) {
                    $counts = dokan_get_withdraw_count();
                    $num_of_pages = ceil( $counts[$status] / $limit );
                    $page_links = paginate_links( array(
                        'base' => add_query_arg( 'paged', '%#%' ),
                        'format' => '',
                        'prev_text' => __( '&laquo;', 'aag' ),
                        'next_text' => __( '&raquo;', 'aag' ),
                        'total' => $num_of_pages,
                        'current' => $pagenum
                    ) );

                    if ( $page_links ) {
                        echo '<div class="tablenav-pages">' . $page_links . '</div>';
                    }
                } ?>
            </div>

        </form>

        <style type="text/css">
            .withdraw-table {
                margin-top: 10px;
            }

            .withdraw-table td, .withdraw-table th {
                vertical-align: top;
            }
        </style>
        <?php

        $this->add_note_script();
    }

    function add_note_script() {
        ?>
        <script type="text/javascript">

        jQuery(function($) {
            var dokan_admin = {
                init: function() {
                    $('div.dokan-add-note').on('click', 'a.dokan-note-field', this.addnote);
                    $('div.dokan-add-note').on('click', 'a.dokan-note-cancle', this.addnoteCancle);
                    $('div.dokan-add-note').on('click', 'a.dokan-note-submit', this.noteUpdate);
                },

                noteUpdate: function(e) {
                    e.preventDefault();

                    var self = $(this),
                    row_id = self.data('row_id'),
                    note = self.siblings('input.dokan-note-text').val(),
                    ajaxurl = self.data('admin_url');
                    data = {
                        'action': 'note',
                        'row_id': row_id,
                        'note': note,
                    };

                    $.post( ajaxurl, data, function(resp) {
                        if(resp.success) {

                            self.siblings('p.ajax_note').text(resp.data['note']);
                            self.hide();
                            self.siblings('input.dokan-note-text').hide();
                            self.siblings('a.dokan-note-cancle').hide();
                            self.siblings('a.dokan-note-field').show();
                        }
                    });
                },

                addnoteCancle: function(e) {
                    e.preventDefault();
                    var self = $(this);
                    self.hide();

                    self.siblings( "a.dokan-note-submit" ).hide();
                    self.siblings('input.dokan-note-text').hide();
                    self.siblings('a.dokan-note-field').show();

                },

                addnote: function(e) {
                    e.preventDefault();
                    var self = $(this);

                    self.hide();
                    self.siblings( "a.dokan-note-submit" ).show();
                    self.siblings('input.dokan-note-text').show();
                    self.siblings('a.dokan-note-cancle').show();

                }
            }
            dokan_admin.init();
        })
        </script>

        <?php
    }

    function note_update() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dokan_withdraw';
        $update = $wpdb->update( $table_name, array('note' => sanitize_text_field( $_POST['note'] ) ), array( 'id' => $_POST['row_id'] ) );
        if( $update ) {
            $html = array(
                'note' => $_POST['note'],
            );
            wp_send_json_success( $html);
        } else {
            wp_send_json_error();
        }

    }

    function has_withdraw_balance( $user_id ) {

        $balance = $this->get_user_balance( $user_id );
        $withdraw_limit = $this->get_withdraw_limit();

        if ( $balance < $withdraw_limit ) {
            return false;
        }

        return true;
    }

    function get_withdraw_limit() {
        return dokan_get_option( 'withdraw_limit', 'dokan_selling', '50' );
    }

    function get_user_balance( $user_id ) {
        return 99;
    }

    function request_status( $status ) {
        switch ($status) {
            case 0:
                return '<span class="label label-danger">' . __( 'Pending Reivew', 'dokan' ) . '</span>';
                break;

            case 1:
                return '<span class="label label-warning">' . __( 'Accepted', 'dokan' ) . '</span>';
                break;
        }
    }

    function withdraw_requests( $user_id ) {
        $withdraw_requests = $this->get_withdraw_requests( $user_id );

        if ( $withdraw_requests ) {
            ?>
            <table class="table table-striped">
                <tr>
                    <th><?php _e( 'Amount', 'dokan' ); ?></th>
                    <th><?php _e( 'Method', 'dokan' ); ?></th>
                    <th><?php _e( 'Date', 'dokan' ); ?></th>
                    <th><?php _e( 'Cancel', 'dokan' ); ?></th>
                    <th><?php _e( 'Status', 'dokan' ); ?></th>
                </tr>

                <?php foreach ($withdraw_requests as $request) { ?>

                    <tr>
                        <td><?php echo wc_price( $request->amount ); ?></td>
                        <td><?php echo $request->method; ?></td>
                        <td><?php echo dokan_format_time( $request->date ); ?></td>
                        <td>
                            <?php
                            $url = add_query_arg( array(
                                'action' => 'dokan_cancel_withdrow',
                                'id' => $request->id
                            ), get_permalink() );
                            ?>
                            <a href="<?php echo wp_nonce_url( $url, 'dokan_cancel_withdrow' ); ?>">
                                <?php _e( 'Cancel', 'dokan' ); ?>
                            </a>
                        </td>
                        <td><?php echo $this->request_status( $request->status ); ?></td>
                    </tr>

                <?php } ?>

            </table>
            <?php
        }
    }

    function get_payment_methods() {
        $method = array(
            '' => __( '- Select Method -', 'dokan' ),
            'paypal' => __( 'Paypal', 'dokan' ),
            'bank' => __( 'Bank Transfer', 'dokan' ),
        );

        $payment_methods = apply_filters( 'payment_withdraw_option', $method );

        return $payment_methods;
    }

    function show_alert_messages() {
        $type = isset( $_GET['message'] ) ? $_GET['message'] : '';

        switch ($type) {
            case 'request_cancelled':
                ?>
                <div class="alert alert-success">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <strong><p><?php _e( 'Your request has been cancelled successfully!', 'dokan' ); ?></p></strong>
                </div>
                <?php
                break;

            case 'request_success':
                ?>
                <div class="alert alert-success">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <strong><?php _e( 'Your request has been received successfully and is under review!', 'dokan' ); ?></strong>
                </div>
                <?php
                break;
            case 'request_error':
                ?>
                <div class="alert alert-danger">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <strong><?php _e( 'Unknown error!', 'dokan' ); ?></strong>
                </div>
                <?php
                break;
        }
    }

    function withdraw_form($validate='') {
        global $current_user;

        // show alert messages
        $this->show_alert_messages();

        if ( $this->has_pending_request( $current_user->ID ) ) {
            ?>
            <div class="alert alert-warning">
                <p><strong>You've already pending withdraw request(s).</strong></p>
                <p>Until it's been cancelled or approved, you can't submit any new request.</p>
            </div>

            <?php
            $this->withdraw_requests( $current_user->ID );
            return;

        } else if ( !$this->has_withdraw_balance( $current_user->ID ) ) {

            print(__( 'You have no sufficient account balance for withdraw request', 'dokan' ) );
            return;
        }

        $payment_methods = $this->get_payment_methods();

        if( is_wp_error($validate) ) {
            $amount = $_POST['witdraw_amount'];
            $withdraw_method = $_POST['withdraw_method'];
        } else {
            $amount = '';
            $withdraw_method = '';
        }
        ?>


        <div class="alert  alert-danger" style="display: none;">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <strong class="jquery_error_place"></strong>
        </div>

        <span class="ajax_table_shown"></span>
        <form class="form-horizontal withdraw" role="form" method="post">
            <div class="form-group">

                <label for="withdraw-amount" class="col-sm-3 control-label">
                    <?php _e( 'Withdraw Amount', 'dokan' ); ?>
                </label>

                <div class="col-sm-3 ">
                    <div class="input-group">
                        <span class="input-group-addon"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input name="witdraw_amount" required number min="50" class="form-control" id="withdraw-amount" name="price" type="number" placeholder="9.99" value="<?php echo $amount; ?>"  >
                    </div>

                </div>
            </div>

            <div class="form-group">
                <label for="withdraw-method" class="col-sm-3 control-label">
                    <?php _e( 'Payment Method', 'dokan' ); ?>
                </label>

                <div class="col-sm-3">
                    <select class="form-control" required name="withdraw_method" id="withdraw-method">
                        <?php foreach ($payment_methods as $value => $name) { ?>
                            <option <?php selected( $withdraw_method, $value );  ?>value="<?php echo esc_attr( $value ); ?>"><?php echo $name; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-10 ajax_prev" style="width: 200px;">
                    <?php wp_nonce_field( 'dokan_withdraw', 'dokan_withdraw_nonce' ); ?>
                    <input type="submit" class="btn btn-primary" value="<?php esc_attr_e( 'Submit Request', 'dokan' ); ?>" name="withdraw_submit">
                </div>
            </div>
        </form>


        <?php
    }

}