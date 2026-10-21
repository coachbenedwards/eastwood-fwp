<?php
/**
 * The shop pages as blocks.
 *
 * Both of these were pages of hand-written HTML -- the shop in the page
 * itself, the shirt in a single code box -- so nobody but me could change
 * a word of either. They are blocks now, and every word they put on the
 * screen is a field: the card names and prices, the FIT and SIZE labels,
 * the two delivery choices and their small print, and the lines shown
 * while the shop is loading or when it cannot be reached.
 *
 * What is NOT a field is the data: prices, sizes and stock come from the
 * shop API, as they did before.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The drawn shirt both pages use in place of a photograph. */
function ew_shop_shirt_svg() {
	return '<svg viewBox="0 0 440 520" width="100%"> <defs> <linearGradient id="bodyGp" x1="0.1" y1="0.05" x2="0.92" y2="0.95"> <stop offset="0" stop-color="#ef1f1f"></stop><stop offset=".45" stop-color="#d81212"></stop> <stop offset=".8" stop-color="#b40707"></stop><stop offset="1" stop-color="#950303"></stop> </linearGradient> <clipPath id="clipSp"><path d="M150 62 C172 51 196 46 220 46 C244 46 268 51 290 62 L390 142 L368 212 L318 192 L318 408 C318 417 311 423 302 423 L138 423 C129 423 122 417 122 408 L122 192 L72 212 L50 142 Z"></path></clipPath> <filter id="softp" x="-30%" y="-30%" width="160%" height="160%"> <feGaussianBlur stdDeviation="14"></feGaussianBlur> </filter> <filter id="soft2p" x="-30%" y="-30%" width="160%" height="160%"> <feGaussianBlur stdDeviation="7"></feGaussianBlur> </filter> <filter id="dropSp" x="-30%" y="-20%" width="160%" height="155%"> <feDropShadow dx="0" dy="16" stdDeviation="18" flood-color="#000" flood-opacity=".32"></feDropShadow> </filter> </defs> <g filter="url(#dropSp)"> <path d="M150 62 C172 51 196 46 220 46 C244 46 268 51 290 62 L390 142 L368 212 L318 192 L318 408 C318 417 311 423 302 423 L138 423 C129 423 122 417 122 408 L122 192 L72 212 L50 142 Z" fill="url(#bodyGp)"></path> <g clip-path="url(#clipSp)"> <g filter="url(#softp)" opacity=".55"> <rect x="78" y="40" width="58" height="420" fill="#6d0000"></rect> <rect x="304" y="40" width="60" height="420" fill="#6d0000"></rect> <ellipse cx="220" cy="150" rx="96" ry="66" fill="#ff6a6a" opacity=".55"></ellipse> </g> <g filter="url(#soft2p)" opacity=".4"> <path d="M158 96 C166 200 162 320 158 430 L176 430 C180 320 182 200 172 96 Z" fill="#7d0000"></path> <path d="M282 96 C272 200 278 320 282 430 L300 430 C304 320 300 200 296 96 Z" fill="#7d0000"></path> <path d="M206 92 C210 220 208 330 210 430 L230 430 C232 330 230 220 234 92 Z" fill="#ff8a8a" opacity=".5"></path> </g> <path d="M130 66 L310 66 L310 94 L130 94 Z" fill="#fff" opacity=".07" filter="url(#soft2p)"></path> </g> <path d="M180 58 C191 83 204 94 220 94 C236 94 249 83 260 58 L250 55 C242 75 232 82 220 82 C208 82 198 75 190 55 Z" fill="#1c1c1c"></path> <path d="M190 55 C198 77 208 84 220 84 C232 84 242 77 250 55" fill="none" stroke="#f2f2f2" stroke-width="3" opacity=".95"></path> </g> </svg>';
}

/* --- The shop index ------------------------------------------------- */

/**
 * [eastwood_shop]
 *
 * One card per line in the body:
 *
 *   Home shirt 2026/27 | From £30 | Adult, Women's and Kids fits | /shop/home-shirt/
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body.
 * @return string
 */
function ew_shop_shortcode( $atts, $content = null ) {
	$cards = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		if ( '' === (string) ( $bits[0] ?? '' ) ) {
			continue;
		}
		$cards[] = array(
			'name'  => (string) $bits[0],
			'price' => (string) ( $bits[1] ?? '' ),
			'note'  => (string) ( $bits[2] ?? '' ),
			'href'  => (string) ( $bits[3] ?? '' ),
		);
	}
	if ( ! $cards ) {
		return '';
	}

	/*
	 * The same class names the page used when it was hand-written HTML,
	 * deliberately: the stylesheet that squares this page up with the rest
	 * of the site is written against them, so keeping them means the page
	 * comes out of the conversion looking identical rather than needing a
	 * second set of rules that says the same thing.
	 */
	$out = '<div class="ewshop"><div class="ewshop-grid">';
	foreach ( $cards as $c ) {
		$out .= '<a class="ewshop-card" href="' . esc_url( $c['href'] ) . '">';
		$out .= '<div class="ewshop-pic">' . ew_shop_shirt_svg() . '</div>';
		$out .= '<div class="ewshop-body">';
		$out .= '<h2>' . esc_html( $c['name'] ) . '</h2>';
		if ( '' !== $c['price'] ) {
			$out .= '<p class="p">' . esc_html( $c['price'] ) . '</p>';
		}
		if ( '' !== $c['note'] ) {
			$out .= '<p class="m">' . esc_html( $c['note'] ) . '</p>';
		}
		$out .= '</div></a>';
	}
	$out .= '</div></div>';

	return $out;
}
add_shortcode( 'eastwood_shop', 'ew_shop_shortcode' );

/* --- One product ----------------------------------------------------- */

/**
 * [eastwood_shirt]
 *
 * One fit per line in the body: the code the shop API uses, the word a
 * person reads, and the SKU it belongs to.
 *
 *   ADULT | Adult | home-shirt-2627
 *   WOMENS | Women's | home-shirt-2627
 *   KIDS | Kids | home-shirt-2627-kids
 *
 * @param array       $atts    Attributes.
 * @param string|null $content Body.
 * @return string
 */
function ew_shirt_shortcode( $atts, $content = null ) {
	$a = shortcode_atts(
		array(
			'title'         => '',
			'brand'         => '',
			'fit_label'     => 'Fit',
			'size_label'    => 'Size',
			'chart_label'   => 'Size chart',
			'chart_url'     => '',
			'in_stock'      => 'In stock',
			'sold_out'      => 'Sold out',
			'print_price'   => '',
			'print_label'   => 'Customise your shirt',
			'print_name'    => 'Name',
			'print_num'     => 'Number',
			'print_max'     => '12',
			'print_chars'   => '{n} of {max} characters',
			'print_range'   => '1 to 99',
			'print_warn'    => 'Printed shirts cannot be returned.',
			'print_bad'     => 'Give a name or a number to print.',
			'print_who'     => 'Whose name?',
			'print_mine'    => 'My own name and number',
			'print_squad'   => '',
			'print_title'   => 'Roster or Name & Number',
			'print_players' => 'Players',
			'print_custom'  => 'Custom',
			'print_none'    => 'None',
			'print_pick'    => 'Select Player',
			'shirt_front'   => '',
			'shirt_back'    => '',
			'print_cta'     => 'Customise',
			'print_head'    => 'Customise',
			'print_done'    => 'Done',
			'print_add'     => 'add +{price}',
			'print_lead'    => '',
			'print_chosen'  => '{what}',
			'share_label'   => 'Share',
			'ful_label'     => 'How do you want it',
			'collect_title' => 'Collect at the ground',
			'collect_note'  => '',
			'post_title'    => 'Post it to me',
			'post_note'     => 'Royal Mail. {price} added at checkout.',
			'empty'         => 'Not on sale yet.',
			'failed'        => 'Could not load the shop just now.',
			'pay'           => 'Pay by card',
			'det_title'     => 'Your details',
			'order_title'   => 'Your order',
			'back_pick'     => 'Back to the shirt',
			'back_det'      => 'Back to your details',
			'sum_collect'   => 'collecting',
			'sum_post'      => 'posted',
			'email_label'   => 'Email',
			'email_note'    => 'Your receipt and anything about the order goes here.',
			'email_bad'     => 'We need an email to send your receipt to.',
			'consent'       => 'Tell me when new kit and home tickets go on sale',
			'crm_hook'      => '',
			'greeting'      => '',
			'promos'        => '',
			'hide_pay'      => '',
			'cont'          => 'Continue',
			'loading'       => 'Loading the payment form…',
			'off'           => 'Card payment opens soon.',
			'thanks'        => 'Thank you. Your receipt is on its way to your email.',
		),
		(array) $atts,
		'eastwood_shirt'
	);

	$fits = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $content ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$bits = array_map( 'trim', explode( '|', $line ) );
		$code = strtoupper( (string) ( $bits[0] ?? '' ) );
		if ( '' === $code ) {
			continue;
		}
		$fits[] = array(
			'code' => $code,
			'name' => (string) ( $bits[1] ?? $code ),
			'sku'  => (string) ( $bits[2] ?? '' ),
		);
	}
	if ( ! $fits ) {
		return '';
	}

	// Everything the script needs to say, handed to it rather than written
	// into it, so the words stay here where a person can reach them.
	$words = array(
		'fits'      => $fits,
		'collect'   => array( $a['collect_title'], $a['collect_note'] ),
		'post'      => array( $a['post_title'], $a['post_note'] ),
		'empty'     => $a['empty'],
		'failed'    => $a['failed'],
		'paybtn'    => $a['pay'],
		'instock'   => $a['in_stock'],
		'soldout'   => $a['sold_out'],
		'squad'     => array_values( array_filter( array_map(
			function ( $row ) {
				$bits = array_map( 'trim', explode( '|', $row ) );
				$num  = (int) preg_replace( '/[^0-9]/', '', (string) ( $bits[0] ?? '' ) );
				$who  = trim( (string) ( $bits[1] ?? '' ) );
				return ( $who && $num > 0 && $num < 100 ) ? array( 'n' => (string) $num, 'w' => $who ) : null;
			},
			preg_split( '/[;\r\n]+/', (string) $a['print_squad'] )
		) ) ),
		'whoLab'    => $a['print_who'],
		'mineLab'   => $a['print_mine'],
		'pickLab'   => $a['print_pick'],
		'ordtitle'  => $a['order_title'],
		'print'     => '' === trim( (string) $a['print_price'] ) ? null : array(
			'price' => round( (float) $a['print_price'], 2 ),
			'label' => $a['print_label'],
			'chars' => $a['print_chars'],
			'max'   => max( 1, (int) $a['print_max'] ),
			'bad'   => $a['print_bad'],
			'cta'    => $a['print_cta'],
			'head'   => $a['print_head'],
			'done'   => $a['print_done'],
			'add'    => $a['print_add'],
			'lead'   => $a['print_lead'],
			'chosen' => $a['print_chosen'],
			'shirt'  => ew_asset_url( 'assets/shop/home-shirt-back.png' ),
		),
		'sizelab'   => $a['size_label'],
		'emailbad'  => $a['email_bad'],
		'title'     => $a['title'],
		'backpick'  => $a['back_pick'],
		'backdet'   => $a['back_det'],
		'sumcol'    => $a['sum_collect'],
		'sumpost'   => $a['sum_post'],
		'loading'   => $a['loading'],
		'off'       => $a['off'],
		// The publishable key is public by design; the secret never
		// leaves the server.
		'pk'        => function_exists( 'ew_stripe_creds' )
			? (string) ( ew_stripe_creds()['publishable'] ?? '' ) : '',
		'rest'      => rest_url( 'eastwood/v1/shirt/checkout' ),
		'page'      => (int) get_the_ID(),
	);

	ob_start();
	?>
<div class="ewsh" data-shirt="<?php echo esc_attr( wp_json_encode( $words ) ); ?>">
 <div class="ewsh-wrap">
  <div class="ewsh-gal">
   <div class="ewsh-galface" id="ewshGalFront">
    <img src="<?php echo esc_url( '' !== trim( (string) $a['shirt_front'] ) ? $a['shirt_front'] : ew_asset_url( 'assets/shop/home-shirt-front.jpg' ) ); ?>" alt="<?php echo esc_attr( $a['title'] ); ?>" width="900" height="900">
   </div>
   <?php if ( '' !== trim( (string) $a['print_price'] ) ) : ?>
   <div class="ewsh-prev" id="ewshGalBack" hidden>
    <img src="<?php echo esc_url( '' !== trim( (string) $a['shirt_back'] ) ? $a['shirt_back'] : ew_asset_url( 'assets/shop/home-shirt-back.png' ) ); ?>" alt="" width="700" height="764">
    <b id="ewshPvName"></b><i id="ewshPvNum"></i>
   </div>
   <?php endif; ?>
  </div>
  <div class="ewsh-info">
   <?php
	if ( isset( $_GET['ewsh_paid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification
		ew_shirt_mark_paid( sanitize_text_field( wp_unslash( $_GET['ewsh_paid'] ) ), $a['crm_hook'] ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
   <p class="ewsh-thanks"><?php echo esc_html( $a['thanks'] ); ?></p>
   <?php endif; ?>
   <div class="ewsh-pick">
    <h1><?php echo esc_html( $a['title'] ); ?></h1>
    <p class="ewsh-sub"><?php echo esc_html( $a['brand'] ); ?></p>
    <p class="ewsh-price" id="ewshPrice"></p>
    <p class="ewsh-note" id="ewshNote"></p>
    <div class="ewsh-rule"></div>
    <p class="ewsh-stock" id="ewshStock"></p>
    <p class="ewsh-lab"><?php echo esc_html( $a['fit_label'] ); ?></p>
    <div class="ewsh-fits" id="ewshFits"></div>
    <div class="ewsh-sizerow">
     <p class="ewsh-lab" id="ewshSizeLab"><?php echo esc_html( $a['size_label'] ); ?></p>
     <?php if ( '' !== $a['chart_url'] ) : ?>
     <a class="ewsh-chart" href="<?php echo esc_url( $a['chart_url'] ); ?>"><?php echo esc_html( $a['chart_label'] ); ?></a>
     <?php endif; ?>
    </div>
    <div class="ewsh-sizes" id="ewshSizes"></div>

    <?php if ( '' !== trim( (string) $a['print_price'] ) ) : ?>
    <div class="ewsh-printcta">
     <button type="button" class="ewsh-pbtn" id="ewshPrintOpen"><?php echo esc_html( $a['print_cta'] ); ?></button>
     <p class="ewsh-pchosen" id="ewshPChosen"></p>
    </div>
    <?php endif; ?>

    <div class="ewsh-pay" id="ewshPay"></div>
    <div class="ewsh-share">
     <span><?php echo esc_html( $a['share_label'] ); ?></span>
     <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( get_permalink() ); ?>" rel="noopener" target="_blank" aria-label="Share on Facebook"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 8h3V5h-3a4 4 0 0 0-4 4v2H8v3h2v6h3v-6h2.5l.5-3H13V9a1 1 0 0 1 1-1z"/></svg></a>
     <a href="https://x.com/intent/post?url=<?php echo rawurlencode( get_permalink() ); ?>" rel="noopener" target="_blank" aria-label="Share on X"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 4l16 16M20 4L4 20"/></svg></a>
     <a href="mailto:?body=<?php echo rawurlencode( get_permalink() ); ?>" aria-label="Share by email"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></a>
    </div>
   </div>

   <?php /* Once a size is chosen the shirt and the buttons give way to
            this: the same heading on both later steps, a way back, and
            one line saying what they are buying and what it costs. */ ?>
   <div class="ewsh-order" hidden>
    <div class="ewsh-head">
     <h2 id="ewshOrderTitle"><?php echo esc_html( $a['order_title'] ); ?></h2>
     <p class="ewsh-back">&lsaquo; <button type="button" id="ewshBack"></button></p>
    </div>
    <div class="ewsh-card">
     <div class="ewsh-sum">
      <p id="ewshSumWhat"></p>
      <p id="ewshSumCost"></p>
     </div>

   <?php /* The customise step. Same card, same way back as the other
            steps -- the shirt turns round on it and shows the name and
            the number as they are typed, at the same place the picker
            on the home page puts them. */ ?>
   <div class="ewsh-prt" hidden>
    <?php if ( '' !== trim( (string) $a['print_price'] ) ) : ?>
     <p class="ewsh-ptitle"><?php echo esc_html( $a['print_title'] ); ?></p>
     <div class="ewsh-pmodes" id="ewshPModes" role="group" aria-label="<?php echo esc_attr( $a['print_title'] ); ?>">
      <button type="button" data-m="player" aria-pressed="false"><?php echo esc_html( $a['print_players'] ); ?></button>
      <button type="button" data-m="custom" aria-pressed="false"><?php echo esc_html( $a['print_custom'] ); ?></button>
      <button type="button" data-m="none" class="on" aria-pressed="true"><?php echo esc_html( $a['print_none'] ); ?></button>
     </div>
     <div class="ewsh-pwho" id="ewshPWho" hidden>
      <select class="ewsh-email" id="ewshPPick" aria-label="<?php echo esc_attr( $a['print_pick'] ); ?>"></select>
     </div>
     <div class="ewsh-pfields" id="ewshPFields" hidden>
      <div class="ewsh-pf">
       <label class="ewsh-flab" for="ewshPName"><?php echo esc_html( $a['print_name'] ); ?></label>
       <input class="ewsh-email ewsh-pname" id="ewshPName" type="text" maxlength="<?php echo esc_attr( max( 1, (int) $a['print_max'] ) ); ?>" autocomplete="off">
       <p class="ewsh-fnote" id="ewshPCount"></p>
      </div>
      <div class="ewsh-pf ewsh-pf-num">
       <label class="ewsh-flab" for="ewshPNum"><?php echo esc_html( $a['print_num'] ); ?></label>
       <input class="ewsh-email" id="ewshPNum" type="text" maxlength="2" inputmode="numeric" autocomplete="off">
       <p class="ewsh-fnote"><?php echo esc_html( $a['print_range'] ); ?></p>
      </div>
     </div>
     <p class="ewsh-pwarn" id="ewshPWarn" hidden><?php echo esc_html( $a['print_warn'] ); ?></p>
    <p class="ewsh-plead" id="ewshPLead" hidden><?php echo esc_html( $a['print_lead'] ); ?></p>
    <button type="button" class="ewsh-go" id="ewshPDone"></button>
    <?php endif; ?>
   </div>

   <?php /* Step two. Collect or post, and the email, before anybody is
            asked for a card -- so the email is ours whether or not they
            go through with it. The address is not here on purpose: Stripe
            collects that on the next step, where it autocompletes for
            free and a wallet fills it in without typing. */ ?>
   <div class="ewsh-det" hidden>
    <p class="ewsh-lab"><?php echo esc_html( $a['det_title'] ); ?></p>

    <label class="ewsh-flab" for="ewshEmail"><?php echo esc_html( $a['email_label'] ); ?></label>
    <input class="ewsh-email" id="ewshEmail" type="email" autocomplete="email" inputmode="email">
    <p class="ewsh-fnote"><?php echo esc_html( $a['email_note'] ); ?></p>

    <label class="ewsh-check">
     <input type="checkbox" id="ewshConsent">
     <span><?php echo esc_html( $a['consent'] ); ?></span>
    </label>

    <p class="ewsh-lab ewsh-sub-lab"><?php echo esc_html( $a['ful_label'] ); ?></p>
    <div class="ewsh-ful" id="ewshFul"></div>

    <button type="button" class="ewsh-go ewsh-cont"><?php echo esc_html( $a['cont'] ); ?></button>
    <p class="ewsh-err" id="ewshErr"></p>
   </div>

   <div class="ewsh-co" hidden>
    <div class="ewsh-mount"></div>
    <p class="ewsh-msg"></p>
   </div>
    </div>
   </div>
  </div>
 </div>
</div>
	<?php
	return trim( ob_get_clean() );
}
add_shortcode( 'eastwood_shirt', 'ew_shirt_shortcode' );

/* --- What the two pages wear and how the product page behaves -------- */

/**
 * The shop index stylesheet.
 *
 * Word for word what was in the page's own style tag before it became a
 * block, minus the h1 and the wrapper, which the page no longer draws: the
 * title is a heading widget now and gets the red band from the sheet that
 * gives every other page its band.
 */
function ew_shop_css() {
	return /* The row holding the title, the line under it and the grid is laid out
   in normal flow, so the margins between them collapse the way they did
   when the three were one piece of HTML. */
		'.e-con.ew-shoprow{display:block}'
		/* The title band. The sheet that gives every page its band sets a
		   line-height of 1 and no bottom margin; this page's own markup used
		   to add 1.05 and 34px, which is three pixels of red and the gap
		   under the band. Measured off the page as it was, not guessed. */
		. '.ew-shoprow .elementor-widget-heading h1.elementor-heading-title'
		. '{line-height:1.05;margin:0 calc(50% - 50vw) 34px !important}'
		/* The line under the title is a text widget now, so it needs the
		   column gutter the wrapper used to give it.
		
		   15px, not the 17px the plugin's own sheet asks for: two rules set
		   this paragraph and the page's own came last, so 15px is what it has
		   always been. Its line-height was 1.6 inherited as a number from the
		   wrapper, which on 15px text is 24, and its margin 30. Those three
		   numbers are the seven pixels the page was out by. */
		. '.elementor-widget-text-editor > p.lede{font-size:15px;line-height:1.6;'
		. 'color:#5c5c5c;margin:0 0 30px;'
		. 'padding-left:max(16px,calc(50vw - 624px));'
		. 'padding-right:max(16px,calc(50vw - 624px))}'
		. '.ewshop{max-width:1120px;margin:0 auto;padding:40px 20px 70px; font:400 16px/1.6 Inter,-apple-system,system-ui,sans-serif;color:#111} .ewshop h1{font-size:40px;line-height:1.05;margin:0 0 6px;letter-spacing:.3px} .ewshop .lede{color:#5c5c5c;margin:0 0 30px;font-size:15px} .ewshop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px} .ewshop-card{border:1px solid #e4e4e7;border-radius:6px;overflow:hidden;background:#fff; text-decoration:none;color:inherit;display:block;transition:border-color .15s} .ewshop-card:hover{border-color:#CC0000} .ewshop-pic{background:#CC0000;height:190px;display:flex;align-items:center;justify-content:center} .ewshop-pic svg{width:120px;height:auto} .ewshop-body{padding:16px 18px 20px} .ewshop-body h2{font-size:17px;margin:0 0 4px;line-height:1.3} .ewshop-body .p{font-weight:700;margin:0 0 6px} .ewshop-body .m{font-size:13px;color:#6a6a6a;margin:0}';
}

/**
 * The product page stylesheet, as it was in the code box, with one
 * correction Ben asked for.
 *
 * The sheet styled .ewsh-info h2 and the page has always had an h1, so
 * the product title had never once been drawn in Anton -- it came out in
 * whatever the browser uses by default, among a page of club headings.
 * The selector takes both now.
 */
function ew_shirt_css() {
	return /* The section this page used to sit in held the content 30px in
		   from each edge on a phone. The row it sits in now does not, which
		   made the shirt bigger and the text column narrower -- eleven
		   pixels of difference that were really two larger ones cancelling.
		   Measured at 390: with this the page is the height it was. */
		'@media(max-width:767px){.ewsh{padding-left:30px;padding-right:30px}}'
		. '.ewsh{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010}.ewsh-wrap{display:flex;gap:52px;align-items:flex-start;max-width:1140px;margin:0 auto}.ewsh-gal{flex:0 0 470px;background:#f2f2f2;border-radius:12px;padding:26px 36px 20px}.ewsh-info{flex:1 1 auto;min-width:0}.ewsh-info h1,.ewsh-info h2{font-family:Anton,Impact,sans-serif;font-weight:400;font-size:42px;line-height:1.03;letter-spacing:.5px;text-transform:uppercase;margin:0 0 6px;color:#101010}.ewsh-sub{font-size:14px;color:#777;margin:0 0 22px}.ewsh-price{font-family:Anton,Impact,sans-serif;font-size:38px;margin:0 0 4px;color:#101010}.ewsh-note{font-size:13px;color:#8a8a8a;margin:0 0 26px}.ewsh-lab{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin:0 0 10px}.ewsh-sizes{display:flex;gap:9px;flex-wrap:wrap;margin:0 0 26px}.ewsh-sizes button{min-width:62px;padding:13px 6px;background:#fff;border:1.5px solid #d6d6d6;border-radius:6px;font:inherit;font-size:14px;font-weight:600;cursor:pointer}.ewsh-sizes button.on{border-color:#101010;background:#101010;color:#fff}.ewsh-sizes button[disabled]{color:#c0c0c0;border-style:dashed;cursor:not-allowed;text-decoration:line-through}.ewsh-ful{display:flex;flex-direction:column;gap:10px;margin:0 0 26px}.ewsh-ful label{display:flex;gap:12px;align-items:flex-start;border:1.5px solid #d6d6d6;border-radius:8px;padding:15px 16px;cursor:pointer}.ewsh-ful label.on{border-color:#101010}.ewsh-ful b{display:block;font-size:15px;margin-bottom:3px}.ewsh-ful span span{display:block;font-size:13px;color:#777;line-height:1.45}.ewsh-pay{border:1.5px solid #e2e2e2;border-radius:10px;padding:22px;background:#fafafa}.ewsh-pay p{margin:0;font-size:14px;line-height:1.6;color:#666}.ewsh-pay b{display:block;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#101010;margin-bottom:6px}@media(max-width:900px){.ewsh-wrap{flex-direction:column;gap:26px}.ewsh-gal{flex:1 1 auto;width:100%}} .ewsh-fits{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px} .ewsh-fits button{font:700 13px/1 Inter,sans-serif;letter-spacing:.4px;padding:11px 18px; border:1.5px solid #d8d8dc;background:#fff;color:#111;border-radius:4px;cursor:pointer} .ewsh-fits button.on{border-color:#CC0000;background:#CC0000;color:#fff} .ewsh-sizes button[disabled]{opacity:.4;text-decoration:line-through;cursor:not-allowed} .ewsh-sizes button.on{border-color:#CC0000;background:#CC0000;color:#fff}      .ewsh-go{margin:0;width:100%;background:#CC0000;color:#fff;border:0;border-radius:6px;font:700 15px/1 Inter,sans-serif;letter-spacing:.4px;padding:17px 20px;cursor:pointer}      .ewsh-go:not([disabled]):hover{background:#b30000}      .ewsh-go[disabled]{background:#e6e6e8;color:#9b9b9f;cursor:not-allowed}      .ewsh-small{margin:12px 0 0;font-size:13px;color:#777;line-height:1.5}      .ewsh-co{margin:0}      .ewsh-mount{background:#fff;border-radius:6px;min-height:40px}      .ewsh-mount:empty{display:none}      .ewsh-msg{margin:10px 0 0;font-size:13px;color:#777}      .ewsh-thanks{border:1.5px solid #CC0000;border-radius:8px;padding:16px 18px;margin:0 0 22px;font-size:15px;color:#101010}      .ewsh-head{margin:0 0 14px}      .ewsh-head h2{margin:0}      .ewsh-back{margin:7px 0 0;font-size:13px;color:#777}      .ewsh-back button{background:none;border:0;padding:0;font:inherit;color:#777;cursor:pointer;text-decoration:underline}      .ewsh-back button:hover{color:#CC0000}      .ewsh-card{background:#fff;border:1.5px solid #e2e2e2;border-radius:10px;overflow:hidden}      .ewsh-sum{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 18px;border-bottom:1px solid #ececec}      .ewsh-sum p{margin:0;font-size:14px;color:#555}      .ewsh-sum p+p{font-family:Anton,Impact,sans-serif;font-size:24px;color:#101010;white-space:nowrap}      .ewsh-det{margin:0;padding:22px;background:#fafafa}      .ewsh-det .ewsh-ful{margin:0 0 18px}      .ewsh-sub-lab{margin-top:4px}      .ewsh-flab{display:block;font-size:13px;font-weight:600;margin:0 0 6px}      .ewsh-flab2{margin-top:14px}      .ewsh-email{width:100%;box-sizing:border-box;border:1.5px solid #d6d6d6;border-radius:6px;padding:13px 14px;font:400 15px "Instrument Sans",system-ui,sans-serif;color:#101010;background:#fff}      .ewsh-email:focus{outline:none;border-color:#101010}      .ewsh-fnote{margin:7px 0 0;font-size:12px;color:#777}      .ewsh-check{display:flex;align-items:center;gap:9px;margin:16px 0 20px;font-size:13px;color:#555;cursor:pointer}      .ewsh-cont{margin:0}      .ewsh-err{margin:10px 0 0;font-size:13px;color:#CC0000;font-weight:600}      .ewsh-err:empty{display:none}      .ewsh [hidden]{display:none}      .ewsh-stock{margin:0 0 24px;font-size:14px;font-weight:600;color:#1a7f4b}      .ewsh-stock:empty{display:none}      .ewsh-stock.out{color:#8a8a8a}      .ewsh-price{margin-bottom:20px}      .ewsh-rule{height:1px;background:#e2e2e2;margin:0 0 20px}      .ewsh-sizerow{display:flex;justify-content:space-between;align-items:baseline;gap:16px}      .ewsh-chart{font-size:13px;color:#555}      .ewsh-chart:hover{color:#CC0000}      .ewsh-share{display:flex;align-items:center;gap:14px;margin:26px 0 0;padding-top:18px;border-top:1px solid #e2e2e2;color:#555}      .ewsh-share span{font-size:13px;color:#777}      .ewsh-share a{color:#555;display:flex}      .ewsh-share a:hover{color:#CC0000}      .ewsh-order{max-width:460px}      .ewsh-print{margin:0 0 14px}      .ewsh-print.on{background:#fff;border:1.5px solid #e2e2e2;border-radius:8px;padding:16px 18px}      .ewsh-pbtn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;background:#101010;border:0;border-radius:6px;padding:17px 20px;font:700 13px/1 Inter,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:#fff;cursor:pointer}      .ewsh-pbtn:hover{background:#2a2a2a}      .ewsh-print.on .ewsh-pbtn{background:#101010;color:#fff}      .ewsh-pfields{display:flex;gap:12px;margin:16px 0 0}      .ewsh-pf{flex:1 1 auto;min-width:0}      .ewsh-pf-num{flex:0 0 110px}      .ewsh-pname{letter-spacing:.08em;text-transform:uppercase;font-weight:600}      .ewsh-pwho{margin:16px 0 0}      .ewsh-pwho .ewsh-pf{flex:1 1 auto}      select.ewsh-email{padding-right:12px}      .ewsh-pwarn{margin:14px 0 0;font-size:13px;color:#777}      .ewsh-pay{border:0;padding:0;background:none}      .ewsh-ptitle{margin:0 0 10px;font-size:16px;font-weight:700;color:#101010}      .ewsh-pmodes{display:flex;gap:10px}      .ewsh-pmodes button{flex:1 1 0;min-width:0;background:#fff;color:#101010;border:1px solid #8a8a8a;border-radius:0;padding:16px 6px;font:700 15px/1 "Instrument Sans",system-ui,sans-serif;cursor:pointer}      .ewsh-pmodes button:hover{background:#f2f2f2;border-color:#101010}      .ewsh-pmodes button.on,.ewsh-pmodes button.on:hover{background:#000;border-color:#000;color:#fff}      .ewsh-pwho{margin:12px 0 0}      .ewsh-pwho select.ewsh-email{border-color:#8a8a8a;padding:15px 14px}      .ewsh-pay .ewsh-go{width:100%;font-size:15px;padding:19px 20px}      .ewsh-prt{margin:0;padding:22px;background:#fafafa}      .ewsh-printcta{margin:0 0 14px}      .ewsh-pchosen{margin:10px 0 0;font-size:13px;color:#777}      .ewsh-pchosen:empty{display:none}      .ewsh-prev{position:relative;width:100%;margin:0}      .ewsh-galface img{display:block;width:100%;height:auto}      .ewsh-prev img{display:block;width:100%;height:auto}      .ewsh-prev b,.ewsh-prev i{position:absolute;left:0;right:0;text-align:center;-webkit-text-fill-color:#fff;color:#fff;-webkit-text-stroke:3.9px rgba(40,0,6,.9);paint-order:stroke;font-family:Oswald,"Instrument Sans",sans-serif;font-weight:700}      .ewsh-prev b{top:17%;font-size:47px;line-height:34px;text-transform:uppercase}      .ewsh-prev i{top:36%;font-style:normal;font-size:174px;line-height:127px}      .ewsh-plead{margin:12px 0 0;font-size:13px;color:#777}      .ewsh-prt .ewsh-go{margin:18px 0 0}';
}

/**
 * The product page's behaviour.
 *
 * Unchanged from the code box except in one respect: every word it used to
 * carry is now read off the block, so changing "Collect at the ground" is
 * an edit in Elementor rather than an edit here.
 */
function ew_shirt_js() {
	return <<<'JS'
/* Stripe renamed initEmbeddedCheckout to createEmbeddedCheckoutPage. Ask for
   whichever this browser's copy of Stripe.js actually has, so the page keeps
   working either side of that change. */
function ewStripeCheckout(stripe, opts){
 var make = stripe.createEmbeddedCheckoutPage || stripe.initEmbeddedCheckout;
 return make.call(stripe, opts);
}
(function(){
 var EL = document.querySelector('.ewsh');
 if(!EL) return;
 var W;
 try { W = JSON.parse(EL.getAttribute('data-shirt') || '{}'); } catch(e){ return; }
 if(!W.fits || !W.fits.length) return;

 var API = '/wp-json/eastwood/v1/shop/item';
 var NAMES = {}, ORDER = [], SKUS = {};
 W.fits.forEach(function(f){
  NAMES[f.code] = f.name; ORDER.push(f.code);
  (SKUS[f.sku] = SKUS[f.sku] || []).push(f.code);
 });

 var model = {}, fit = null, size = null, post = false, checkout = null, stage = 'pick';
 var P = W.print || null, printOn = false;
 var $ = function(id){ return document.getElementById(id); };
 function money(n){ return '\u00a3' + Number(n).toFixed(2); }
 function printCost(){ return (P && printOn) ? Number(P.price) : 0; }
 function printName(){ var e = $('ewshPName'); return e ? (e.value || '').trim().toUpperCase() : ''; }
 function printNum(){ var e = $('ewshPNum'); return e ? (e.value || '').replace(/[^0-9]/g, '') : ''; }

 function load(){
  var jobs = Object.keys(SKUS).map(function(sku){
   return fetch(API + '?' + 'sku=' + encodeURIComponent(sku))
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){ return d ? {fits:SKUS[sku], data:d} : null; });
  });
  Promise.all(jobs).then(function(res){
   res.forEach(function(r){
    if(!r) return;
    r.fits.forEach(function(f){
     var sizes = (r.data.sizes||[]).filter(function(x){ return x.size.indexOf(f + ' ') === 0; })
       .map(function(x){ return {size:x.size, label:x.size.slice(f.length+1), stock:x.in_stock}; });
     if(sizes.length){
      model[f] = {sku:r.data.sku, price:r.data.price, postage:r.data.postage, sizes:sizes};
     }
    });
   });
   var have = ORDER.filter(function(f){ return model[f]; });
   if(!have.length){ $('ewshNote').textContent = W.empty; return; }
   fit = have[0];
   $('ewshNote').textContent = '';
   drawFits(have); drawSizes(); drawFul(); drawPay();
  }).catch(function(){ $('ewshNote').textContent = W.failed; });
 }

 function drawFits(have){
  var w = $('ewshFits'); w.innerHTML = '';
  have.forEach(function(f){
   var b = document.createElement('button');
   b.type = 'button'; b.textContent = NAMES[f] || f;
   if(f === fit) b.className = 'on';
   b.addEventListener('click', function(){ fit = f; size = null; post = false;
     drawFits(have); drawSizes(); drawFul(); drawPay(); });
   w.appendChild(b);
  });
  $('ewshPrice').textContent = money(model[fit].price);
  var any = model[fit].sizes.some(function(x){ return x.stock; });
  var st = $('ewshStock');
  st.textContent = any ? W.instock : W.soldout;
  st.className = 'ewsh-stock' + (any ? '' : ' out');
 }

 function drawSizes(){
  var w = $('ewshSizes'); w.innerHTML = '';
  model[fit].sizes.forEach(function(s){
   var b = document.createElement('button');
   b.type = 'button'; b.textContent = s.label;
   if(!s.stock) b.disabled = true;
   if(s.size === size) b.className = 'on';
   b.addEventListener('click', function(){ size = s.size; drawSizes(); drawPay(); });
   w.appendChild(b);
  });
  var lab = $('ewshSizeLab'), pick = size ? size.slice(fit.length + 1) : '';
  lab.textContent = pick ? (W.sizelab + ' \u00b7 ' + pick) : W.sizelab;
 }

 function drawFul(){
  var w = $('ewshFul'), m = model[fit]; w.innerHTML = '';
  var opts = [{k:false, t:W.collect[0], d:W.collect[1]}];
  if(Number(m.postage) > 0){
   opts.push({k:true, t:W.post[0], d:String(W.post[1]).replace('{price}', money(m.postage))});
  }
  opts.forEach(function(o){
   var l = document.createElement('label');
   if(o.k === post) l.className = 'on';
   var b = document.createElement('b'); b.textContent = o.t;
   var s = document.createElement('span'); s.textContent = o.d;
   l.appendChild(b); l.appendChild(s);
   l.addEventListener('click', function(){ post = o.k; drawFul(); closePay(); });
   w.appendChild(l);
  });
 }

 /* The shirt page's own button. It does not pay for anything -- it opens
    the details step underneath. */
 function drawPay(){
  var pay = $('ewshPay');
  closePay();
  if(!pay.firstChild){
   var btn = document.createElement('button');
   btn.type = 'button'; btn.className = 'ewsh-go'; btn.textContent = W.paybtn;
   btn.addEventListener('click', openDetails);
   pay.appendChild(btn);
  }
  pay.querySelector('.ewsh-go').disabled = !size;
 }

 /* One step on screen at a time. The shirt, the price and the size
    buttons stay put above; what changes underneath them is the single
    panel for whichever step they are on. */
 function step(s){
  stage = s;
  var later = (s !== 'pick');
  EL.querySelector('.ewsh-pick').hidden  = later;
  EL.querySelector('.ewsh-order').hidden = !later;
  EL.querySelector('.ewsh-det').hidden   = (s !== 'details');
  EL.querySelector('.ewsh-co').hidden    = (s !== 'pay');
  var prt = EL.querySelector('.ewsh-prt');
  if(prt){ prt.hidden = (s !== 'print'); }
  /* The shirt in the picture column turns round for the customise step. */
  var gf = $('ewshGalFront'), gb = $('ewshGalBack');
  if(gf && gb){ gf.hidden = (s === 'print'); gb.hidden = (s !== 'print'); }
  /* No price bar over the customise step: nothing has been agreed yet. */
  var sum = EL.querySelector('.ewsh-sum');
  if(sum){ sum.hidden = (s === 'print'); }
  var ttl = $('ewshOrderTitle');
  if(ttl){ ttl.textContent = (s === 'print' && P) ? P.head : W.ordtitle; }
  if(later){ drawSum(); }
  $('ewshBack').textContent = (s === 'pay') ? W.backdet : W.backpick;
  EL.scrollIntoView({behavior:'smooth', block:'start'});
 }

 /* What they are buying, in one line, standing in for the chooser. */
 /* On the details step this is the whole order, because nothing else on
    screen says it. On the card step Stripe is right underneath listing
    the shirt, the size and the price, so all that is left to say is the
    one thing Stripe does not: whether it is collected or posted. */
 function drawSum(){
  var m = model[fit], what, cost = '';
  if(stage === 'pay'){
   what = post ? W.post[0] : W.collect[0];
  } else if(stage === 'print'){
   what = W.title + (size ? ' \u00b7 ' + size.toUpperCase() : '');
   cost = printOn ? money(printCost()) : '';
  } else {
   what = W.title + ' \u00b7 ' + (size || '').toUpperCase() + ', ' + (post ? W.sumpost : W.sumcol);
   if(printOn){
    what += ' \u00b7 ' + [printName(), printNum()].filter(Boolean).join(' ');
   }
   cost = money(Number(m.price) + (post ? Number(m.postage) : 0) + printCost());
  }
  $('ewshSumWhat').textContent = what;
  $('ewshSumCost').textContent = cost;
 }

 function wirePrint(){
  if(!P) return;
  var modes = $('ewshPModes'), fields = $('ewshPFields'),
      warn = $('ewshPWarn'), nm = $('ewshPName'), nu = $('ewshPNum'),
      squad = W.squad || [], pick = $('ewshPPick'), who = $('ewshPWho'), mode = 'none';
  if(!modes) return;
  var btns = Array.prototype.slice.call(modes.querySelectorAll('button'));

  function count(){
   $('ewshPCount').textContent = String(P.chars)
     .replace('{n}', printName().length).replace('{max}', P.max);
  }

  /* The shirt turns round and wears whatever has been typed. */
  function preview(){
   var n = $('ewshPvName'), u = $('ewshPvNum');
   if(n){ n.textContent = printOn ? printName() : ''; }
   if(u){ u.textContent = printOn ? printNum() : ''; }
  }

  /* What saying Done is going to cost, said on the button itself. */
  function doneLabel(){
   var b = $('ewshPDone'), lead = $('ewshPLead');
   if(lead){ lead.hidden = !(printOn && String(P.lead || '').length); }
   if(!b){ return; }
   var add = printOn ? String(P.add || '').replace('{price}', money(P.price)) : '';
   b.textContent = add ? (P.done + ' (' + add + ')') : P.done;
  }

  /* Back on the shirt, one line saying what is going on the back of it. */
  function chosen(){
   var p = $('ewshPChosen');
   if(!p){ return; }
   var what = [printName(), printNum()].filter(Boolean).join(' ');
   p.textContent = (printOn && what) ? String(P.chosen || '{what}').replace('{what}', what) : '';
  }

  /* Three answers to one question. Players: pick from the squad and the
     surname and number are his, nothing to type. Custom: your own name
     and number. None: no printing, nothing charged. */
  function setMode(m){
   mode = m;
   printOn = 'none' !== m;
   btns.forEach(function(b){
    var on = b.getAttribute('data-m') === m;
    b.className = on ? 'on' : '';
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
   });
   who.hidden = 'player' !== m;
   fields.hidden = 'custom' !== m;
   warn.hidden = !printOn;
   /* Changing the answer clears what the last one filled in, so nothing
      is printed or charged that is not on the page any more. */
   nm.value = ''; nu.value = ''; if(pick){ pick.value = ''; }
   count(); preview(); doneLabel(); chosen(); closePay(); drawPay();
   if('player' === m && pick){ pick.focus(); }
   if('custom' === m){ nm.focus(); }
  }

  if(squad.length && pick){
   var first = document.createElement('option');
   first.value = ''; first.textContent = W.pickLab || W.mineLab;
   pick.appendChild(first);
   squad.forEach(function(pl, i){
    var o = document.createElement('option');
    o.value = String(i);
    o.textContent = pl.n + ' · ' + pl.w;
    pick.appendChild(o);
   });
   pick.addEventListener('change', function(){
    var pl = '' === pick.value ? null : squad[Number(pick.value)];
    if(pl){
     var last = pl.w.split(' ').pop().toUpperCase().replace(/[^A-Z '-]/g, '');
     nm.value = last.slice(0, P.max);
     nu.value = pl.n;
    } else {
     nm.value = ''; nu.value = '';
    }
    count(); preview(); doneLabel(); chosen(); closePay(); drawPay();
   });
  } else {
   /* No squad on the block: there is nothing to pick from. */
   btns.forEach(function(b){ if('player' === b.getAttribute('data-m')){ b.hidden = true; } });
  }
  btns.forEach(function(b){
   b.addEventListener('click', function(){ setMode(b.getAttribute('data-m')); });
  });
  /* Letters, spaces, hyphens and apostrophes only -- it has to be
     cuttable in vinyl. */
  nm.addEventListener('input', function(){
   var v = nm.value.toUpperCase().replace(/[^A-Z '-]/g, '').slice(0, P.max);
   if(v !== nm.value){ nm.value = v; }
   count(); preview(); chosen(); closePay(); drawPay();
  });
  nu.addEventListener('input', function(){
   var v = nu.value.replace(/[^0-9]/g, '').slice(0, 2);
   if('0' === v){ v = ''; }
   if(v !== nu.value){ nu.value = v; }
   preview(); chosen(); closePay(); drawPay();
  });
  var open = $('ewshPrintOpen'), done = $('ewshPDone');
  if(open){ open.addEventListener('click', function(){ step('print'); }); }
  if(done){ done.addEventListener('click', function(){ chosen(); step('pick'); }); }
  wirePrint.mode = function(){ return mode; };
  count(); preview(); doneLabel(); chosen();
 }

 function openDetails(){
  step('details');
  var e = $('ewshEmail'); if(e) e.focus();
 }

 /* Any change of fit, size or delivery throws an open payment form away:
    it was priced for the old choice and Stripe will not re-price it. */
 function closePay(){
  var co = EL.querySelector('.ewsh-co');
  if(!co) return;
  if(checkout){ checkout.destroy(); checkout = null; }
  EL.querySelector('.ewsh-mount').innerHTML = '';
  EL.querySelector('.ewsh-msg').textContent = '';
  /* Priced for the old choice, so it goes -- back to the details they
     had already filled in, not back to the start. */
  if(stage === 'pay'){ step('details'); }
 }

 function startPay(){
  var co = EL.querySelector('.ewsh-co'),
      mount = EL.querySelector('.ewsh-mount'),
      msg = EL.querySelector('.ewsh-msg'),
      err = $('ewshErr'),
      email = ($('ewshEmail').value || '').trim(),
      consent = $('ewshConsent').checked;

  if(!size){ return; }
  if(email.indexOf('@') < 1 || email.indexOf('.', email.indexOf('@')) < 0){
   err.textContent = W.emailbad;
   $('ewshEmail').focus();
   return;
  }
  if(printOn && !printName() && !printNum()){
   err.textContent = P.bad;
   step('pick');
   var pk = $('ewshPPick');
   ((wirePrint.mode && 'player' === wirePrint.mode() && pk) ? pk : $('ewshPName')).focus();
   return;
  }
  err.textContent = '';

  step('pay');
  if(!W.pk || !window.Stripe){ msg.textContent = W.off; return; }
  if(checkout) return;
  msg.textContent = W.loading;
  var stripe = window.Stripe(W.pk), m = model[fit];
  /* The browser says which shirt, which size, whether it is posted and who
     to send the receipt to. What any of it costs is read again on the
     server. */
  ewStripeCheckout(stripe, {fetchClientSecret: function(){
   return fetch(W.rest, {method:'POST', headers:{'Content-Type':'application/json'},
     body: JSON.stringify({sku: m.sku, size: size, post: post, email: email, consent: consent, page: W.page,
       print: printOn, pname: printName(), pnum: printNum()})})
    .then(function(r){ return r.json(); })
    .then(function(j){ if(!j.clientSecret) throw new Error(j.message || 'error');
      return j.clientSecret; });
  }}).then(function(c){ checkout = c; msg.textContent = ''; c.mount(mount); })
    .catch(function(e){ msg.textContent = (e && e.message) || W.off; });
 }

 EL.addEventListener('click', function(e){
  if(e.target.closest('.ewsh-cont')){ startPay(); return; }
  if(e.target.closest('#ewshBack')){
   if(stage === 'pay'){ closePay(); } else { step('pick'); }
  }
 });

 wirePrint();
 load();
})();
JS;
}

/**
 * Print each sheet only on the page that holds its block.
 *
 * Gated on the block being in the page's content or its Elementor data,
 * through the one helper every other gate here uses, so a page converted
 * from a shortcode in the content to a block in the builder keeps its
 * styling either way.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}

	if ( ew_post_contains( $post, 'eastwood_shop' ) ) {
		wp_register_style( 'ew-shop', false, array(), EW_FWP_VERSION );
		wp_enqueue_style( 'ew-shop' );
		wp_add_inline_style( 'ew-shop', ew_shop_css() );
	}

	if ( ew_post_contains( $post, 'eastwood_shirt' ) ) {
		wp_register_style( 'ew-shirt', false, array(), EW_FWP_VERSION );
		wp_enqueue_style( 'ew-shirt' );
		wp_add_inline_style( 'ew-shirt', ew_shirt_css() );
		// Oswald is the printing on the shirt, same as the home picker.
		wp_enqueue_style( 'ew-shirt-oswald', 'https://fonts.googleapis.com/css2?family=Oswald:wght@700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		$ew_shirt_deps = array();
		if ( function_exists( 'ew_stripe_creds' ) && '' !== (string) ( ew_stripe_creds()['publishable'] ?? '' ) ) {
			wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			$ew_shirt_deps[] = 'stripe-js';
		}
		wp_register_script( 'ew-shirt', '', $ew_shirt_deps, EW_FWP_VERSION, true );
		wp_enqueue_script( 'ew-shirt' );
		wp_add_inline_script( 'ew-shirt', ew_shirt_js() );
	}
}, 22 );

/* --- Paying for a shirt ---------------------------------------------- */

/**
 * Card payment for one shirt, through Stripe's own form on the page.
 *
 * The browser sends only the SKU, the size and whether it is being
 * posted. The price, the postage and whether that size is actually in
 * stock are read again here from the same shop endpoint the page reads,
 * so a basket edited in the console cannot set its own price or buy
 * something that has sold out.
 *
 * Shirt money is Eastwood FC Ventures, so it uses ew_stripe_creds() --
 * the same keys as the event tickets.
 */

/** The shirt block on a page, so the endpoint can read its fields. */
function ew_shirt_block_on( $post ) {
	$found = null;
	$data  = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
	if ( is_array( $data ) ) {
		$walk = function ( $nodes ) use ( &$walk, &$found ) {
			foreach ( (array) $nodes as $n ) {
				if ( $found ) {
					return;
				}
				$sc = (string) ( $n['settings']['shortcode'] ?? '' );
				if ( 0 === strpos( ltrim( $sc ), '[eastwood_shirt' ) ) {
					$found = ew_blocks_parse( $sc );
					return;
				}
				if ( ! empty( $n['elements'] ) ) {
					$walk( $n['elements'] );
				}
			}
		};
		$walk( $data );
	}
	if ( ! $found && preg_match( '/\[eastwood_shirt[^\]]*\]/', (string) $post->post_content, $m ) ) {
		$found = ew_blocks_parse( $m[0] . '[/eastwood_shirt]' );
	}
	return $found;
}

/* --- The order, written down before the card is asked for ------------- */

/**
 * A shirt order is the same shape as a ticket booking -- somebody's name
 * and email, what they are buying, what it costs -- so it is written into
 * the same Bookings list rather than a second one beside it. One admin
 * screen, one CSV, and the CRM hears about shirts the way it hears about
 * tickets.
 *
 * Everything here is guarded: if the tickets part is ever not loaded, the
 * shirt still sells, it just goes unrecorded.
 */
function ew_shirt_booking( $post, $what, $name, $email, $lines, $pence, $hook, $greeting = '' ) {
	if ( ! function_exists( 'ew_tix_booking_start' ) ) {
		return 0;
	}
	return (int) ew_tix_booking_start( $post, $what, $name, $email, $lines, $pence, $hook, $greeting );
}

/**
 * Back from Stripe: check the payment really happened, mark the Booking
 * paid and tell the CRM. Same two steps the tickets block takes, because
 * a record stuck on "started" forever is worse than no record.
 */
function ew_shirt_mark_paid( $session_id, $hook ) {
	if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $session_id )
		|| ! function_exists( 'ew_tix_stripe' ) || ! function_exists( 'ew_tix_booking_for' ) ) {
		return;
	}
	$s = ew_tix_stripe( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	if ( empty( $s['id'] ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) {
		return;
	}
	$bid = ew_tix_booking_for( $s );
	if ( ! $bid || 'paid' === get_post_meta( $bid, '_ewt_status', true ) ) {
		return;
	}
	update_post_meta( $bid, '_ewt_status', 'paid' );
	update_post_meta( $bid, '_ewt_paid', (int) ( $s['amount_total'] ?? 0 ) );
	update_post_meta( $bid, '_ewt_session', $s['id'] );
	$who = trim( (string) ( $s['customer_details']['name'] ?? '' ) );
	if ( '' !== $who ) {
		update_post_meta( $bid, '_ewt_name', $who );
		wp_update_post( array( 'ID' => $bid, 'post_title' => $who ) );
	}
	if ( function_exists( 'ew_tix_crm' ) ) {
		ew_tix_crm( $hook, $bid, 'paid' );
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/shirt/checkout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$sk = function_exists( 'ew_stripe_creds' ) ? (string) ( ew_stripe_creds()['secret'] ?? '' ) : '';
			if ( '' === $sk ) {
				return new WP_Error( 'ewsh_off', 'Card payment is not switched on.', array( 'status' => 503 ) );
			}

			$sku  = sanitize_text_field( (string) $req->get_param( 'sku' ) );
			$size = sanitize_text_field( (string) $req->get_param( 'size' ) );
			$post    = (bool) $req->get_param( 'post' );
			$email   = sanitize_email( (string) $req->get_param( 'email' ) );
			$want    = (bool) $req->get_param( 'print' );
			$pname   = strtoupper( trim( preg_replace( "/[^A-Za-z '-]/", '', (string) $req->get_param( 'pname' ) ) ) );
			$pnum    = (string) (int) preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'pnum' ) );
			$pnum    = ( $pnum > 0 && $pnum < 100 ) ? $pnum : '';
			$consent = (bool) $req->get_param( 'consent' );
			if ( '' === $sku || '' === $size ) {
				return new WP_Error( 'ewsh_what', 'Choose a size first.', array( 'status' => 400 ) );
			}
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'ewsh_email', 'We need an email to send your receipt to.', array( 'status' => 400 ) );
			}

			// The same endpoint the page reads, called inside this request
			// rather than over HTTP.
			$look = new WP_REST_Request( 'GET', '/eastwood/v1/shop/item' );
			$look->set_query_params( array( 'sku' => $sku ) );
			$found = rest_do_request( $look );
			if ( $found->is_error() ) {
				return new WP_Error( 'ewsh_item', 'No such shirt.', array( 'status' => 404 ) );
			}
			$item = (array) $found->get_data();

			$in_stock = false;
			foreach ( (array) ( $item['sizes'] ?? array() ) as $row ) {
				if ( (string) ( $row['size'] ?? '' ) === $size ) {
					$in_stock = ! empty( $row['in_stock'] );
					break;
				}
			}
			if ( ! $in_stock ) {
				return new WP_Error( 'ewsh_stock', 'That size has gone.', array( 'status' => 409 ) );
			}

			$pence = (int) round( (float) ( $item['price'] ?? 0 ) * 100 );
			$ship  = (int) round( (float) ( $item['postage'] ?? 0 ) * 100 );
			if ( $pence <= 0 ) {
				return new WP_Error( 'ewsh_price', 'This shirt has no price yet.', array( 'status' => 409 ) );
			}
			$post = $post && $ship > 0;

			// Written down before Stripe is called, so a checkout that never
			// finishes is still somebody the club can reach.
			$page   = get_post( (int) $req->get_param( 'page' ) );
			$hook   = '';
			$promos = false;
			if ( $page ) {
				$block  = ew_shirt_block_on( $page );
				$hook   = (string) ( $block['fields']['crm_hook'] ?? '' );
				$promos = in_array( strtolower( (string) ( $block['fields']['promos'] ?? '' ) ), array( 'yes', 'true', '1', 'on' ), true );
				$hide   = array_values( array_filter( array_map(
					function ( $v ) {
						return preg_replace( '/[^a-z_]/', '', strtolower( trim( $v ) ) );
					},
					explode( ',', (string) ( $block['fields']['hide_pay'] ?? '' ) )
				) ) );
			}
			// What the printing costs is read here, off the block, so the
			// browser cannot talk the price down.
			$pp = 0;
			if ( $want && $page ) {
				$pp = (int) round( (float) ( $block['fields']['print_price'] ?? 0 ) * 100 );
			}
			$pname = $pp > 0 ? substr( $pname, 0, max( 1, (int) ( $block['fields']['print_max'] ?? 12 ) ) ) : '';
			$pnum  = $pp > 0 ? $pnum : '';
			if ( $pp > 0 && '' === $pname && '' === $pnum ) {
				return new WP_Error( 'ewsh_print', 'Give a name or a number to print.', array( 'status' => 400 ) );
			}
			$plabel = trim( $pname . ( '' !== $pname && '' !== $pnum ? ' ' : '' ) . $pnum );

			$booking = $page ? ew_shirt_booking(
				$page,
				trim( (string) ( $item['name'] ?? 'Shirt' ) ),
				'',
				$email,
				trim( $size . ' · ' . ( $post ? 'posted' : 'collecting' )
					. ( $pp > 0 ? ' · printed ' . $plabel : '' ) ),
				$pence + ( $post ? $ship : 0 ) + $pp,
				$hook,
				(string) ( $block['fields']['greeting'] ?? '' )
			) : 0;
			if ( $booking && $consent ) {
				update_post_meta( $booking, '_ewt_consent', 'yes' );
			}

			$back = wp_get_referer();
			$back = $back ? strtok( $back, '?' ) : home_url( '/shop/' );

			$body = array(
				'ui_mode'                          => 'embedded_page',
				'mode'                             => 'payment',
				'return_url'                       => add_query_arg( 'ewsh_paid', '{CHECKOUT_SESSION_ID}', $back ),
				'phone_number_collection[enabled]' => 'true',
				'customer_email'                   => $email,
				'metadata[booking]'                => (string) $booking,
				'metadata[sku]'                    => $sku,
				'metadata[size]'                   => $size,
				'metadata[delivery]'               => $post ? 'post' : 'collect',
				'line_items[0][quantity]'                       => '1',
				'line_items[0][price_data][currency]'           => 'gbp',
				'line_items[0][price_data][unit_amount]'        => (string) $pence,
				'line_items[0][price_data][product_data][name]' => trim( (string) ( $item['name'] ?? 'Shirt' ) . ' · ' . $size ),
			);

			// Ways to pay this block does not want offered. Buy now, pay
			// later on a £35 shirt is the case in point: the credit is
			// Klarna's to give, but the club's name is on the page offering
			// it. Set per product, because what is wrong for a shirt may be
			// right for a £500 hospitality table.
			//
			// Apple Pay, Google Pay and Link cannot be excluded this way and
			// are not meant to be -- they are cards, not credit.
			foreach ( $hide as $k => $method ) {
				$body[ "excluded_payment_method_types[$k]" ] = $method;
			}

			// Stripe's own discount box, off unless the block asks for it.
			// A code promising money off, with nowhere to type it, is worse
			// than no offer; and an empty box invites people to go hunting
			// for a code they never had.
			if ( $promos ) {
				$body['allow_promotion_codes'] = 'true';
			}

			// Printing and postage each go on as their own line, so the
			// receipt breaks the order down rather than showing one figure.
			$line = 1;
			if ( $pp > 0 ) {
				$body[ 'line_items[' . $line . '][quantity]' ]                       = '1';
				$body[ 'line_items[' . $line . '][price_data][currency]' ]           = 'gbp';
				$body[ 'line_items[' . $line . '][price_data][unit_amount]' ]        = (string) $pp;
				$body[ 'line_items[' . $line . '][price_data][product_data][name]' ] = 'Printing · ' . $plabel;
				$body['metadata[printing]']                                          = $plabel;
				++$line;
			}
			if ( $post ) {
				$body['shipping_address_collection[allowed_countries][0]']           = 'GB';
				$body[ 'line_items[' . $line . '][quantity]' ]                       = '1';
				$body[ 'line_items[' . $line . '][price_data][currency]' ]           = 'gbp';
				$body[ 'line_items[' . $line . '][price_data][unit_amount]' ]        = (string) $ship;
				$body[ 'line_items[' . $line . '][price_data][product_data][name]' ] = 'Postage';
			}

			$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $sk,
					'Stripe-Version' => '2026-03-25.dahlia',
				),
				'body'    => $body,
			) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ewsh_stripe', 'Could not reach Stripe.', array( 'status' => 503 ) );
			}
			$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $j['client_secret'] ) ) {
				$msg = (string) ( $j['error']['message'] ?? 'Stripe refused the order.' );
				return new WP_Error( 'ewsh_stripe', $msg, array( 'status' => 400 ) );
			}
			if ( (int) $booking && ! empty( $j['id'] ) ) {
				update_post_meta( (int) $booking, '_ewt_open_session', (string) $j['id'] );
			}
			return array( 'clientSecret' => $j['client_secret'] );
		},
	) );
} );
