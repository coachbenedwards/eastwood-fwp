<?php
/**
 * Bookings -> New shirt order: staff take a shirt order and send a payment link.
 *
 * For someone who asks at the bar, on the phone or by message. Staff pick
 * the shirts, sizes and any printing, choose collect or post, and get a
 * Stripe payment link to copy or email from orders@. The order is a
 * Booking like any website order, so it shows in Bookings, in Analytics
 * and in GoHighLevel, and it is marked paid -- and the "Thanks for your
 * order" email goes -- when the customer pays.
 *
 * Prices, sizes in stock, printing and postage are read from the same
 * places the shirt page reads them, never typed in here.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

const EW_SO_SKUS = array( 'home-shirt-2627', 'home-shirt-2627-kids' );

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=ewt_booking', 'New shirt order', 'New shirt order', 'edit_posts', 'ew-shirt-order', 'ew_so_page' );
}, 21 );

/** The shirt page and its block: printing price, CRM hook, greeting. */
function ew_so_shirt_page() {
	$p = get_page_by_path( 'shop/home-shirt' );
	if ( ! $p ) {
		$p = get_post( 107 );
	}
	$block = ( $p && function_exists( 'ew_shirt_block_on' ) ) ? ew_shirt_block_on( $p ) : null;
	return array( $p, (array) ( $block['fields'] ?? array() ) );
}

/** Each shirt for sale, from the shop's own item endpoint. */
function ew_so_items() {
	$out = array();
	foreach ( EW_SO_SKUS as $sku ) {
		$r = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
		$r->set_query_params( array( 'sku' => $sku ) );
		$res = rest_do_request( $r );
		if ( ! $res->is_error() ) {
			$out[ $sku ] = (array) $res->get_data();
		}
	}
	return $out;
}

/** Turn the form into priced lines. Returns [lines[], error]. */
function ew_so_lines( $items, $fields, $sizes, $names, $nums ) {
	$pp    = (int) round( (float) ( $fields['print_price'] ?? 0 ) * 100 );
	$max   = max( 1, (int) ( $fields['print_max'] ?? 12 ) );
	$lines = array();
	foreach ( (array) $sizes as $i => $pick ) {
		$pick = sanitize_text_field( (string) $pick );
		if ( '' === $pick ) {
			continue;
		}
		list( $sku, $size ) = array_pad( explode( '|', $pick, 2 ), 2, '' );
		$item = $items[ $sku ] ?? null;
		if ( ! $item ) {
			return array( array(), 'Unknown shirt.' );
		}
		$ok = false;
		foreach ( (array) ( $item['sizes'] ?? array() ) as $row ) {
			if ( (string) ( $row['size'] ?? '' ) === $size && ! empty( $row['in_stock'] ) ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return array( array(), $size . ' is not in stock.' );
		}
		$pname = strtoupper( trim( preg_replace( "/[^A-Za-z '-]/", '', (string) ( $names[ $i ] ?? '' ) ) ) );
		$pname = substr( $pname, 0, $max );
		$pnum  = (int) preg_replace( '/[^0-9]/', '', (string) ( $nums[ $i ] ?? '' ) );
		$pnum  = ( $pnum > 0 && $pnum < 100 ) ? (string) $pnum : '';
		$print = trim( $pname . ( '' !== $pname && '' !== $pnum ? ' ' : '' ) . $pnum );
		if ( '' !== $print && $pp <= 0 ) {
			return array( array(), 'Printing is not offered at the moment.' );
		}
		$lines[] = array(
			'sku'   => $sku,
			'name'  => trim( (string) ( $item['name'] ?? 'Shirt' ) ),
			'size'  => $size,
			'pence' => (int) round( (float) ( $item['price'] ?? 0 ) * 100 ),
			'print' => $print,
			'pp'    => '' !== $print ? $pp : 0,
			'ship'  => (int) round( (float) ( $item['postage'] ?? 0 ) * 100 ),
		);
	}
	if ( ! $lines ) {
		return array( array(), 'Add at least one shirt.' );
	}
	return array( $lines, '' );
}

/** Make the order: a Booking, then a Stripe payment link. */
function ew_so_create( $email, $name, $lines, $post ) {
	list( $page, $fields ) = ew_so_shirt_page();
	if ( ! $page || ! function_exists( 'ew_tix_booking_start' ) || ! function_exists( 'ew_tix_stripe' ) || '' === ew_tix_sk() ) {
		return new WP_Error( 'ew_so', 'Card payment is not set up.' );
	}
	$ship  = $post ? max( array_column( $lines, 'ship' ) ) : 0;
	$total = $ship;
	$desc  = array();
	foreach ( $lines as $l ) {
		$total += $l['pence'] + $l['pp'];
		$desc[] = $l['size'] . ( '' !== $l['print'] ? ' printed ' . $l['print'] : '' );
	}
	$what = implode( ', ', $desc ) . ' · ' . ( $post ? 'posted' : 'collecting' );

	$id = (int) ew_tix_booking_start( $page, 'Home shirt 2026/27 (staff order)', $name, $email, $what, $total,
		(string) ( $fields['crm_hook'] ?? '' ), (string) ( $fields['greeting'] ?? '' ) );
	if ( ! $id ) {
		return new WP_Error( 'ew_so', 'Could not save the order.' );
	}
	update_post_meta( $id, '_ewt_kind', 'shop' );
	update_post_meta( $id, '_ewt_staff', get_current_user_id() );
	update_post_meta( $id, '_ewt_shirts', wp_json_encode( array_map( function ( $l ) {
		return array( $l['sku'], $l['size'] );
	}, $lines ) ) );
	// A link sent by a person is not a checkout someone walked away from.
	wp_clear_scheduled_hook( 'ew_tix_remind', array( $id ) );

	$body = array(
		'mode'                => 'payment',
		'success_url'         => add_query_arg( 'ewsh_paid', '{CHECKOUT_SESSION_ID}', get_permalink( $page ) ),
		'cancel_url'          => get_permalink( $page ),
		'customer_email'      => $email,
		'expires_at'          => (string) ( time() + DAY_IN_SECONDS - 300 ),
		'metadata[booking]'   => (string) $id,
		'metadata[delivery]'  => $post ? 'post' : 'collect',
		'metadata[staff]'     => 'yes',
		'phone_number_collection[enabled]' => 'true',
	);
	$n = 0;
	foreach ( $lines as $l ) {
		$body[ "line_items[$n][quantity]" ]                       = '1';
		$body[ "line_items[$n][price_data][currency]" ]           = 'gbp';
		$body[ "line_items[$n][price_data][unit_amount]" ]        = (string) $l['pence'];
		$body[ "line_items[$n][price_data][product_data][name]" ] = $l['name'] . ' · ' . $l['size'];
		++$n;
		if ( $l['pp'] ) {
			$body[ "line_items[$n][quantity]" ]                       = '1';
			$body[ "line_items[$n][price_data][currency]" ]           = 'gbp';
			$body[ "line_items[$n][price_data][unit_amount]" ]        = (string) $l['pp'];
			$body[ "line_items[$n][price_data][product_data][name]" ] = 'Printing · ' . $l['print'];
			++$n;
		}
	}
	if ( $ship ) {
		$body['shipping_address_collection[allowed_countries][0]'] = 'GB';
		$body[ "line_items[$n][quantity]" ]                       = '1';
		$body[ "line_items[$n][price_data][currency]" ]           = 'gbp';
		$body[ "line_items[$n][price_data][unit_amount]" ]        = (string) $ship;
		$body[ "line_items[$n][price_data][product_data][name]" ] = 'Postage';
	}
	$s = ew_tix_stripe( 'POST', 'checkout/sessions', $body );
	if ( empty( $s['url'] ) ) {
		return new WP_Error( 'ew_so', (string) ( $s['error']['message'] ?? 'Stripe refused the order.' ) );
	}
	update_post_meta( $id, '_ewt_open_session', (string) $s['id'] );
	update_post_meta( $id, '_ewt_paylink', (string) $s['url'] );
	update_post_meta( $id, '_ewt_link_expires', (int) ( $s['expires_at'] ?? 0 ) );
	return $id;
}

/** Ask Stripe about orders still waiting, so the list is right without a webhook. */
function ew_so_refresh( $ids ) {
	list( , $fields ) = ew_so_shirt_page();
	foreach ( $ids as $id ) {
		if ( 'started' !== get_post_meta( $id, '_ewt_status', true ) ) {
			continue;
		}
		$sid = (string) get_post_meta( $id, '_ewt_open_session', true );
		if ( '' === $sid ) {
			continue;
		}
		$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) );
		if ( 'paid' === ( $s['payment_status'] ?? '' ) && function_exists( 'ew_shirt_mark_paid' ) ) {
			ew_shirt_mark_paid( $sid, (string) ( $fields['crm_hook'] ?? '' ) );
		}
	}
}

function ew_so_default_mail() {
	return "Hi {first_name},\n\nHere's the link to pay for your Eastwood FC shirt order:\n\n{shirts}\nTotal: £{total}\n\n{link}\n\nThe link works for 24 hours. Any questions, just reply to this email.\n\nEastwood FC";
}

function ew_so_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$items = ew_so_items();
	list( $page, $fields ) = ew_so_shirt_page();
	$pp     = (float) ( $fields['print_price'] ?? 0 );
	$notice = '';
	$made   = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0; // phpcs:ignore

	// Create.
	if ( isset( $_POST['ew_so_make'] ) && check_admin_referer( 'ew_so_make' ) ) {
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['cname'] ?? '' ) );
		list( $lines, $err ) = ew_so_lines( $items, $fields,
			(array) wp_unslash( $_POST['size'] ?? array() ),
			(array) wp_unslash( $_POST['pname'] ?? array() ),
			(array) wp_unslash( $_POST['pnum'] ?? array() ) );
		if ( ! is_email( $email ) ) {
			$err = 'Add the customer\'s email.';
		}
		if ( '' === $err ) {
			$r = ew_so_create( $email, $name, $lines, ! empty( $_POST['post'] ) );
			if ( is_wp_error( $r ) ) {
				$err = $r->get_error_message();
			} else {
				$made = (int) $r;
			}
		}
		if ( '' !== $err ) {
			$notice = '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	// Email the link.
	if ( isset( $_POST['ew_so_mail'] ) && check_admin_referer( 'ew_so_mail' ) ) {
		$made = (int) $_POST['ew_so_mail'];
		$text = sanitize_textarea_field( wp_unslash( $_POST['mailtext'] ?? '' ) );
		update_option( 'ew_so_mail', $text );
		$to   = (string) get_post_meta( $made, '_ewt_email', true );
		$name = trim( (string) get_post_meta( $made, '_ewt_name', true ) );
		$body = strtr( $text, array(
			'{first_name}' => false === strpos( $name, ' ' ) ? $name : substr( $name, 0, strpos( $name, ' ' ) ),
			'{shirts}'     => (string) get_post_meta( $made, '_ewt_lines', true ),
			'{total}'      => number_format( (int) get_post_meta( $made, '_ewt_total', true ) / 100, 2 ),
			'{link}'       => (string) get_post_meta( $made, '_ewt_paylink', true ),
		) );
		$body = preg_replace( '/ +,/', ',', $body );
		$ok   = is_email( $to ) && wp_mail( $to, 'Your Eastwood FC shirt order', $body );
		if ( $ok ) {
			update_post_meta( $made, '_ewt_link_mailed', time() );
		}
		$notice = '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . ( $ok ? 'Payment link emailed to ' . esc_html( $to ) . '.' : 'The email could not be sent.' ) . '</p></div>';
	}

	$staff = get_posts( array( 'post_type' => 'ewt_booking', 'post_status' => 'any', 'numberposts' => 30, 'fields' => 'ids', 'meta_key' => '_ewt_staff' ) ); // phpcs:ignore
	ew_so_refresh( $staff );

	// Size options, grouped by fit.
	$opts = '<option value="">Choose a size</option>';
	foreach ( $items as $sku => $it ) {
		$groups = array();
		foreach ( (array) ( $it['sizes'] ?? array() ) as $row ) {
			$sz  = (string) ( $row['size'] ?? '' );
			$fit = ucfirst( strtolower( strtok( $sz, ' ' ) ) );
			$fit = 'Womens' === $fit ? "Women's" : $fit;
			$groups[ $fit ][] = '<option value="' . esc_attr( $sku . '|' . $sz ) . '"' . ( empty( $row['in_stock'] ) ? ' disabled' : '' ) . '>'
				. esc_html( $sz . ' — £' . number_format( (float) $it['price'], 2 ) . ( empty( $row['in_stock'] ) ? ' (out of stock)' : '' ) ) . '</option>';
		}
		foreach ( $groups as $fit => $o ) {
			$opts .= '<optgroup label="' . esc_attr( $fit ) . '">' . implode( '', $o ) . '</optgroup>';
		}
	}
	$postage = 0;
	foreach ( $items as $it ) {
		$postage = max( $postage, (float) ( $it['postage'] ?? 0 ) );
	}
	$row = '<tr class="ew-so-row"><td><select name="size[]">' . $opts . '</select></td>'
		. ( $pp > 0 ? '<td><input type="text" name="pname[]" placeholder="Name" style="width:9em"> <input type="text" name="pnum[]" placeholder="No." style="width:4em" inputmode="numeric"></td>' : '' )
		. '<td><button type="button" class="button-link ew-so-del">Remove</button></td></tr>';
	?>
<div class="wrap ew-so">
<style>
.ew-so .cols{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:18px;align-items:start;margin-top:12px}
.ew-so .box{background:#fff;border:1px solid #c3c4c7;padding:16px}
.ew-so label.b{display:block;font-weight:600;margin:12px 0 6px}
.ew-so .total{font-size:18px;margin-top:18px;padding-top:14px;border-top:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center}
.ew-so .link{width:100%;font-size:13px}
@media(max-width:960px){.ew-so .cols{grid-template-columns:1fr}}
</style>
<h1>New shirt order</h1>
<?php echo $notice; // phpcs:ignore ?>
<div class="cols">
<form method="post" class="box" id="ewSoForm">
	<?php wp_nonce_field( 'ew_so_make' ); ?>
	<input type="hidden" name="ew_so_make" value="1">
	<label class="b" for="ewSoEmail" style="margin-top:0">Customer email</label>
	<input class="regular-text" style="width:100%" type="email" id="ewSoEmail" name="email" required placeholder="name@example.com">
	<label class="b" for="ewSoName">Customer name</label>
	<input class="regular-text" style="width:100%" type="text" id="ewSoName" name="cname">

	<h2 style="margin:22px 0 8px">Shirts</h2>
	<table class="widefat striped">
		<thead><tr><th>Shirt and size</th><?php echo $pp > 0 ? '<th>Printing (+£' . esc_html( number_format( $pp, 2 ) ) . ')</th>' : ''; ?><th></th></tr></thead>
		<tbody id="ewSoRows"><?php echo $row; // phpcs:ignore ?></tbody>
	</table>
	<p><button type="button" class="button" id="ewSoAdd">+ Add another shirt</button></p>

	<label class="b">Getting it to them</label>
	<label><input type="radio" name="post" value="" checked> Collect at the club</label>&nbsp;&nbsp;
	<?php if ( $postage > 0 ) : ?><label><input type="radio" name="post" value="1"> Post · £<?php echo esc_html( number_format( $postage, 2 ) ); ?></label><?php endif; ?>

	<div class="total"><div>Total <b id="ewSoTotal">£0.00</b></div><?php submit_button( 'Create payment link', 'primary', 'submit', false ); ?></div>
</form>

<div>
	<div class="box">
		<h2 style="margin-top:0">Payment link</h2>
		<?php if ( $made && get_post_meta( $made, '_ewt_paylink', true ) ) : $link = (string) get_post_meta( $made, '_ewt_paylink', true ); ?>
		<p><?php echo esc_html( (string) get_post_meta( $made, '_ewt_lines', true ) ); ?> — <b>£<?php echo esc_html( number_format( (int) get_post_meta( $made, '_ewt_total', true ) / 100, 2 ) ); ?></b> for <?php echo esc_html( (string) get_post_meta( $made, '_ewt_email', true ) ); ?></p>
		<input class="link" type="text" readonly value="<?php echo esc_attr( $link ); ?>" id="ewSoLink">
		<p><button type="button" class="button" id="ewSoCopy">Copy link</button> <span class="description">Works for 24 hours.</span></p>
		<form method="post">
			<?php wp_nonce_field( 'ew_so_mail' ); ?>
			<input type="hidden" name="ew_so_mail" value="<?php echo (int) $made; ?>">
			<label class="b">Email to the customer</label>
			<textarea name="mailtext" rows="9" class="large-text"><?php echo esc_textarea( (string) get_option( 'ew_so_mail', ew_so_default_mail() ) ); ?></textarea>
			<?php submit_button( 'Email it to the customer', 'secondary', 'submit', false ); ?>
		</form>
		<?php else : ?>
		<p class="description">Shows here once created.</p>
		<?php endif; ?>
	</div>

	<h2>Staff orders</h2>
	<table class="widefat striped">
		<thead><tr><th>Customer</th><th>Shirts</th><th>Total</th><th>Status</th></tr></thead>
		<tbody>
		<?php if ( ! $staff ) : ?><tr><td colspan="4" class="description">No staff orders yet</td></tr><?php endif; ?>
		<?php foreach ( $staff as $id ) :
			$paid = 'paid' === get_post_meta( $id, '_ewt_status', true );
			$exp  = (int) get_post_meta( $id, '_ewt_link_expires', true );
			$st   = $paid ? 'Paid' : ( $exp && $exp < time() ? 'Link expired' : 'Waiting' );
			?>
		<tr><td><a href="<?php echo esc_url( add_query_arg( 'order', $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></td>
		<td><?php echo esc_html( (string) get_post_meta( $id, '_ewt_lines', true ) ); ?></td>
		<td>£<?php echo esc_html( number_format( (int) get_post_meta( $id, '_ewt_total', true ) / 100, 2 ) ); ?></td>
		<td><?php echo esc_html( $st ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
</div>
<script>
(function(){
	var rows=document.getElementById('ewSoRows'),tpl=rows.innerHTML,pp=<?php echo wp_json_encode( $pp ); ?>,post=<?php echo wp_json_encode( $postage ); ?>,
		price=<?php $pr = array(); foreach ( $items as $sku => $it ) { $pr[ $sku ] = (float) $it['price']; } echo wp_json_encode( $pr ); ?>;
	function total(){var t=0;rows.querySelectorAll('.ew-so-row').forEach(function(r){var v=r.querySelector('select').value;if(!v)return;t+=price[v.split('|')[0]]||0;
		var n=r.querySelector('[name="pname[]"]'),m=r.querySelector('[name="pnum[]"]');if(pp&&((n&&n.value.trim())||(m&&m.value.trim())))t+=pp;});
		var p=document.querySelector('input[name="post"]:checked');if(p&&p.value)t+=post;document.getElementById('ewSoTotal').textContent='£'+t.toFixed(2);}
	document.getElementById('ewSoAdd').onclick=function(){rows.insertAdjacentHTML('beforeend',tpl);total();};
	rows.addEventListener('click',function(e){if(e.target.classList.contains('ew-so-del')&&rows.children.length>1){e.target.closest('tr').remove();total();}});
	document.getElementById('ewSoForm').addEventListener('input',total);document.getElementById('ewSoForm').addEventListener('change',total);
	var c=document.getElementById('ewSoCopy');if(c)c.onclick=function(){var l=document.getElementById('ewSoLink');l.select();navigator.clipboard.writeText(l.value);c.textContent='Copied';};
})();
</script>
</div>
	<?php
}

/* Staff orders get the "Thanks for your order" email but no one-hour nudge. */
add_filter( 'ew_om_skip', function ( $skip, $booking_id, $step ) {
	return $skip || ( 'reminder' === $step && get_post_meta( $booking_id, '_ewt_staff', true ) );
}, 10, 3 );
