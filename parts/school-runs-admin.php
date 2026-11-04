<?php
/**
 * School runs — the admin page (wp-admin → School runs).
 *
 * Three tabs, from the delivery-app review Ben picked (4 Nov 2026):
 *   Today     — dispatcher (Onfleet / Circuit): drivers down the left, the
 *               live map in the middle, the school you click on the right.
 *   Timeline  — one row per day, runs as bars against the clock (estimates).
 *   Plan      — days, drivers, which driver has each run, the schools in a
 *               run and their tickets. Same saved plan as the app's editor.
 *
 * The data lives in the app's Supabase project (school_run_plans, drops,
 * positions, runners). Drivers' positions are private to owners/admins, so
 * this page reads them server-side with the project's service key, saved in
 * Settings → Eastwood FWP → School runs (write-only, pasted by Ben). Only
 * WordPress administrators can open the page or call its endpoints.
 *
 * Map: Leaflet with OpenStreetMap tiles (no key needed). A Google Maps browser
 * key can replace it later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ew_runs_key() {
	return (string) get_option( 'ew_runs_sb_key', '' );
}

/** Call the Supabase REST API with the service key. Returns [code, decoded body]. */
function ew_runs_sb( $method, $path, $body = null, $extra_headers = array() ) {
	$key = ew_runs_key();
	if ( '' === $key ) {
		return array( 0, array( 'message' => 'No Supabase key saved.' ) );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array_merge( array(
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		), $extra_headers ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = wp_remote_request( EW_FAN_SUPABASE . $path, $args );
	if ( is_wp_error( $r ) ) {
		return array( 0, array( 'message' => $r->get_error_message() ) );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
}

/* ---------------------------------------------------------------- settings */

add_action( 'ew_fwp_after_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ew_runs_save'] ) && check_admin_referer( 'ew_runs_save' ) ) {
		$k = trim( (string) wp_unslash( $_POST['ew_runs_sb_key'] ?? '' ) );
		if ( '' !== $k ) {
			update_option( 'ew_runs_sb_key', $k, false );
		}
		if ( ! empty( $_POST['ew_runs_clear'] ) ) {
			delete_option( 'ew_runs_sb_key' );
		}
		echo '<div class="notice notice-success"><p>School runs key saved.</p></div>';
	}
	echo '<h2>School runs</h2>';
	echo '<p>The School runs page reads drivers\' positions and drops from the app\'s Supabase project. Paste the project\'s <strong>service_role</strong> key here (Supabase → Project settings → API keys). It is never shown again. '
		. 'Status: <strong>' . ( '' !== ew_runs_key() ? 'saved' : 'not set' ) . '</strong>.</p>';
	echo '<form method="post">';
	wp_nonce_field( 'ew_runs_save' );
	echo '<input type="hidden" name="ew_runs_save" value="1">';
	echo '<p><label>Supabase service key' . ( '' !== ew_runs_key() ? ' (saved; leave blank to keep it)' : '' ) . '<br><input type="password" name="ew_runs_sb_key" size="60" autocomplete="new-password" value=""></label></p>';
	echo '<p><label><input type="checkbox" name="ew_runs_clear" value="1"> Remove the saved key</label></p>';
	submit_button( 'Save School runs key' );
	echo '</form>';
}, 30 );

/* ---------------------------------------------------------------- REST */

add_action( 'rest_api_init', function () {
	$admin = function () {
		return current_user_can( 'manage_options' );
	};
	register_rest_route( 'eastwood/v1', '/runs-admin/state', array(
		'methods'             => 'GET',
		'permission_callback' => $admin,
		'callback'            => 'ew_runs_state',
	) );
	register_rest_route( 'eastwood/v1', '/runs-admin/plan', array(
		'methods'             => 'POST',
		'permission_callback' => $admin,
		'callback'            => 'ew_runs_save_plan',
	) );
	register_rest_route( 'eastwood/v1', '/runs-admin/driver', array(
		'methods'             => 'POST',
		'permission_callback' => $admin,
		'callback'            => 'ew_runs_save_driver',
	) );
} );

function ew_runs_state( WP_REST_Request $req ) {
	$today = wp_date( 'Y-m-d' );
	list( $c, $plans ) = ew_runs_sb( 'GET', '/rest/v1/school_run_plans?select=key,title,game_date,data&game_date=gte.' . rawurlencode( $today ) . '&order=game_date.asc&limit=1' );
	if ( 200 !== $c ) {
		return new WP_REST_Response( array( 'error' => is_array( $plans ) ? ( $plans['message'] ?? 'Could not read the runs.' ) : 'Could not read the runs.' ), 200 );
	}
	$plan = $plans[0] ?? null;
	if ( ! $plan ) {
		return array( 'plan' => null );
	}
	$k = rawurlencode( $plan['key'] );
	list( , $drops ) = ew_runs_sb( 'GET', '/rest/v1/school_run_drops?select=*&plan_key=eq.' . $k . '&order=dropped_at.asc' );
	$since = gmdate( 'c', time() - 16 * HOUR_IN_SECONDS );
	list( , $pos ) = ew_runs_sb( 'GET', '/rest/v1/school_run_positions?select=run_id,user_sub,driver_name,lat,lng,speed,heading,recorded_at&plan_key=eq.' . $k . '&recorded_at=gte.' . rawurlencode( $since ) . '&order=recorded_at.asc&limit=5000' );
	list( , $runners ) = ew_runs_sb( 'GET', '/rest/v1/runners?select=user_id,name,kind,status,photo_required,phone&order=name.asc' );
	$drops = is_array( $drops ) ? $drops : array();
	// Signed links for drop photos (private bucket), an hour long.
	$paths = array_values( array_filter( array_map( function ( $d ) { return $d['photo_path'] ?? ''; }, $drops ) ) );
	$photos = array();
	if ( $paths ) {
		list( $sc, $signed ) = ew_runs_sb( 'POST', '/storage/v1/object/sign/run-photos', array( 'expiresIn' => 3600, 'paths' => $paths ) );
		if ( 200 === $sc && is_array( $signed ) ) {
			foreach ( $signed as $s ) {
				if ( ! empty( $s['signedURL'] ) && ! empty( $s['path'] ) ) {
					$photos[ $s['path'] ] = EW_FAN_SUPABASE . '/storage/v1' . $s['signedURL'];
				}
			}
		}
	}
	return array(
		'plan'      => $plan,
		'drops'     => $drops,
		'positions' => is_array( $pos ) ? $pos : array(),
		'runners'   => is_array( $runners ) ? $runners : array(),
		'photos'    => $photos,
		'now'       => gmdate( 'c' ),
	);
}

function ew_runs_save_plan( WP_REST_Request $req ) {
	$key  = sanitize_text_field( (string) $req->get_param( 'key' ) );
	$data = $req->get_param( 'data' );
	if ( '' === $key || ! is_array( $data ) || ! isset( $data['runs'], $data['days'] ) ) {
		return new WP_Error( 'bad', 'Missing plan.', array( 'status' => 400 ) );
	}
	list( $c, $b ) = ew_runs_sb( 'PATCH', '/rest/v1/school_run_plans?key=eq.' . rawurlencode( $key ), array( 'data' => $data, 'updated_at' => gmdate( 'c' ) ), array( 'Prefer' => 'return=minimal' ) );
	if ( $c < 200 || $c >= 300 ) {
		return new WP_Error( 'save', is_array( $b ) ? ( $b['message'] ?? 'Not saved.' ) : 'Not saved.', array( 'status' => 500 ) );
	}
	return array( 'ok' => true );
}

function ew_runs_save_driver( WP_REST_Request $req ) {
	$sub = sanitize_text_field( (string) $req->get_param( 'user_id' ) );
	if ( '' === $sub ) {
		return new WP_Error( 'bad', 'Missing driver.', array( 'status' => 400 ) );
	}
	$row = array(
		'user_id'        => $sub,
		'name'           => sanitize_text_field( (string) $req->get_param( 'name' ) ),
		'kind'           => in_array( $req->get_param( 'kind' ), array( 'staff', 'volunteer' ), true ) ? $req->get_param( 'kind' ) : 'staff',
		'photo_required' => (bool) $req->get_param( 'photo_required' ),
		'status'         => 'approved',
		'approved_at'    => gmdate( 'c' ),
	);
	list( $c, $b ) = ew_runs_sb( 'POST', '/rest/v1/runners?on_conflict=user_id', $row, array( 'Prefer' => 'resolution=merge-duplicates,return=minimal' ) );
	if ( $c < 200 || $c >= 300 ) {
		return new WP_Error( 'save', is_array( $b ) ? ( $b['message'] ?? 'Not saved.' ) : 'Not saved.', array( 'status' => 500 ) );
	}
	return array( 'ok' => true );
}

/* ---------------------------------------------------------------- page */

add_action( 'admin_menu', function () {
	add_menu_page( 'School runs', 'School runs', 'manage_options', 'ew-runs', 'ew_runs_admin_page', 'dashicons-location', 28 );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_ew-runs' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', array(), '1.9.4', true );
	wp_enqueue_style( 'ew-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Instrument+Sans:wght@400;500;600;700&display=swap', array(), null );
} );

function ew_runs_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$cfg = array(
		'api'    => esc_url_raw( rest_url( 'eastwood/v1/runs-admin/' ) ),
		'nonce'  => wp_create_nonce( 'wp_rest' ),
		'hasKey' => '' !== ew_runs_key(),
	);
	echo '<div class="wrap ewr"><div id="ewr-app"><p>Loading…</p></div></div>';
	echo '<style>' . ew_runs_css() . '</style>';
	echo '<script>window.EWR=' . wp_json_encode( $cfg ) . ';</script>';
	echo '<script>' . ew_runs_js() . '</script>';
}

function ew_runs_css() {
	return <<<'CSS'
.ewr{font-family:'Instrument Sans',system-ui,sans-serif;color:#121417;max-width:1500px}
.ewr h1,.ewr h2,.ewr .anton{font-family:Anton,Impact,sans-serif;font-weight:400;text-transform:uppercase;letter-spacing:.3px}
.ewr h1{font-size:34px;margin:12px 0 0;padding:0}
.ewr .kick{font-size:11px;font-weight:600;letter-spacing:1px;color:#8B9099;text-transform:uppercase;margin:0}
.ewr .muted{color:#565C64;font-size:13px}
.ewr .bar{display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px;margin-bottom:16px}
.ewr .bar .grow{flex:1}
.ewr .tabs{display:flex;gap:4px;background:#fff;border:1px solid #E4E5E8;border-radius:10px;padding:4px}
.ewr .tabs button{height:36px;padding:0 16px;border-radius:8px;border:0;background:transparent;font:600 14px 'Instrument Sans',sans-serif;cursor:pointer;color:#121417}
.ewr .tabs button.on{background:#121417;color:#fff}
.ewr .btn{height:40px;padding:0 16px;border-radius:8px;border:1px solid rgba(0,0,0,.14);background:#fff;font:600 13px 'Instrument Sans',sans-serif;cursor:pointer;color:#121417}
.ewr .btn.red{background:#CC0000;border-color:#CC0000;color:#fff}
.ewr .btn.sm{height:30px;padding:0 10px;font-size:12px}
.ewr .card{background:#fff;border:1px solid #E4E5E8;border-radius:14px;padding:16px;max-width:none;min-width:0;margin-top:0;box-shadow:none;box-sizing:border-box}
.ewr .row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}
.ewr .rail{flex:1 1 260px;max-width:300px;display:flex;flex-direction:column;gap:10px}
.ewr .mapwrap{flex:999 1 480px;min-width:0}
.ewr #ewr-map{height:640px;border-radius:12px;border:1px solid #E4E5E8}
.ewr .detail{flex:1 1 300px;max-width:360px;display:flex;flex-direction:column;gap:10px}
.ewr .drv{background:#fff;border:1px solid #E4E5E8;border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px;cursor:pointer}
.ewr .drv.sel{border:2px solid #121417}
.ewr .drv .top{display:flex;align-items:center;gap:10px}
.ewr .av{width:36px;height:36px;border-radius:18px;background:#F0F1F3;display:flex;align-items:center;justify-content:center;font-weight:700}
.ewr .live{display:inline-block;width:8px;height:8px;border-radius:4px;background:#8B9099;margin-right:6px}
.ewr .live.on{background:#CC0000}
.ewr .prog{height:6px;border-radius:3px;background:rgba(0,0,0,.06);overflow:hidden}
.ewr .prog i{display:block;height:6px;background:#CC0000}
.ewr .stops{display:flex;flex-direction:column}
.ewr .stop{display:flex;align-items:center;gap:10px;min-height:36px;border-top:1px solid #E4E5E8;cursor:pointer;font-size:14px}
.ewr .stop .n{width:22px;height:22px;border-radius:11px;border:1.5px solid rgba(0,0,0,.28);box-sizing:border-box;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;flex-shrink:0}
.ewr .stop .n.done{background:#121417;border-color:#121417;color:#fff}
.ewr .stop .n.next{border:2px solid #CC0000}
.ewr .stop .nm{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ewr .photo{width:100%;border-radius:10px;background:#E4E5E8;min-height:120px;object-fit:cover}
.ewr .pin{width:24px;height:24px;border-radius:12px;background:#fff;border:2px solid #121417;box-sizing:border-box;display:flex;align-items:center;justify-content:center;font:700 11px 'Instrument Sans',sans-serif;color:#121417;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.ewr .pin.done{background:#121417;color:#fff;border-color:#fff}
.ewr .pin.next{border:3px solid #CC0000}
.ewr .car{width:30px;height:30px;border-radius:15px;background:#CC0000;border:3px solid #fff;box-sizing:border-box;box-shadow:0 2px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center}
.ewr .tl{overflow-x:auto;width:100%}
.ewr .tl .in{min-width:900px}
.ewr .tl .axis,.ewr .tl .lane{display:flex}
.ewr .tl .lab{flex:0 0 200px;padding:12px 14px}
.ewr .tl .track{flex:1;position:relative;min-height:72px}
.ewr .tl .lane{border-top:1px solid #E4E5E8}
.ewr .tl .tick{position:absolute;top:0;bottom:0;width:1px;background:#E4E5E8}
.ewr .tl .hr{position:absolute;top:8px;transform:translateX(-50%);font-size:12px;color:#8B9099}
.ewr .tl .blk{position:absolute;top:14px;height:44px;border-radius:8px;box-sizing:border-box;padding:4px 8px;overflow:hidden;font-size:12px;border:1px solid rgba(0,0,0,.2);background:#F7F7F8}
.ewr .tl .blk.on{border:2px solid #121417;background:#fff}
.ewr .tl .blk .fill{position:absolute;left:0;top:0;bottom:0;background:#CC0000}
.ewr .tl .blk span{position:relative;display:block;white-space:nowrap}
.ewr .tl .now{position:absolute;top:0;bottom:0;width:2px;background:#CC0000}
.ewr .day{background:#F7F7F8;border:1px solid #E4E5E8;border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:10px;margin-bottom:10px}
.ewr .day .hd{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.ewr .runs{display:flex;flex-wrap:wrap;gap:8px}
.ewr .runc{flex:1 1 240px;background:#fff;border:1px solid #E4E5E8;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:6px;cursor:pointer}
.ewr .runc.sel{border:2px solid #121417}
.ewr .runc select,.ewr .day input,.ewr .stoprow input{height:32px;border:1px solid rgba(0,0,0,.14);border-radius:6px;padding:0 8px;font:400 13px 'Instrument Sans',sans-serif}
.ewr .stoprow{display:flex;align-items:center;gap:8px;min-height:44px;border-top:1px solid #E4E5E8;font-size:14px}
.ewr .stoprow .nm{flex:1 1 220px;min-width:0}
.ewr .warn{background:#fff;border:1px solid #E4E5E8;border-radius:12px;padding:14px;margin:10px 0}
.ewr .legend{display:flex;flex-wrap:wrap;gap:18px;font-size:12px;color:#565C64;margin-top:8px}
@media (max-width:900px){.ewr #ewr-map{height:420px}.ewr .rail,.ewr .detail{max-width:none}}
CSS;
}

function ew_runs_js() {
	return <<<'JS'
(function(){
var C=window.EWR, app=document.getElementById('ewr-app');
var S={tab:'today',state:null,sel:{driver:null,run:null,stop:null},dirty:false,data:null,editRun:null};
var map=null, layer=null;
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function fmt(n){return Number(n||0).toLocaleString('en-GB');}
function hm(d){return new Date(d).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});}
function iso(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function dayLabel(s){return new Date(s+'T12:00:00').toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short'});}
function api(p,o){o=o||{};o.headers=Object.assign({'X-WP-Nonce':C.nonce,'Content-Type':'application/json'},o.headers||{});return fetch(C.api+p,o).then(function(r){return r.json();});}
function key(r,s){return r+':'+s;}
function dropsMap(){var m={};(S.state.drops||[]).forEach(function(d){m[key(d.run_id,d.stop)]=d;});return m;}
function runById(id){return S.data.runs.filter(function(r){return r.id===id;})[0];}
function driverOf(runId){var a=(S.data.assign||{})[String(runId)];if(a)return a;var d=S.data.days.filter(function(x){return x.runs.indexOf(runId)>-1;})[0];return d?{sub:null,name:d.driver}:{sub:null,name:''};}
function drivers(){
  // Roster (runners) plus anyone who has sent positions or is a day's driver.
  var list={};
  (S.state.runners||[]).forEach(function(r){list['sub:'+r.user_id]={sub:r.user_id,name:r.name||'Driver',kind:r.kind,photo:r.photo_required,roster:true};});
  (S.state.positions||[]).forEach(function(p){if(p.user_sub&&!list['sub:'+p.user_sub])list['sub:'+p.user_sub]={sub:p.user_sub,name:p.driver_name||'Driver',roster:false};});
  S.data.days.forEach(function(d){var n=(d.driver||'').trim();if(!n)return;var have=Object.keys(list).some(function(k){return (list[k].name||'').split(' ')[0].toLowerCase()===n.split(' ')[0].toLowerCase();});if(!have)list['name:'+n]={sub:null,name:n,roster:false};});
  return Object.keys(list).map(function(k){return list[k];});
}
function lastPos(d){var ps=(S.state.positions||[]).filter(function(p){return d.sub?p.user_sub===d.sub:(p.driver_name||'').split(' ')[0].toLowerCase()===d.name.split(' ')[0].toLowerCase();});return ps.length?ps[ps.length-1]:null;}
function trail(d){return (S.state.positions||[]).filter(function(p){return d.sub?p.user_sub===d.sub:(p.driver_name||'').split(' ')[0].toLowerCase()===d.name.split(' ')[0].toLowerCase();});}
function todayRuns(){var t=iso(new Date());var day=S.data.days.filter(function(d){return d.date===t;})[0];return day?day.runs.map(runById).filter(Boolean):[];}
function runsFor(d){return todayRuns().filter(function(r){var o=driverOf(r.id);return d.sub&&o.sub?o.sub===d.sub:(o.name||'').split(' ')[0].toLowerCase()===d.name.split(' ')[0].toLowerCase();});}

function load(){return api('state').then(function(st){S.state=st;if(st.plan&&!S.dirty){S.data=JSON.parse(JSON.stringify(st.plan.data));}render();});}

function render(){
  if(!C.hasKey){app.innerHTML='<h1>School runs</h1><div class="warn"><strong>One step first.</strong> Paste the Supabase service key in Settings → Eastwood FWP → School runs, then come back.</div>';return;}
  var st=S.state;if(!st){return;}
  if(st.error){app.innerHTML='<h1>School runs</h1><div class="warn">'+esc(st.error)+'</div>';return;}
  if(!st.plan){app.innerHTML='<h1>School runs</h1><div class="warn">No school runs loaded for the next home game.</div>';return;}
  var g=S.data.game||{};
  var h='<div class="bar"><div class="grow"><p class="kick">'+esc(g.home)+' vs '+esc(g.away)+' · '+esc(dayLabel(g.date||st.plan.game_date))+' · '+esc(g.kickoff||'')+'</p><h1>School runs</h1></div>'
   +'<div class="tabs">'+['today','timeline','plan'].map(function(t){return '<button data-tab="'+t+'" class="'+(S.tab===t?'on':'')+'">'+({today:'Today',timeline:'Timeline',plan:'Plan and edit'})[t]+'</button>';}).join('')+'</div>'
   +(S.tab==='plan'?'<button class="btn red" id="ewr-save"'+(S.dirty?'':' disabled')+'>'+(S.dirty?'Save changes':'Saved')+'</button>':'')+'</div>';
  if(S.tab==='today')h+=today();else if(S.tab==='timeline')h+=timeline();else h+=plan();
  if(map){map.remove();map=null;}
  app.innerHTML=h;
  if(S.tab==='today')drawMap();
}

function today(){
  var dm=dropsMap(), ds=drivers(), runs=todayRuns();
  var schools=0,done=0,tk=0,out=0;runs.forEach(function(r){schools+=r.stops.length;tk+=r.tickets;r.stops.forEach(function(s){var d=dm[key(r.id,s.stop)];if(d){done++;out+=d.tickets_dropped||0;}});});
  var rail='<p class="kick">Drivers today</p>';
  var any=false;
  ds.forEach(function(d){var rr=runsFor(d);if(!rr.length&&!lastPos(d))return;any=true;var lp=lastPos(d);var on=lp&&(Date.now()-new Date(lp.recorded_at))<3*60000;
    var dn=0,tot=0;rr.forEach(function(r){tot+=r.stops.length;r.stops.forEach(function(s){if(dm[key(r.id,s.stop)])dn++;});});
    rail+='<div class="drv'+(S.sel.driver===d.name?' sel':'')+'" data-driver="'+esc(d.name)+'"><div class="top"><span class="av">'+esc((d.name||'?')[0])+'</span><span style="flex:1"><strong>'+esc(d.name)+'</strong><br><span class="muted"><span class="live'+(on?' on':'')+'"></span>'+(lp?(on?'Live · ':'Last seen ')+hm(lp.recorded_at)+(lp.speed>0?' · '+Math.round(lp.speed*2.237)+' mph':''):'Not seen yet today')+'</span></span></div>'
    +(tot?'<div class="prog"><i style="width:'+(dn/tot*100).toFixed(0)+'%"></i></div><span class="muted">'+rr.map(function(r){return 'R'+r.id;}).join(', ')+' · '+dn+' of '+tot+' schools</span>':'')+'</div>';});
  if(!any)rail+='<div class="card muted">No driver has runs today.</div>';
  var stops='';runs.forEach(function(r){var nxt=null;r.stops.forEach(function(s){if(!nxt&&!dm[key(r.id,s.stop)])nxt=s.stop;});
    stops+='<p class="kick" style="margin-top:10px">R'+r.id+' · '+esc(r.name)+' · '+esc(driverOf(r.id).name)+'</p><div class="stops">';
    r.stops.forEach(function(s,i){var d=dm[key(r.id,s.stop)];stops+='<div class="stop" data-stop="'+r.id+':'+s.stop+'"><span class="n '+(d?'done':(s.stop===nxt?'next':''))+'">'+(d?'✓':(i+1))+'</span><span class="nm">'+esc(s.school)+'</span><span class="muted">'+(d?(d.status==='skipped'?'Skipped':fmt(d.tickets_dropped)+' · '+hm(d.dropped_at)):fmt(s.tickets))+'</span></div>';});
    stops+='</div>';});
  rail+='<div class="card" style="padding:12px">'+(stops||'<span class="muted">No runs today.</span>')+'</div>';
  return '<p class="muted">'+done+' of '+schools+' schools today · '+fmt(out)+' of '+fmt(tk)+' tickets out · updates every 30 seconds</p><div class="row" style="margin-top:10px"><aside class="rail">'+rail+'</aside><section class="mapwrap"><div id="ewr-map"></div><div class="legend"><span>● black: dropped</span><span>○ red ring: next</span><span>○ to do</span><span style="color:#CC0000">━ driven</span><span>Map © OpenStreetMap contributors</span></div></section><aside class="detail">'+detail()+'</aside></div>';
}

function detail(){
  if(!S.sel.stop)return '<div class="card muted">Click a school on the map or in the list to see what happened there.</div>';
  var p=S.sel.stop.split(':'),r=runById(+p[0]);if(!r)return '';var s=r.stops.filter(function(x){return x.stop===+p[1];})[0];if(!s)return '';
  var d=dropsMap()[S.sel.stop];var ph=d&&d.photo_path?S.state.photos[d.photo_path]:null;
  var h='<div class="card" style="display:flex;flex-direction:column;gap:10px"><p class="kick">Run '+r.id+' · stop '+(r.stops.indexOf(s)+1)+'</p><h2 style="margin:0;font-size:22px">'+esc(s.school)+'</h2><span class="muted">'+esc([s.town,s.postcode,s.head].filter(Boolean).join(' · '))+'</span>';
  if(d){h+='<div style="background:#F3F3F3;border-radius:10px;padding:10px 12px"><strong>'+(d.status==='skipped'?'Skipped':'Dropped')+' '+hm(d.dropped_at)+'</strong>'+(d.status==='skipped'?'':' · '+fmt(d.tickets_dropped)+' of '+fmt(s.tickets))+'</div>';
    h+=ph?'<a href="'+esc(ph)+'" target="_blank" rel="noopener"><img class="photo" src="'+esc(ph)+'" alt="Photo at '+esc(s.school)+'"></a>':'<div class="photo" style="display:flex;align-items:center;justify-content:center" class="muted">No photo</div>';
    h+='<div class="muted">Taken by: <strong>'+esc(d.handed_to||'—')+'</strong><br>Hand them out: <strong>'+esc({yes:'Yes',no:'No',not_sure:'Not sure'}[d.will_hand_out]||'—')+'</strong>'+(d.notes?'<br>Notes: '+esc(d.notes):'')+'</div>';
    h+='<p class="kick">What happened</p><div class="muted">'+(d.arrived_at?hm(d.arrived_at)+' · Arrived<br>':'')+hm(d.dropped_at)+' · '+(d.status==='skipped'?'Skipped':'Dropped, by '+esc(d.dropped_by_name||'driver'))+'</div>';
  } else h+='<div class="muted">Not dropped yet · '+fmt(s.tickets)+' tickets</div>';
  if(s.phone)h+='<a class="btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none" href="tel:'+esc(s.phone.replace(/\s+/g,''))+'">Call the school · '+esc(s.phone)+'</a>';
  return h+'</div>';
}

function drawMap(){
  if(!window.L)return setTimeout(drawMap,200);
  map=L.map('ewr-map',{zoomControl:true});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap contributors'}).addTo(map);
  var dm=dropsMap(), b=[];
  todayRuns().forEach(function(r){var pts=[],nxt=null;r.stops.forEach(function(s,i){if(typeof s.lat!=='number')return;var d=dm[key(r.id,s.stop)];if(!nxt&&!d)nxt=s.stop;pts.push([s.lat,s.lng]);b.push([s.lat,s.lng]);
      var cls=d?'done':(s.stop===nxt?'next':'');
      L.marker([s.lat,s.lng],{icon:L.divIcon({className:'',html:'<div class="pin '+cls+'">'+(d?'✓':(i+1))+'</div>',iconSize:[24,24],iconAnchor:[12,12]})}).addTo(map).on('click',function(){S.sel.stop=r.id+':'+s.stop;document.querySelector('.detail').innerHTML=detail();});});
    if(pts.length>1)L.polyline(pts,{color:'#121417',weight:2,opacity:.5,dashArray:'5 6'}).addTo(map);});
  drivers().forEach(function(d){var t=trail(d);if(!t.length)return;var ll=t.map(function(p){return [p.lat,p.lng];});L.polyline(ll,{color:'#CC0000',weight:4}).addTo(map);var lp=t[t.length-1];b.push([lp.lat,lp.lng]);
    L.marker([lp.lat,lp.lng],{icon:L.divIcon({className:'',html:'<div class="car" style="transform:rotate('+(lp.heading||0)+'deg)"><svg width="14" height="14" viewBox="0 0 24 24" fill="#fff"><path d="M12 2 20 21 12 16.5 4 21Z"/></svg></div>',iconSize:[30,30],iconAnchor:[15,15]})}).addTo(map).bindTooltip(esc(d.name)+' · '+hm(lp.recorded_at),{permanent:true,direction:'right',offset:[16,0]});});
  if(b.length)map.fitBounds(b,{padding:[30,30]});else map.setView([53.0143,-1.299],12);
}

function timeline(){
  var dm=dropsMap(), START=8*60, SPAN=8*60, now=new Date(), nowM=now.getHours()*60+now.getMinutes(), t0=iso(now);
  function pc(m){return ((m-START)/SPAN*100).toFixed(2)+'%';}
  var h='<div class="card tl"><div class="in"><div class="axis"><div class="lab"></div><div class="track" style="min-height:30px">';
  for(var i=0;i<=8;i++)h+='<span class="hr" style="left:'+pc(START+i*60)+'">'+String(8+i).padStart(2,'0')+':00</span>';
  h+='</div></div>';
  S.data.days.slice().sort(function(a,b){return a.date<b.date?-1:1;}).forEach(function(day){
    var t=9*60, bars='';
    for(var i=0;i<=8;i++)bars+='<span class="tick" style="left:'+pc(START+i*60)+'"></span>';
    day.runs.forEach(function(id){var r=runById(id);if(!r)return;var est=(r.driveMinutes||0)+3*r.stops.length;
      var ds=r.stops.map(function(s){return dm[key(r.id,s.stop)];}).filter(Boolean);
      if(ds.length&&day.date===t0){var first=new Date(ds[0].arrived_at||ds[0].dropped_at);var st=first.getHours()*60+first.getMinutes()-(r.stops[0].minutesFromLast||0);var len=Math.max(est,nowM-st);
        bars+='<div class="blk on" style="left:'+pc(st)+';width:'+(len/SPAN*100).toFixed(2)+'%"><div class="fill" style="width:'+((nowM-st)/len*100).toFixed(1)+'%"></div><span style="color:#fff;font-weight:700">R'+r.id+' · '+ds.length+' of '+r.stops.length+'</span><span style="color:#fff">'+esc(driverOf(r.id).name)+'</span></div>';t=st+len+15;}
      else{bars+='<div class="blk" style="left:'+pc(t)+';width:'+(est/SPAN*100).toFixed(2)+'%"><span style="font-weight:700">R'+r.id+' · '+r.stops.length+' schools</span><span class="muted">'+esc(driverOf(r.id).name)+' · '+String(Math.floor(t/60)).padStart(2,'0')+':'+String(t%60).padStart(2,'0')+'</span></div>';t+=est+15;}});
    if(day.date===t0&&nowM>=START&&nowM<=START+SPAN)bars+='<span class="now" style="left:'+pc(nowM)+'"></span>';
    h+='<div class="lane"><div class="lab"><div class="anton" style="font-size:18px">'+esc(dayLabel(day.date))+(day.date===t0?' · Today':'')+'</div><span class="muted">'+esc(day.driver||'')+' · '+day.runs.length+' runs</span></div><div class="track">'+bars+'</div></div>';});
  return h+'</div></div><p class="muted">Bars not yet driven are estimates: drive time plus about 3 minutes a school, from 9am, 15 minutes between runs.</p>';
}

function plan(){
  var ds=drivers(), sel=S.editRun;
  var opts=function(runId){var a=(S.data.assign||{})[String(runId)];var h='<option value="">Day\'s driver</option>';ds.filter(function(d){return d.sub;}).forEach(function(d){h+='<option value="'+esc(d.sub)+'"'+(a&&a.sub===d.sub?' selected':'')+'>'+esc(d.name)+'</option>';});return h;};
  var h='<div class="card"><div style="display:flex;align-items:center;gap:10px;margin-bottom:10px"><h2 style="margin:0;font-size:18px">Days</h2><span style="flex:1"></span><button class="btn sm" data-act="addday">Add a day</button></div>';
  S.data.days.forEach(function(d,di){
    h+='<div class="day"><div class="hd"><button class="btn sm" data-act="dprev" data-i="'+di+'">‹</button><span class="anton" style="font-size:18px;min-width:96px;text-align:center">'+esc(dayLabel(d.date))+'</span><button class="btn sm" data-act="dnext" data-i="'+di+'">›</button><label class="kick" style="margin-left:8px">Driver <input data-act="driver" data-i="'+di+'" value="'+esc(d.driver)+'" style="width:120px"></label><span style="flex:1"></span><button class="btn sm" data-act="delday" data-i="'+di+'">Remove day</button></div><div class="runs">';
    d.runs.forEach(function(id,ri){var r=runById(id);if(!r)return;h+='<div class="runc'+(sel===id?' sel':'')+'" data-act="pick" data-id="'+id+'"><strong>R'+id+' · '+esc(r.name)+'</strong><span class="muted">'+r.stops.length+' schools · '+fmt(r.tickets)+' tickets</span>'
      +'<label class="muted">Driver <select data-act="assign" data-id="'+id+'">'+opts(id)+'</select></label>'
      +'<span style="display:flex;gap:6px"><button class="btn sm" data-act="rup" data-i="'+di+'" data-r="'+ri+'">↑</button><button class="btn sm" data-act="rdn" data-i="'+di+'" data-r="'+ri+'">↓</button><select data-act="move" data-i="'+di+'" data-r="'+ri+'"><option value="">Move to…</option>'+S.data.days.map(function(x,xi){return xi===di?'':'<option value="'+xi+'">'+esc(dayLabel(x.date))+'</option>';}).join('')+'<option value="off">Take off the days</option></select></span></div>';});
    h+='</div></div>';});
  var un=S.data.runs.filter(function(r){return !S.data.days.some(function(d){return d.runs.indexOf(r.id)>-1;});});
  if(un.length){h+='<p class="kick">Not on a day yet</p><div class="runs">'+un.map(function(r){return '<div class="runc" data-act="pick" data-id="'+r.id+'"><strong>R'+r.id+' · '+esc(r.name)+'</strong><select data-act="put" data-id="'+r.id+'"><option value="">Put on…</option>'+S.data.days.map(function(x,xi){return '<option value="'+xi+'">'+esc(dayLabel(x.date))+'</option>';}).join('')+'</select></div>';}).join('')+'</div>';}
  h+='</div>';
  if(sel){var r=runById(sel);h+='<div class="card" style="margin-top:16px"><h2 style="margin:0 0 4px;font-size:18px">Run '+r.id+' · '+esc(r.name)+'</h2><p class="muted">'+r.stops.length+' schools · order top to bottom is the order they are driven</p>';
    r.stops.forEach(function(s,i){h+='<div class="stoprow"><span class="muted" style="width:22px;text-align:right">'+(i+1)+'</span><span class="nm"><strong>'+esc(s.school)+'</strong> <span class="muted">'+esc(s.town)+' · '+esc(s.postcode)+'</span></span><label class="muted">Tickets <input data-act="tk" data-s="'+i+'" value="'+s.tickets+'" style="width:70px"></label><button class="btn sm" data-act="sup" data-s="'+i+'">↑</button><button class="btn sm" data-act="sdn" data-s="'+i+'">↓</button><button class="btn sm" data-act="sdel" data-s="'+i+'">Take off</button></div>';});
    h+='<div class="stoprow"><input id="ewr-ns" placeholder="School name" style="flex:1 1 200px"><input id="ewr-np" placeholder="Postcode" style="width:110px"><input id="ewr-nt" placeholder="Tickets" style="width:80px"><button class="btn sm" data-act="sadd">Add a school</button></div></div>';}
  h+='<div class="card" style="margin-top:16px"><h2 style="margin:0 0 6px;font-size:18px">Drivers</h2><p class="muted">Drivers come from the runners list. Anyone who has opened School runs in the app shows here and can be added. Photo required means they can\'t finish a school without one.</p>';
  ds.forEach(function(d){h+='<div class="stoprow"><span class="nm"><strong>'+esc(d.name)+'</strong> <span class="muted">'+(d.roster?(d.kind==='staff'?'Staff':'Volunteer')+' · photo '+(d.photo?'required':'optional'):(d.sub?'Not on the list yet':'Name only: open School runs in the app once to link'))+'</span></span>'
    +(d.sub?'<label class="muted"><input type="checkbox" data-act="photo" data-sub="'+esc(d.sub)+'" data-name="'+esc(d.name)+'" data-kind="'+esc(d.kind||'staff')+'"'+(d.roster?(d.photo?' checked':''):'')+'> Photo required</label>'+(d.roster?'':'<button class="btn sm" data-act="adddrv" data-sub="'+esc(d.sub)+'" data-name="'+esc(d.name)+'">Add as driver</button>'):'')+'</div>';});
  return h+'</div>';
}

function shift(s,n){var d=new Date(s+'T12:00:00');d.setDate(d.getDate()+n);return iso(d);}
function changed(){S.dirty=true;render();}
app.addEventListener('click',function(e){
  var el=e.target.closest('[data-tab],[data-act],[data-driver],[data-stop],#ewr-save');if(!el)return;
  if(el.dataset.tab){S.tab=el.dataset.tab;render();return;}
  if(el.id==='ewr-save'){api('plan',{method:'POST',body:JSON.stringify({key:S.state.plan.key,data:S.data})}).then(function(r){if(r&&r.ok){S.dirty=false;load();}else alert((r&&r.message)||'Not saved');});return;}
  if(el.dataset.driver){S.sel.driver=el.dataset.driver;render();return;}
  if(el.dataset.stop){S.sel.stop=el.dataset.stop;document.querySelector('.detail').innerHTML=detail();return;}
  var a=el.dataset.act,i=+el.dataset.i,ri=+el.dataset.r,si=+el.dataset.s,D=S.data;
  if(el.tagName==='SELECT'||el.tagName==='INPUT')return;
  var run=S.editRun?runById(S.editRun):null;
  if(a==='pick'){S.editRun=+el.dataset.id;render();}
  else if(a==='dprev'){D.days[i].date=shift(D.days[i].date,-1);changed();}
  else if(a==='dnext'){D.days[i].date=shift(D.days[i].date,1);changed();}
  else if(a==='delday'){if(confirm('Take this day off? Its runs go to "Not on a day yet".')){D.days.splice(i,1);changed();}}
  else if(a==='addday'){var l=D.days[D.days.length-1];D.days.push({date:l?shift(l.date,1):iso(new Date()),driver:l?l.driver:'',runs:[]});changed();}
  else if(a==='rup'||a==='rdn'){var arr=D.days[i].runs,j=ri+(a==='rup'?-1:1);if(j<0||j>=arr.length)return;var t=arr[ri];arr[ri]=arr[j];arr[j]=t;e.stopPropagation();changed();}
  else if(run&&(a==='sup'||a==='sdn')){var j2=si+(a==='sup'?-1:1);if(j2<0||j2>=run.stops.length)return;var t2=run.stops[si];run.stops[si]=run.stops[j2];run.stops[j2]=t2;changed();}
  else if(run&&a==='sdel'){if(run.stops.length<2){alert('A run needs at least one school.');return;}if(confirm('Take '+run.stops[si].school+' off this run?')){run.stops.splice(si,1);run.tickets=run.stops.reduce(function(x,s){return x+(+s.tickets||0);},0);changed();}}
  else if(run&&a==='sadd'){var n=document.getElementById('ewr-ns').value.trim(),pc=document.getElementById('ewr-np').value.trim().toUpperCase(),tk=parseInt(document.getElementById('ewr-nt').value,10)||0;if(!n||!pc){alert('School name and postcode, please.');return;}var mx=run.stops.reduce(function(m,s){return Math.max(m,s.stop);},0)+1;var q=encodeURIComponent(n+', '+pc).replace(/%20/g,'+');run.stops.push({stop:mx,school:n,town:'',postcode:pc,head:'',phone:'',ages:'',pupils:0,tickets:tk,milesFromLast:0,minutesFromLast:0,mapsUrl:'https://www.google.com/maps/dir/?api=1&destination='+q});run.tickets=run.stops.reduce(function(x,s){return x+(+s.tickets||0);},0);changed();}
  else if(a==='adddrv'){api('driver',{method:'POST',body:JSON.stringify({user_id:el.dataset.sub,name:el.dataset.name,kind:'staff',photo_required:false})}).then(load);}
});
app.addEventListener('change',function(e){
  var el=e.target,a=el.dataset.act,D=S.data;if(!a)return;
  if(a==='driver'){D.days[+el.dataset.i].driver=el.value;S.dirty=true;var b=document.getElementById('ewr-save');if(b){b.disabled=false;b.textContent='Save changes';}return;}
  if(a==='assign'){D.assign=D.assign||{};var id=String(el.dataset.id);if(!el.value)delete D.assign[id];else{var d=drivers().filter(function(x){return x.sub===el.value;})[0];D.assign[id]={sub:el.value,name:d?d.name:''};}changed();return;}
  if(a==='move'){var i=+el.dataset.i,ri=+el.dataset.r;if(el.value==='')return;var id2=D.days[i].runs.splice(ri,1)[0];if(el.value!=='off')D.days[+el.value].runs.push(id2);changed();return;}
  if(a==='put'){if(el.value==='')return;D.days[+el.value].runs.push(+el.dataset.id);changed();return;}
  if(a==='tk'){var run=runById(S.editRun);run.stops[+el.dataset.s].tickets=parseInt(el.value,10)||0;run.tickets=run.stops.reduce(function(x,s){return x+(+s.tickets||0);},0);changed();return;}
  if(a==='photo'){api('driver',{method:'POST',body:JSON.stringify({user_id:el.dataset.sub,name:el.dataset.name,kind:el.dataset.kind||'staff',photo_required:el.checked})}).then(load);return;}
});
window.addEventListener('beforeunload',function(e){if(S.dirty){e.preventDefault();e.returnValue='';}});
load();
setInterval(function(){if(S.tab!=='plan')load();},30000);
})();
JS;
}
