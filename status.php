<?php
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root . '/wp-load.php')) {
    require_once($root . '/wp-load.php');
} else {
    require_once($root . '/wp-config.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (!isset($GLOBALS['post']) || empty($GLOBALS['post'])) {
        $GLOBALS['post'] = array();
    }
    get_header();
} else {
    exit;
}
?>

<?php
$root = dirname(dirname(dirname(dirname(__FILE__))));
if (file_exists($root . '/wp-load.php')) {
    require_once($root . '/wp-load.php');
} else {
    require_once($root . '/wp-config.php');
}

class CPGPaymentsStatus
{
    public function __construct()
    {

        $this->transaction_id = $_GET['transaction_id'];
        $this->order_id = $_GET['order_id'];
        $this->redirect_url = urldecode($_GET['redirect_url']);
        $this->reference_id = $_GET['reference'];
        $this->init_view();
        $this->track_transaction($this->transaction_id, $this->order_id, $this->reference_id);
    }

    public function init_view()
    {
        echo '<style>
            #wc_cpg_status_wait_block p.text_loading {
                margin-bottom: 20px;
                font-size: 25px;          
                line-height: 1.6;
                color: black;
            }
            li {
                list-style: none;
            }
            #wc_cpg_status_wait_block {
        	    max-width: 800px;
        	    position: absolute;
        	    left: 20px;
        	    right: 20px;
        	    margin: 0 auto;
        	    z-index: 1000;
        	    padding: 40px 20px;
    		    background: rgba(255,255,255, 0.9);
        	    font-size: 16px;
        	    box-shadow: 1px 1px 20px #00000020;
            }
            .wc_cpg_status_wait_block {
                margin: 0 auto;
                text-align: center;
                font-size: 16px!important;
            }
            .storefront-breadcrumb {
                display: none!important;
            }
            #loading {
              margin-top: 20px;
              display: inline-block;
              width: 50px;
              height: 50px;
              border: 3px solid #0000004d;
              border-radius: 50%;
              border-top-color: #000;
              animation: spin 1s ease-in-out infinite;
              -webkit-animation: spin 1s ease-in-out infinite;
            }

            @keyframes spin {
              to { -webkit-transform: rotate(360deg); }
            }
            @-webkit-keyframes spin {
              to { -webkit-transform: rotate(360deg); }
            }
           
        </style>
        <div id="wc_cpg_status_wait_block" class="wc_cpg_status_wait_block">
            <p class="text_loading">Please do not exit or refresh this page. We are processing your transaction. This may take up to a minute to complete.…</p>
            <div id="loading"></div>
        </div>
        <script>
        	const statusContentContainer = document.getElementById("wc_cpg_status_wait_block")
        	const windowHeight = window.innerHeight
        	const blockHeight = document.getElementById("wc_cpg_status_wait_block").offsetHeight

        	statusContentContainer.style.top = (windowHeight - blockHeight) / 2 + "px"
        </script>';
    }

    public function track_transaction($transaction_id, $order_id, $reference_id)
    {
        $TRANSACTION_COMPLETE = 'COMPLETE';
        $TRANSACTION_CREATED = 'CREATED';
        $TRANSACTION_FAILED = 'FAILED';
        $TRANSACTION_ABANDONED = 'ABANDONED';
        $TRANSACTION_REFUNDED = 'REFUNDED';
        $TRANSACTION_PROCESSING = 'PROCESSING';
        $TRANSACTION_CHARGEBACK = 'CHARGEBACK';
        $TRANSACTION_CUSTOMER_VALIDATION = 'CUSTOMER_VALIDATION';

        $get_site = get_site_url();

        echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>';
        echo "
        <script>
            const div_content = document.getElementById('wc_cpg_status_wait_block')
            async function getPost() {
                let reason_text = 'We are unable to process your order at this time.'
                let declined_text = 'Payment FAILED - $reference_id'
                try {
                    const formData = {
                        transaction_id: '$transaction_id',
                        order_id: '$order_id',
                    }
                    const complete_array = ['$TRANSACTION_COMPLETE']
                    const processing_array = ['$TRANSACTION_CREATED', '$TRANSACTION_PROCESSING', '$TRANSACTION_CUSTOMER_VALIDATION']
                    const failed_array = ['$TRANSACTION_ABANDONED', '$TRANSACTION_FAILED', '$TRANSACTION_REFUNDED', '$TRANSACTION_CHARGEBACK']
                    jQuery.ajax({
                        type: 'POST',
                        url: 'track_transaction.php',
                        dataType: 'json',
                        data: formData,
                        success: function (obj) {
                            let obj_json = {}
                            try { obj_json = JSON.parse(obj) } catch (e) { console.log(e) }
                            console.log('obj:', obj_json)
                            if (complete_array.includes(obj_json['status'])) {
                                clearInterval(timerId)
                                window.location.href = '$this->redirect_url'
                            } else if (processing_array.includes(obj_json['status'])) {
                              console.log('Processing')
                            } else if (failed_array.includes(obj_json['status'])) {
                                clearInterval(timerId)
                                if (obj_json['fail_reason']) reason_text = obj_json['fail_reason']
                                div_content.classList.remove('wc_cpg_status_wait_block')
                                div_content.innerHTML = '<h1 style=\'font-weight: 400;\'>Order has not been received</h1>'
                                div_content.innerHTML += '<div class=\'woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout\'><ul class=\'woocommerce-error\' role=\'alert\'><li>' + declined_text + '</li></ul></div>'
                                div_content.innerHTML += '<p style=\'color: black;\'>' + reason_text + '</p>'
                                div_content.innerHTML += '<a class=\'error-btn button\' href=\'$get_site\'>Return To Homepage</a>'
                            }
                        }
                    });
                } catch (e) {
                    clearInterval(timerId)
                    console.log(e)
                    reason_text = e.name
                    div_content.classList.remove('wc_cpg_status_wait_block')
                    div_content.innerHTML = '<h1 style=\'font-weight: 400;\'>Order has not been received</h1>'
                    div_content.innerHTML += '<div class=\'woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout\'><ul class=\'woocommerce-error\' role=\'alert\'><li>' + declined_text + '</li></ul></div>'
                    div_content.innerHTML += '<p style=\'color: black;\'>' + reason_text + '</p>'
                    div_content.innerHTML += '<a class=\'error-btn button\' href=\'$get_site\'>Return To Homepage</a>'
                }
            }
            let timerId = setInterval(getPost, 5000)
        </script>";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    new CPGPaymentsStatus;
} else {
    exit;
}
