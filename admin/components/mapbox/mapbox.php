<?php
/**
 * admin/components/mapbox/mapbox.php - the Mapbox CDN include.
 *
 * Include this ONCE, inside <head>, only on pages that actually show a
 * route map (Employee Day Detail, Overview, Routes & Map, Dashboard) - not
 * loaded globally in header.php, since most admin pages never need it.
 *
 *   $sectionCss[] = APP_URL . '/admin/components/mapbox/css/mapbox-route.css';
 *   ... then right before header.php's closing </head>-adjacent output,
 *   or anywhere in <head>: require .../components/mapbox/mapbox.php;
 *
 * To actually draw a map, a page then renders a div and calls
 * TrackMapboxRoute.render() - see admin/components/mapbox/js/mapbox-route.js
 * for the exact usage and admin/02-employees/_route_map.php for a worked
 * example once wired in.
 */

declare(strict_types=1);
?>
<link href="https://api.mapbox.com/mapbox-gl-js/v3.29.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.29.0/mapbox-gl.js"></script>
<script src="<?= e(asset_url(APP_URL . '/admin/components/mapbox/js/mapbox-route.js')) ?>"></script>
