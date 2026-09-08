<?php
/**
 * admin/08-evidence-photos/photo.php - one photo, full size, on the map.
 * URL: /track/admin/08-evidence-photos/photo.php?id=N
 *
 * Opened from the "View on map" button on a photo card. Shows the capture
 * large, its details, and an expanded street map centred on where it was
 * taken (with that day's check-in / check-out as context).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';
require dirname(__DIR__) . '/02-employees/_repo.php';   // latlng() / map_link()
require dirname(__DIR__) . '/components/mapbox/mapbox.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare(
    "SELECT p.*, u.name AS employee_name, u.code AS employee_code, u.region,
            u.photo_path AS employee_photo,
            v.shop_name, v.seq AS visit_seq,
            a.check_in_at, a.check_in_lat, a.check_in_lng, a.check_in_odometer_km,
            a.check_out_at, a.check_out_lat, a.check_out_lng, a.check_out_odometer_km
       FROM photos p
       JOIN users u        ON u.id = p.employee_id
  LEFT JOIN visits v       ON v.id = p.visit_id
  LEFT JOIN attendance a   ON a.id = COALESCE(p.attendance_id, (SELECT attendance_id FROM visits WHERE id = p.visit_id))
      WHERE p.id = ?
      LIMIT 1"
);
$stmt->execute([$id]);
$ph = $stmt->fetch();

if (!$ph) {
    http_response_code(404);
    $pageTitle = 'Photo not found';
    $activeSection = 'photos';
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><h1>Photo not found</h1><p class="section-note">That photo no longer exists. '
       . '<a href="' . e(APP_URL) . '/admin/08-evidence-photos/">Back to Evidence Photos</a>.</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$kinds  = photo_kinds();
$kLabel = $kinds[$ph['photo_kind']] ?? 'Photo';
$kMeta  = [
    'checkin'  => ['tone' => 'green', 'icon' => 'bi-box-arrow-in-right'],
    'visit'    => ['tone' => 'blue',  'icon' => 'bi-shop'],
    'checkout' => ['tone' => 'red',   'icon' => 'bi-box-arrow-right'],
][$ph['photo_kind']] ?? ['tone' => 'grey', 'icon' => 'bi-camera'];

$lat = isset($ph['lat']) ? (float) $ph['lat'] : null;
$lng = isset($ph['lng']) ? (float) $ph['lng'] : null;
$hasGps = $lat !== null && $lng !== null;

/**
 * Build one event's (check-in or check-out) display facts, or null if that
 * event hasn't happened yet (e.g. check-out on a day that's still open).
 * Shown regardless of which single photo is being viewed - the admin
 * reviewing ANY photo from this day (checkin, checkout, or a shop visit in
 * between) sees the whole day's check-in AND check-out story together,
 * not just the one event this particular photo happens to belong to.
 */
function evidence_photo_event(array $ph, string $prefix): ?array
{
    $at = $ph["{$prefix}_at"] ?? null;
    if (!$at) {
        return null;
    }
    $lat = $ph["{$prefix}_lat"] ?? null;
    $lng = $ph["{$prefix}_lng"] ?? null;
    $odo = $ph["{$prefix}_odometer_km"] ?? null;

    return [
        'at'  => new DateTimeImmutable($at),
        'lat' => $lat !== null ? (float) $lat : null,
        'lng' => $lng !== null ? (float) $lng : null,
        'odo' => $odo !== null ? (float) $odo : null,
    ];
}

$checkInEvent  = evidence_photo_event($ph, 'check_in');
$checkOutEvent = evidence_photo_event($ph, 'check_out');

$taken   = new DateTimeImmutable($ph['taken_at']);
$title   = $ph['photo_kind'] === 'visit' ? ($ph['shop_name'] ?: 'Shop visit') : $kLabel;
$imgUrl  = UPLOAD_URL . '/' . $ph['stored_path'];
$gmap    = map_link($lat, $lng);
$dayUrl  = APP_URL . '/admin/02-employees/day.php?employee=' . (int) $ph['employee_id'] . '&date=' . $ph['work_date'];

// Return to the gallery, keeping the filters the user came from (if same-site).
$backUrl = APP_URL . '/admin/08-evidence-photos/';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '' && str_starts_with($ref, APP_URL . '/admin/08-evidence-photos/') && !str_contains($ref, 'photo.php')) {
    $backUrl = $ref;
}

// Real Mapbox map, one point - same TrackMapboxRoute.render() every route
// map on the admin side already uses (see admin/components/mapbox), just
// fed a single-point array here. render() itself already handles a lone
// point fine (skips line-drawing/bounds-fitting, which both need >= 2
// points, and just centers on it) - see mapbox-route.js.
if ($hasGps) {
    $photoPointJson = json_encode([[
        'kind' => $ph['photo_kind'], 'label' => $title, 'at' => $ph['taken_at'],
        'lat' => $lat, 'lng' => $lng, 'no' => $ph['visit_seq'] ?? null,
    ]]);
    $osmView = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat)
        . '&mlon=' . rawurlencode((string) $lng) . '#map=17/' . $lat . '/' . $lng;
}

$pageTitle     = $title . ' - photo';
$activeSection = 'photos';
$sectionCss    = [
    APP_URL . '/admin/08-evidence-photos/css/evidence-photos.css',
    APP_URL . '/admin/components/mapbox/css/mapbox-route.css',
];

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <a class="ep-back" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left"></i> Back to Evidence Photos</a>
      <h1><?= e($title) ?></h1>
      <p class="section-note">
        <span class="ep-chip ep-chip--<?= e($kMeta['tone']) ?>"><i class="bi <?= e($kMeta['icon']) ?>"></i> <?= e($kLabel) ?></span>
        &nbsp;<?= e($taken->format('l, j M Y')) ?> at <?= e($taken->format('g:i A')) ?>
      </p>
    </div>
    <div class="ep-pickers">
      <a class="btn" href="<?= e($imgUrl) ?>" download><i class="bi bi-download"></i> Download photo</a>
      <a class="btn" href="<?= e($dayUrl) ?>"><i class="bi bi-calendar-week"></i> Open the day</a>
      <?php if ($gmap): ?>
        <a class="btn" href="<?= e($gmap) ?>" target="_blank" rel="noopener"><i class="bi bi-geo-alt-fill"></i> Google Maps</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="ep-photopage">
    <!-- photo -->
    <figure class="card ep-photobox">
      <button type="button" class="ep-photopage__img" data-lightbox
              data-src="<?= e($imgUrl) ?>"
              data-shop="<?= e($title) ?>"
              data-employee="<?= e($ph['employee_name']) ?>"
              data-when="<?= e($taken->format('j M Y, g:i A')) ?>"
              data-kind="<?= e($kLabel) ?>">
        <img src="<?= e($imgUrl) ?>" alt="<?= e($title) ?>">
        <span class="ep-card__zoom"><i class="bi bi-arrows-fullscreen"></i></span>
      </button>
      <figcaption>
        <a class="ep-photopage__employee" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $ph['employee_id'] ?>">
          <?php if (!empty($ph['employee_photo'])): ?>
            <span class="ep-ava ep-ava--photo"><img src="<?= e(UPLOAD_URL . '/' . $ph['employee_photo']) ?>" alt=""></span>
          <?php else: ?>
            <span class="ep-ava"><?= e(mb_strtoupper(mb_substr($ph['employee_name'], 0, 1))) ?></span>
          <?php endif; ?>
          <span class="stack">
            <strong><?= e($ph['employee_name']) ?></strong>
            <span class="c-muted"><?= e($ph['employee_code'] ?: '-') ?><?= $ph['region'] ? ' &middot; ' . e($ph['region']) : '' ?></span>
          </span>
        </a>
        <dl class="ep-photopage__facts">
          <?php if ($ph['photo_kind'] === 'visit' && $ph['shop_name']): ?>
            <div>
              <span class="ep-fact__ico ep-fact__ico--blue"><i class="bi bi-shop"></i></span>
              <dt>Shop</dt>
              <dd><?= e($ph['shop_name']) ?><?= $ph['visit_seq'] ? ' <span class="c-muted">(stop ' . (int) $ph['visit_seq'] . ')</span>' : '' ?></dd>
            </div>
          <?php endif; ?>
          <div>
            <span class="ep-fact__ico ep-fact__ico--purple"><i class="bi bi-camera"></i></span>
            <dt>This photo</dt>
            <dd><?= e($taken->format('g:i:s A')) ?> <span class="c-muted">&middot; <?= e($kLabel) ?></span></dd>
          </div>
          <div>
            <span class="ep-fact__ico ep-fact__ico--grey"><i class="bi bi-geo-alt"></i></span>
            <dt>Photo GPS</dt>
            <dd><?= $hasGps ? e(latlng($lat, $lng)) : '<span class="c-muted">no GPS recorded</span>' ?></dd>
          </div>
        </dl>

        <?php if ($checkInEvent): ?>
          <div class="ep-fact-group">
            <p class="ep-fact-group__head ep-fact-group__head--green"><i class="bi bi-box-arrow-in-right"></i> Check-in</p>
            <dl class="ep-photopage__facts">
              <div>
                <span class="ep-fact__ico ep-fact__ico--green"><i class="bi bi-clock"></i></span>
                <dt>Time</dt>
                <dd>
                  <?= e($checkInEvent['at']->format('g:i:s A')) ?>
                  <?php if ($checkInEvent['odo'] !== null): ?>
                    <span class="c-muted">&middot; <?= e(number_format($checkInEvent['odo'], 1)) ?> km</span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <span class="ep-fact__ico ep-fact__ico--green"><i class="bi bi-geo-alt"></i></span>
                <dt>Location</dt>
                <dd><?= $checkInEvent['lat'] !== null ? e(latlng($checkInEvent['lat'], $checkInEvent['lng'])) : '<span class="c-muted">no GPS recorded</span>' ?></dd>
              </div>
              <div>
                <span class="ep-fact__ico ep-fact__ico--green"><i class="bi bi-speedometer2"></i></span>
                <dt>Odometer</dt>
                <dd class="ep-fact__strong"><?= $checkInEvent['odo'] !== null ? e(number_format($checkInEvent['odo'], 1)) . ' km' : dash() ?></dd>
              </div>
            </dl>
          </div>
        <?php endif; ?>

        <?php if ($checkOutEvent): ?>
          <div class="ep-fact-group">
            <p class="ep-fact-group__head ep-fact-group__head--red"><i class="bi bi-box-arrow-right"></i> Check-out</p>
            <dl class="ep-photopage__facts">
              <div>
                <span class="ep-fact__ico ep-fact__ico--red"><i class="bi bi-clock"></i></span>
                <dt>Time</dt>
                <dd>
                  <?= e($checkOutEvent['at']->format('g:i:s A')) ?>
                  <?php if ($checkOutEvent['odo'] !== null): ?>
                    <span class="c-muted">&middot; <?= e(number_format($checkOutEvent['odo'], 1)) ?> km</span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <span class="ep-fact__ico ep-fact__ico--red"><i class="bi bi-geo-alt"></i></span>
                <dt>Location</dt>
                <dd><?= $checkOutEvent['lat'] !== null ? e(latlng($checkOutEvent['lat'], $checkOutEvent['lng'])) : '<span class="c-muted">no GPS recorded</span>' ?></dd>
              </div>
              <div>
                <span class="ep-fact__ico ep-fact__ico--red"><i class="bi bi-speedometer2"></i></span>
                <dt>Odometer</dt>
                <dd class="ep-fact__strong"><?= $checkOutEvent['odo'] !== null ? e(number_format($checkOutEvent['odo'], 1)) . ' km' : dash() ?></dd>
              </div>
            </dl>
          </div>
        <?php else: ?>
          <div class="ep-fact-group">
            <p class="ep-fact-group__head ep-fact-group__head--red"><i class="bi bi-box-arrow-right"></i> Check-out</p>
            <p class="section-note">Not checked out yet.</p>
          </div>
        <?php endif; ?>

        <dl class="ep-photopage__facts">
          <div>
            <span class="ep-fact__ico ep-fact__ico--grey"><i class="bi bi-file-earmark-image"></i></span>
            <dt>File</dt>
            <dd><?= e(strtoupper(pathinfo($ph['stored_path'], PATHINFO_EXTENSION))) ?>, <?= e(number_format((int) $ph['bytes'] / 1024)) ?> KB<?= $ph['width'] ? ', ' . (int) $ph['width'] . '&times;' . (int) $ph['height'] : '' ?></dd>
          </div>
        </dl>
      </figcaption>
    </figure>

    <!-- map -->
    <section class="card ep-mapbox">
      <div class="card__head">
        <h2><i class="bi bi-geo-alt"></i> Where it was taken</h2>
        <?php if ($hasGps): ?>
          <a class="ep-mapbox__open" href="<?= e($osmView) ?>" target="_blank" rel="noopener">Open larger <i class="bi bi-box-arrow-up-right"></i></a>
        <?php endif; ?>
      </div>
      <?php if ($hasGps): ?>
        <div class="ep-map">
          <div class="ep-map__real mb-route-map" id="mb-photo-map"
               data-map-token="<?= e(defined('MAPBOX_ACCESS_TOKEN') ? MAPBOX_ACCESS_TOKEN : '') ?>"
               data-map-points='<?= e($photoPointJson) ?>'></div>
          <button type="button" class="ep-map__expand" title="Expand map to inspect"
                  onclick="if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.openRoute('mb-photo-map'); }">
            <i class="bi bi-arrows-fullscreen"></i> Expand map
          </button>
        </div>
        <script>if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.render('mb-photo-map'); }</script>
        <p class="section-note ep-map__foot">
          <i class="bi bi-info-circle"></i>
          Marker is the GPS point the phone recorded when the photo was taken
          (<?= e($taken->format('g:i A')) ?>). Accuracy depends on the phone's signal at that moment.
        </p>
      <?php else: ?>
        <p class="section-note ep-map__none">
          <i class="bi bi-geo"></i>
          No GPS was recorded with this photo, so it cannot be placed on the map.
        </p>
      <?php endif; ?>
    </section>
  </div>

  <!-- ===== lightbox (same markup/behavior as the gallery - see evidence-photos.js) ===== -->
  <div class="ep-lightbox" id="ep-lightbox" hidden>
    <button type="button" class="ep-lightbox__close" data-lightbox-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <figure class="ep-lightbox__frame">
      <img src="" alt="" id="ep-lightbox-img">
      <figcaption class="ep-lightbox__cap">
        <strong id="ep-lightbox-shop"></strong>
        <span id="ep-lightbox-meta"></span>
      </figcaption>
    </figure>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/08-evidence-photos/js/evidence-photos.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
