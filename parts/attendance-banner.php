<?php
/**
 * The official attendance, across the top of every page, the moment it is
 * announced in the app (the Attendance preset in Announcements).
 *
 * Read in the visitor's browser straight from the app database, so a page
 * cached by the CDN still shows it. Comes down with the app banner: when staff
 * switch it off, when it expires, or twelve hours after it went out.
 *
 * @package eastwood-fwp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', function () {
	if ( is_admin() || ! defined( 'EW_FAN_SUPABASE' ) || ! defined( 'EW_FAN_ANON' ) ) {
		return;
	}
	$cfg = array(
		'url' => EW_FAN_SUPABASE . '/rest/v1/announcements',
		'key' => EW_FAN_ANON,
	);
	?>
<style>
.ew-att{position:relative;z-index:9999;background:#CC0000;color:#fff;text-align:center;padding:12px 16px;font-family:Anton,Impact,"Arial Black",sans-serif;letter-spacing:.02em;text-transform:uppercase;line-height:1.2}
.ew-att b{font-weight:400;font-size:22px;display:block}
.ew-att span{font-family:"Instrument Sans",Arial,sans-serif;text-transform:none;letter-spacing:0;font-size:15px;font-weight:600;display:block;margin-top:2px;opacity:.95}
</style>
<script>
(function () {
	var cfg = <?php echo wp_json_encode( $cfg ); ?>;
	var since = new Date(Date.now() - 12 * 3600 * 1000).toISOString();
	var now = new Date().toISOString();
	var q = cfg.url + '?select=title,body,expires_at,send_at&club=eq.eastwood&preset=eq.attendance&active=eq.true&banner=eq.true&figure=not.is.null'
		+ '&created_at=gte.' + encodeURIComponent(since) + '&or=(send_at.is.null,send_at.lte.' + encodeURIComponent(now) + ')&order=created_at.desc&limit=1';
	fetch(q, { headers: { apikey: cfg.key, Authorization: 'Bearer ' + cfg.key } })
		.then(function (r) { return r.ok ? r.json() : []; })
		.then(function (rows) {
			var a = rows && rows[0];
			if (!a || (a.expires_at && new Date(a.expires_at) <= new Date())) return;
			var bar = document.createElement('div');
			bar.className = 'ew-att';
			bar.setAttribute('role', 'status');
			var t = document.createElement('b');
			t.textContent = a.title;
			bar.appendChild(t);
			if (a.body) {
				var s = document.createElement('span');
				s.textContent = a.body;
				bar.appendChild(s);
			}
			document.body.insertBefore(bar, document.body.firstChild);
		})
		.catch(function () {});
})();
</script>
	<?php
}, 30 );
