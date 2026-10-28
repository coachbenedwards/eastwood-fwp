<?php
/**
 * Academy questions board: the questions sent from /academy/question/,
 * shown big on one page for staff to read out at the end of a session.
 *
 * [eastwood_questions_board]
 *
 * The page is only useful with its key in the address (?k=...), so it can
 * be opened on a phone with no login. Without the key it shows nothing.
 * The key is shown to a logged-in editor at the top of the page itself.
 * Newest question first; the list refreshes itself every 15 seconds.
 *
 * @package eastwood-fwp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_acad_board_key() {
	return substr( wp_hash( 'acad-questions-board' ), 0, 16 );
}

function ew_acad_board_defaults() {
	return array(
		'title'   => 'Questions',
		'empty'   => 'No questions yet. This page refreshes itself.',
		'no_key'  => 'This page needs its private link.',
		'anon'    => 'Anonymous',
		'hours'   => '12',
	);
}

function ew_acad_board_shortcode( $atts ) {
	$a = shortcode_atts( ew_acad_board_defaults(), (array) $atts, 'eastwood_questions_board' );
	$w = array(
		'rest'   => esc_url_raw( rest_url( 'eastwood/v1/academy/questions' ) ),
		'empty'  => $a['empty'],
		'no_key' => $a['no_key'],
		'anon'   => $a['anon'],
		'hours'  => (int) $a['hours'],
	);
	$h = '<section class="ewqb">';
	if ( current_user_can( 'edit_pages' ) ) {
		$url = add_query_arg( 'k', ew_acad_board_key(), get_permalink() );
		$h  .= '<p class="ewqb-admin">Staff link (only editors see this line): <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
	}
	$h .= '<p class="ewqb-count"></p><ol class="ewqb-list"><li class="ewqb-msg">' . esc_html( $a['empty'] ) . '</li></ol>';
	$h .= '<script type="application/json" class="ewqb-words">' . wp_json_encode( $w ) . '</script>';
	return $h . '</section>';
}
add_shortcode( 'eastwood_questions_board', 'ew_acad_board_shortcode' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'eastwood/v1', '/academy/questions', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			nocache_headers();
			$k = (string) $req->get_param( 'k' );
			if ( ! hash_equals( ew_acad_board_key(), $k ) ) {
				return new WP_REST_Response( array( 'message' => 'no' ), 403 );
			}
			$hours = max( 1, min( 168, (int) $req->get_param( 'hours' ) ?: 12 ) );
			$q     = new WP_Query( array(
				'post_type'      => 'ewt_booking',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'date_query'     => array( array( 'after' => $hours . ' hours ago', 'inclusive' => true ) ),
				'meta_query'     => array( array( 'key' => '_ewt_status', 'value' => 'question' ) ),
			) );
			$out = array();
			foreach ( $q->posts as $p ) {
				$out[] = array(
					'time' => get_the_date( 'g:ia', $p ),
					'name' => (string) get_post_meta( $p->ID, '_ewt_name', true ),
					'q'    => (string) get_post_meta( $p->ID, '_ewt_lines', true ),
				);
			}
			return array( 'ok' => 1, 'questions' => $out );
		},
	) );
} );

function ew_acad_board_css() {
	return '
.ewqb{font-family:"Instrument Sans",system-ui,sans-serif;color:#101010;max-width:1248px;margin:0 auto;padding:32px 24px 80px}
.ewqb .ewqb-admin{margin:0 0 20px;padding:12px 16px;background:#fff3cd;border-radius:8px;font-size:14px;word-break:break-all}
.ewqb .ewqb-count{margin:0 0 16px;font-size:15px;color:#666}
.ewqb .ewqb-list{list-style:none;margin:0;padding:0;display:grid;gap:14px}
.ewqb .ewqb-list li{background:#0b0b0b;color:#fff;border-radius:14px;padding:22px 26px}
.ewqb .ewqb-q{margin:0;font-size:24px;line-height:1.4;white-space:pre-wrap}
.ewqb .ewqb-meta{margin:10px 0 0;font-size:14px;color:#9a9aa3}
.ewqb .ewqb-msg{font-size:18px;color:#bdbdbd}
';
}

function ew_acad_board_js() {
	return <<<'JS'
(function(){
 var root = document.querySelector('.ewqb'); if(!root) return;
 var W = JSON.parse(root.querySelector('.ewqb-words').textContent);
 var list = root.querySelector('.ewqb-list'), count = root.querySelector('.ewqb-count');
 var key = (new URLSearchParams(location.search)).get('k') || '';
 function msg(t){ list.innerHTML = ''; var li = document.createElement('li'); li.className = 'ewqb-msg'; li.textContent = t; list.appendChild(li); }
 if(!key){ msg(W.no_key); return; }
 function load(){
   fetch(W.rest + (W.rest.indexOf('?') < 0 ? '?' : '&') + 'k=' + encodeURIComponent(key) + '&hours=' + W.hours, {cache:'no-store'})
   .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
   .then(function(x){
     if(!x.ok){ msg(W.no_key); return; }
     var qs = x.j.questions || [];
     count.textContent = qs.length ? qs.length + (qs.length === 1 ? ' question' : ' questions') : '';
     if(!qs.length){ msg(W.empty); return; }
     list.innerHTML = '';
     qs.forEach(function(q){
       var li = document.createElement('li');
       var p = document.createElement('p'); p.className = 'ewqb-q'; p.textContent = q.q;
       var m = document.createElement('p'); m.className = 'ewqb-meta'; m.textContent = (q.name || W.anon) + ' · ' + q.time;
       li.appendChild(p); li.appendChild(m); list.appendChild(li);
     });
   }).catch(function(){});
 }
 load(); setInterval(load, 15000);
})();
JS;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() || ! function_exists( 'ew_post_contains' ) ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! ew_post_contains( $post, 'eastwood_questions_board' ) ) {
		return;
	}
	wp_register_style( 'ew-qboard', false, array(), EW_FWP_VERSION );
	wp_enqueue_style( 'ew-qboard' );
	wp_add_inline_style( 'ew-qboard', ew_acad_board_css() );
	wp_register_script( 'ew-qboard', '', array(), EW_FWP_VERSION, true );
	wp_enqueue_script( 'ew-qboard' );
	wp_add_inline_script( 'ew-qboard', ew_acad_board_js() );
}, 22 );

/* Keep the private page out of search engines. */
add_action( 'wp_head', function () {
	$post = is_singular() ? get_post() : null;
	if ( $post && function_exists( 'ew_post_contains' ) && ew_post_contains( $post, 'eastwood_questions_board' ) ) {
		echo '<meta name="robots" content="noindex,nofollow">' . "\n";
	}
}, 1 );

add_filter( 'ew_blocks_allowed', function ( $list ) {
	return array_merge( (array) $list, array( 'eastwood_questions_board' ) );
} );
