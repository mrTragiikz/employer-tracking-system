<?php
/**
 * admin/02-employees/_view_overview.php  -  Overview tab body.
 * Included by view.php.
 *
 * Expects from view.php:
 *   $id, $employee
 *   $ovMode        'day' | 'month' | 'alltime'
 *   $onDate, $isToday                    (day mode)
 *   $onMonth                             (month mode)
 *   $statement, $timeline                (day mode data)
 *   $periodTotals, $periodLabel          (month / alltime data)
 *   $monthDays                           (month mode: calendar rows)
 *
 * Left column  : the statement for the chosen period + editable Notes
 * Right column : day mode -> location timeline ; month mode -> per-day list ;
 *                alltime  -> a pointer to the Attendance tab
 */

declare(strict_types=1);

$kindMeta = [
    'checkin'  => ['Check-in',  'in'],
    'visit'    => ['Visit',     'visit'],
    'checkout' => ['Check-out', 'out'],
];

/** URL to this page in a given overview mode. #tabs keeps the viewport at the
 *  tab bar after switching, instead of jumping to the top of the page. */
$ovUrl = static function (int $id, string $mode, array $extra = []): string {
    return APP_URL . '/admin/02-employees/view.php?'
        . http_build_query(['id' => $id, 'tab' => 'overview', 'view' => $mode] + $extra) . '#tabs';
};

// "Export PDF" always exports whatever period is CURRENTLY on screen - same
// id/view/on/month params view.php itself uses, so there is nothing extra
// to keep in sync when the admin switches Day/Month/All time or picks a
// different date.
$exportParams = ['id' => $id, 'view' => $ovMode];
if ($ovMode === 'day') {
    $exportParams['on'] = $onDate;
} elseif ($ovMode === 'month') {
    $exportParams['month'] = $onMonth;
}
$exportPdfUrl = APP_URL . '/admin/02-employees/api/export-pdf.php?' . http_build_query($exportParams);
?>

  <!-- Day / Month / All time selector -->
  <div class="ov-modes">
    <div class="ov-modes__seg">
      <a class="ov-seg <?= $ovMode === 'day'     ? 'is-active' : '' ?>" href="<?= e($ovUrl($id, 'day')) ?>">Day</a>
      <a class="ov-seg <?= $ovMode === 'month'   ? 'is-active' : '' ?>" href="<?= e($ovUrl($id, 'month')) ?>">Month</a>
      <a class="ov-seg <?= $ovMode === 'alltime' ? 'is-active' : '' ?>" href="<?= e($ovUrl($id, 'alltime')) ?>">All time</a>
    </div>

    <div class="ov-modes__right">
      <?php if ($ovMode === 'day'): ?>
        <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php" class="ov-datepick">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="tab" value="overview">
          <input type="hidden" name="view" value="day">
          <input class="input" type="date" name="on" value="<?= e($onDate) ?>"
                 max="<?= e(server_today()) ?>" onchange="this.form.submit()">
          <button type="submit" class="btn btn--sm">Show</button>
          <?php if (!$isToday): ?>
            <a class="btn btn--sm" href="<?= e($ovUrl($id, 'day')) ?>"><i class="bi bi-arrow-counterclockwise"></i> Today</a>
          <?php endif; ?>
        </form>
      <?php elseif ($ovMode === 'month'): ?>
        <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/view.php" class="ov-datepick">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="tab" value="overview">
          <input type="hidden" name="view" value="month">
          <input class="input" type="month" name="month" value="<?= e($onMonth) ?>"
                 max="<?= e(substr(server_today(), 0, 7)) ?>" onchange="this.form.submit()">
          <button type="submit" class="btn btn--sm">Show</button>
        </form>
      <?php else: ?>
        <span class="section-note">Lifetime figures for this Employee.</span>
      <?php endif; ?>

      <a class="btn btn--sm ov-export" href="<?= e($exportPdfUrl) ?>">
        <i class="bi bi-file-earmark-pdf"></i> Export PDF
      </a>
    </div>
  </div>

  <div class="overview-grid">

    <!-- ===== left: statement + notes ===== -->
    <div class="ov-left">
      <section class="card">
        <div class="card__head">
          <h2><?= e($periodLabel) ?> statement</h2>
        </div>

        <?php if ($ovMode === 'day'): ?>
          <?php if (!$statement['has_attendance']): ?>
            <div class="day-absent day-absent--sm">
              <i class="bi bi-x-circle"></i>
              <div>
                <strong>No attendance</strong>
                <p class="section-note">This Employee did not check in on <?= e((new DateTimeImmutable($onDate))->format('j F Y')) ?>.</p>
              </div>
            </div>
          <?php else:
            // The day's audit figures - productive km, shop time, road time -
            // are computed once, at check-out (checkout.php -> compute_day()).
            // Until the Employee checks out they are 0, which reads as "did
            // nothing" instead of "not finished" - show a dash until then,
            // matching how "Full day time" already shows "still open".
            $dayClosed = $statement['last_check_out'] !== null;
          ?>
            <ul class="stmt">
              <li>
                <span class="stmt__ico stmt__ico--peach"><i class="bi bi-box-arrow-in-right"></i></span>
                <span class="stmt__label">Attendance time <span class="c-muted">(check-in)</span></span>
                <span class="stmt__value">
                  <?= $statement['first_check_in'] ? e($statement['first_check_in']->format('g:i A')) : dash() ?>
                </span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--green"><i class="bi bi-clipboard-check"></i></span>
                <span class="stmt__label">Total Visits</span>
                <span class="stmt__value"><?= (int) $statement['visits'] ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--blue"><i class="bi bi-geo"></i></span>
                <span class="stmt__label">Productive KM travelled <span class="c-muted">(by road)</span></span>
                <span class="stmt__value"><?= $dayClosed ? e(fmt_km($statement['km'])) : dash() ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--green"><i class="bi bi-check2-circle"></i></span>
                <span class="stmt__label">Productive working time <span class="c-muted">(at shops)</span></span>
                <span class="stmt__value"><?= $dayClosed ? e(hm($statement['shop_secs'])) : dash() ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--purple"><i class="bi bi-clock"></i></span>
                <span class="stmt__label">Full day time <span class="c-muted">(check-in to check-out)</span></span>
                <span class="stmt__value">
                  <?= $statement['active_secs'] !== null ? e(hm($statement['active_secs'])) : dash() ?>
                  <?php if ($statement['last_check_out'] === null && $statement['first_check_in'] !== null): ?>
                    <span class="stmt__sub">still open</span>
                  <?php endif; ?>
                </span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--red"><i class="bi bi-box-arrow-right"></i></span>
                <span class="stmt__label">Last check-out</span>
                <span class="stmt__value">
                  <?= $statement['last_check_out'] ? e($statement['last_check_out']->format('g:i A')) : dash() ?>
                </span>
              </li>
              <?php if ($statement['odo_check_in'] !== null): ?>
                <li>
                  <span class="stmt__ico stmt__ico--peach"><i class="bi bi-speedometer2"></i></span>
                  <span class="stmt__label">
                    Bike odometer <span class="c-muted">(reading only)</span>
                    <span class="stmt__sub">
                      Check-in <?= e(number_format($statement['odo_check_in'], 1)) ?> km
                      <?php if ($statement['odo_check_out'] !== null): ?>
                        &middot; check-out <?= e(number_format($statement['odo_check_out'], 1)) ?> km
                      <?php else: ?>
                        &middot; check-out not recorded yet
                      <?php endif; ?>
                    </span>
                  </span>
                  <span class="stmt__value">
                    <?= $statement['odo_check_out'] !== null
                        ? e(number_format($statement['odo_check_out'], 1)) . ' km'
                        : e(number_format($statement['odo_check_in'], 1)) . ' km' ?>
                  </span>
                </li>
              <?php endif; ?>
            </ul>
            <p class="section-note stmt__foot">
              <?php if ($dayClosed): ?>
                Shop time <?= e(hm($statement['shop_secs'])) ?> &middot;
                Road time <?= e(hm($statement['road_secs'])) ?>
              <?php else: ?>
                Shop time and road time are worked out at check-out
              <?php endif; ?>
            </p>
          <?php endif; ?>

        <?php else: /* month or alltime: period totals */ ?>
          <?php if ($periodTotals['days'] === 0): ?>
            <div class="day-absent day-absent--sm">
              <i class="bi bi-x-circle"></i>
              <div>
                <strong>No activity</strong>
                <p class="section-note">
                  <?= $ovMode === 'month'
                      ? 'No working days recorded in ' . e($periodLabel) . '.'
                      : 'This Employee has no recorded activity yet.' ?>
                </p>
              </div>
            </div>
          <?php else: ?>
            <ul class="stmt">
              <li>
                <span class="stmt__ico stmt__ico--green"><i class="bi bi-clipboard-check"></i></span>
                <span class="stmt__label">Visits</span>
                <span class="stmt__value"><?= (int) $periodTotals['visits'] ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--blue"><i class="bi bi-shop"></i></span>
                <span class="stmt__label">Shops visited</span>
                <span class="stmt__value"><?= (int) $periodTotals['shops'] ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--blue"><i class="bi bi-geo"></i></span>
                <span class="stmt__label">Productive KM <span class="c-muted">(by road)</span></span>
                <span class="stmt__value"><?= e(number_format($periodTotals['productive_km'], 1)) ?> km</span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--green"><i class="bi bi-check2-circle"></i></span>
                <span class="stmt__label">Productive time <span class="c-muted">(at shops)</span></span>
                <span class="stmt__value"><?= e(hm($periodTotals['shop_secs'], '0m')) ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--peach"><i class="bi bi-calendar-check"></i></span>
                <span class="stmt__label">Attendance <span class="c-muted">(days worked)</span></span>
                <span class="stmt__value">
                  <?= (int) $periodTotals['days'] ?>
                  <?php if ($periodTotals['incomplete'] > 0): ?>
                    <span class="stmt__sub"><?= (int) $periodTotals['incomplete'] ?> incomplete</span>
                  <?php endif; ?>
                </span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--purple"><i class="bi bi-signpost-2"></i></span>
                <span class="stmt__label">Travel time <span class="c-muted">(between shops)</span></span>
                <span class="stmt__value"><?= e(hm($periodTotals['road_secs'], '0m')) ?></span>
              </li>
              <li>
                <span class="stmt__ico stmt__ico--peach"><i class="bi bi-clock-history"></i></span>
                <span class="stmt__label">Avg. visit duration</span>
                <span class="stmt__value"><?= e(hm($periodTotals['avg_dwell_secs'], '-')) ?></span>
              </li>
              <li class="stmt__total">
                <span class="stmt__ico stmt__ico--red"><i class="bi bi-clock"></i></span>
                <span class="stmt__label">Total time <span class="c-muted">(check-in to check-out)</span></span>
                <span class="stmt__value">
                  <?= e(hm($periodTotals['total_secs'], '0m')) ?>
                  <?php if ($periodTotals['incomplete'] > 0): ?>
                    <span class="stmt__sub">closed days only</span>
                  <?php endif; ?>
                </span>
              </li>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <!-- notes -->
      <section class="card">
        <div class="card__head">
          <h2>Notes</h2>
          <?php if (!empty($me['is_super_admin'])): ?>
            <button type="button" class="btn btn--sm js-notes-edit"><i class="bi bi-pencil"></i> Edit</button>
          <?php endif; ?>
        </div>
        <div class="notes-view <?= $employee['notes'] ? '' : 'is-empty' ?>">
          <?= $employee['notes'] ? nl2br(e($employee['notes'])) : 'No notes yet.' ?>
        </div>
        <?php if (!empty($me['is_super_admin'])): ?>
          <form class="notes-form" method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/save-notes.php" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <textarea class="input" name="notes" rows="4" maxlength="2000"
                      placeholder="Performance, communication, reliability..."><?= e($employee['notes'] ?? '') ?></textarea>
            <div class="notes-form__actions">
              <button type="button" class="btn btn--sm js-notes-cancel">Cancel</button>
              <button type="submit" class="btn btn--sm btn--primary">Save notes</button>
            </div>
          </form>
        <?php endif; ?>
      </section>
    </div>

    <!-- ===== right: timeline (day) / per-day list (month) / note (alltime) ===== -->
    <section class="card">
      <?php if ($ovMode === 'day'): ?>
        <?php
          // ordered GPS points for the "View route on map" panel: check-in ->
          // shop 1 -> shop 2 -> ... -> check-out. On an open day there is no
          // check-out row, so the panel shows the route SO FAR and says so.
          $ovMapPoints = [];
          $ovShopNo = 0;
          foreach ($timeline as $tr) {
              if ($tr['lat'] === null || $tr['lng'] === null) continue;
              if ($tr['kind'] === 'visit') $ovShopNo++;
              $ovMapPoints[] = [
                  'kind'  => $tr['kind'],
                  'no'    => $tr['kind'] === 'visit' ? $ovShopNo : null,
                  'label' => $tr['label'],
                  'at'    => $tr['at']->format('Y-m-d H:i:s'),
                  'lat'   => (float) $tr['lat'],
                  'lng'   => (float) $tr['lng'],
              ];
          }
        ?>
        <div class="card__head">
          <h2><?= $isToday ? "Today's" : "Day's" ?> Attendance &amp; Location Timeline</h2>
        </div>
        <?php if (!$timeline): ?>
          <p class="section-note">No check-in recorded for this day, so there is no timeline.</p>
        <?php else: ?>
          <?php if (count($ovMapPoints) >= 2):
            $mapPoints     = $ovMapPoints;
            $mapPanelId    = 'ov-map';
            $mapPersistKey = 'trk.daymap.' . (int) $id . '.' . $onDate;
            $doneShops     = count(array_filter($ovMapPoints, static fn($p) => $p['kind'] === 'visit'));
            $hasOut        = (bool) array_filter($ovMapPoints, static fn($p) => $p['kind'] === 'checkout');
            $dot           = " \xc2\xb7 ";
            $mapFoot       = count($ovMapPoints) . ' point' . (count($ovMapPoints) === 1 ? '' : 's')
                           . $dot . $doneShops . ' shop' . ($doneShops === 1 ? '' : 's') . ' visited'
                           . ($hasOut ? '' : $dot . 'not checked out yet');
            require __DIR__ . '/_route_map.php';
          endif; ?>
          <div class="table-wrap">
            <table class="table timeline-table">
              <thead>
                <tr>
                  <th>Point</th>
                  <th>Time</th>
                  <th>Location &amp; GPS</th>
                  <th>Time at shop &middot; Drive from previous stop &middot; Photo</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($timeline as $row):
                  [$typeLabel, $tone] = $kindMeta[$row['kind']];
                  $ll  = latlng($row['lat'], $row['lng']);
                  $isVisit = $row['kind'] === 'visit';
                ?>
                <tr>
                  <td>
                    <span class="tl-status tl-status--<?= e($tone) ?>"><?= e($typeLabel) ?></span>
                    <?php if ($isVisit): ?><span class="tl-ord"><?= e($row['remark']) ?></span><?php endif; ?>
                  </td>
                  <td class="tl-time">
                    <?= e($row['at']->format('g:i A')) ?>
                    <?php if ($isVisit && $row['left_at']): ?>
                      <span class="tl-sub">left <?= e($row['left_at']->format('g:i A')) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="stack">
                      <strong><?= e($row['label']) ?></strong>
                      <?php if ($ll !== ''): ?>
                        <span class="pt-coord">
                          <?= e($ll) ?>
                          <span class="c-muted">&middot; acc <?= $row['accuracy'] !== null ? e(number_format($row['accuracy'], 0)) . ' m' : '-' ?></span>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($isVisit):
                      $stillThere = $row['dwell_secs'] === null;
                      $hopKnown   = $row['hop_secs'] !== null;
                    ?>
                      <div class="tl-shop">
                        <span class="tl-chip tl-chip--dwell<?= $stillThere ? ' is-live' : '' ?>" title="Time spent at this shop">
                          <i class="bi bi-hourglass-split"></i>
                          <?= $stillThere ? 'still here' : e(hm($row['dwell_secs'])) . ' at shop' ?>
                        </span>
                        <span class="tl-chip tl-chip--travel" title="Road distance from the previous stop, and the driving time to get here">
                          <i class="bi bi-signpost-2"></i>
                          <?php if ($hopKnown): ?>
                            <?= e(fmt_km((float) $row['hop_km'])) ?> drive
                            <?php if ((int) $row['hop_secs'] > 0): ?>
                              <span class="tl-chip__sep">&middot;</span> <?= e(hm($row['hop_secs'])) ?> driving time
                            <?php endif; ?>
                          <?php else: ?>
                            distance pending
                          <?php endif; ?>
                        </span>
                        <?php if ($row['photo_path']): ?>
                          <a class="tl-chip tl-chip--photo" href="<?= e(UPLOAD_URL . '/' . $row['photo_path']) ?>" target="_blank" rel="noopener" title="Open the shop photo">
                            <i class="bi bi-camera-fill"></i>
                            photo<?= $row['photo_count'] > 1 ? '&nbsp;&times;' . (int) $row['photo_count'] : '' ?>
                          </a>
                        <?php endif; ?>
                      </div>
                      <?php if ($row['note']): ?>
                        <div class="tl-note"><i class="bi bi-chat-left-quote"></i> <span><?= e($row['note']) ?></span></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="tl-plain"><?= e($row['remark']) ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      <?php elseif ($ovMode === 'month'): ?>
        <?php
          $attendedCount = count(array_filter($monthDays, static fn($r) => $r['has_attendance']));
          // group calendar rows by ISO week for a scannable long-month list
          $weeks = [];
          foreach ($monthDays as $row) {
              $w = (new DateTimeImmutable($row['date']))->format('o-\WW');
              $weeks[$w][] = $row;
          }
        ?>
        <div class="card__head">
          <h2>Days in <?= e($periodLabel) ?></h2>
          <span class="section-note"><?= $attendedCount ?> attended of <?= count($monthDays) ?> days</span>
        </div>
        <?php if (!$monthDays): ?>
          <p class="section-note">Nothing to show for <?= e($periodLabel) ?>.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table days-table month-days">
              <thead>
                <tr>
                  <th>Date</th><th>Check-in</th><th>Check-out</th>
                  <th>Total time</th><th>Shop time</th><th>Travel time</th><th>KM</th><th>Visits</th><th class="ta-right"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($weeks as $wk => $wkRows):
                  $first = new DateTimeImmutable($wkRows[array_key_last($wkRows)]['date']);
                  $last  = new DateTimeImmutable($wkRows[0]['date']);
                ?>
                <tr class="week-head">
                  <td colspan="9">
                    Week of <?= e($first->format('j M')) ?> to <?= e($last->format('j M')) ?>
                  </td>
                </tr>
                <?php foreach ($wkRows as $row):
                  $dObj   = new DateTimeImmutable($row['date']);
                  $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . $id . '&date=' . $row['date'];
                ?>
                <?php if (!$row['has_attendance']): ?>
                  <tr class="day-row day-row--absent">
                    <td><span class="c-muted"><?= e($dObj->format('D, j M')) ?></span></td>
                    <td class="c-muted att-absent-note" colspan="7">Not attended</td>
                    <td></td>
                  </tr>
                <?php else:
                  $d  = $row;
                  $ci = new DateTimeImmutable($d['check_in_at']);
                  $co = $d['check_out_at'] ? new DateTimeImmutable($d['check_out_at']) : null;
                ?>
                  <tr class="day-row <?= $d['status'] === 'incomplete' ? 'is-incomplete' : '' ?>">
                    <td>
                      <strong><?= e($dObj->format('D, j M')) ?></strong>
                      <?php if ($d['status'] === 'incomplete'): ?>
                        <span class="badge badge--rejected">no check-out</span>
                      <?php elseif ($d['status'] === 'open'): ?>
                        <span class="badge badge--open">open</span>
                      <?php endif; ?>
                    </td>
                    <td><?= e($ci->format('g:i A')) ?></td>
                    <td><?= $co ? e($co->format('g:i A')) : dash() ?></td>
                    <td><?= $d['total_seconds'] !== null ? e(hm((int) $d['total_seconds'])) : dash() ?></td>
                    <td class="c-muted"><?= e(hm((int) $d['shop_seconds'], '-')) ?></td>
                    <td class="c-muted"><?= e(hm((int) $d['road_seconds'], '-')) ?></td>
                    <td><?= e(number_format((float) $d['road_km'], 1)) ?></td>
                    <td><?= (int) $d['visit_count'] ?></td>
                    <td class="ta-right"><a class="row-btn row-btn--view" href="<?= e($dayUrl) ?>"><i class="bi bi-eye"></i> View</a></td>
                  </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      <?php else: /* alltime */ ?>
        <div class="card__head"><h2>All-time activity</h2></div>
        <div class="day-absent day-absent--sm">
          <i class="bi bi-clock-history"></i>
          <div>
            <strong>The lifetime summary is on the left.</strong>
            <p class="section-note">
              To see a full day-by-day or month-by-month record, open the
              <a href="<?= e(tab_url($id, 'attendance')) ?>"><strong>Attendance</strong> tab</a>.
            </p>
            <p class="section-note" lang="ne">
              दिनैपिच्छे वा महिनैपिच्छेको पूरा हाजिरी हेर्न
              <a href="<?= e(tab_url($id, 'attendance')) ?>"><strong>हाजिरी (Attendance)</strong> ट्याब</a> खोल्नुहोस्।
            </p>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
